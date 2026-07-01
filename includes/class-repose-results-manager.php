<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class HN_Repose_Results_Manager {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // AJAX file serving — works in both admin and frontend
        add_action( 'wp_ajax_repose_view_result',        array( __CLASS__, 'ajax_serve_file' ) );
        add_action( 'wp_ajax_nopriv_repose_view_result', array( __CLASS__, 'ajax_serve_file' ) );
        add_action( 'repose_send_review_email', array( __CLASS__, 'send_review_email' ) );

        // Register WooCommerce rewrite endpoint (must be on 'init', before headers sent)
        add_action( 'init', array( __CLASS__, 'register_account_endpoint' ) );

        // Customer account dashboard
        add_filter( 'woocommerce_account_menu_items',            array( __CLASS__, 'add_account_menu' ) );
        add_action( 'woocommerce_account_test-results_endpoint', array( __CLASS__, 'render_customer_results' ) );
        add_filter( 'woocommerce_get_query_vars',                array( __CLASS__, 'add_query_var' ) );
    }

    /**
     * Register the rewrite endpoint so WooCommerce account page resolves /my-account/test-results/
     * correctly instead of returning a 404.
     */
    public static function register_account_endpoint() {
        add_rewrite_endpoint( 'test-results', EP_ROOT | EP_PAGES );
    }

    // -----------------------------------------------------------------------
    // Upload result (admin / staff)
    // -----------------------------------------------------------------------

    public static function upload_result( int $order_id, int $uploader_id, array $file, string $test_type ) {
        if ( $file['type'] !== 'application/pdf' ) {
            return new WP_Error( 'invalid_type', 'Only PDF files are accepted.' );
        }

        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        wp_mkdir_p( $dest_dir );

        // Protect directory from direct web access
        if ( ! file_exists( $dest_dir . '.htaccess' ) ) {
            file_put_contents( $dest_dir . '.htaccess', "deny from all\n" );
        }

        $filename = sanitize_file_name( sprintf( 'result-%d-%s.pdf', $order_id, time() ) );
        $dest     = $dest_dir . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'upload_failed', 'File could not be saved. Check folder permissions.' );
        }

        global $wpdb;
        $order      = wc_get_order( $order_id );
        $ref_number = $order ? $order->get_meta( '_repose_reference_number' ) : '';

        $wpdb->insert( $wpdb->prefix . 'repose_results', array(
            'order_id'      => $order_id,
            'reference_num' => $ref_number,
            'test_type'     => sanitize_text_field( $test_type ),
            'file_path'     => $filename,
            'status'        => 'pending_review',
            'uploaded_by'   => $uploader_id,
            'uploaded_at'   => current_time( 'mysql' ),
        ) );

        $result_id = (int) $wpdb->insert_id;

        Repose_Audit_Log::record( $order_id, $uploader_id, 'result_uploaded',
            "Result ID {$result_id}, Test: {$test_type}, File: {$filename}" );

        return $result_id;
    }

    // -----------------------------------------------------------------------
    // Approve result → notify patient
    // -----------------------------------------------------------------------

    public static function approve_result( int $result_id, int $approver_id, bool $schedule_review = false ): bool {
        global $wpdb;
        $table  = $wpdb->prefix . 'repose_results';
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $result_id ) );

        if ( ! $result ) return false;

        $wpdb->update( $table, array(
            'status'      => 'reported',
            'approved_by' => $approver_id,
            'approved_at' => current_time( 'mysql' ),
        ), array( 'id' => $result_id ) );

        Repose_Audit_Log::record( (int) $result->order_id, $approver_id, 'result_approved',
            'Result ID ' . $result_id . ' approved by ' . ( get_user_by( 'id', $approver_id )->display_name ?? 'staff' ) );

        Repose_Notifications::notify_result_ready( (int) $result->order_id, $result_id );

        if ( $schedule_review ) {
            $review_time = time() + DAY_IN_SECONDS;
            wp_schedule_single_event( $review_time, 'repose_send_review_email', array( $result_id ) );
            $wpdb->update( $table, array( 'review_email_at' => gmdate( 'Y-m-d H:i:s', $review_time ) ), array( 'id' => $result_id ) );
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------------

    public static function add_note( int $result_id, int $author_id, string $note, string $visibility = 'internal' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_result_notes', array(
            'result_id'  => $result_id,
            'author_id'  => $author_id,
            'note'       => wp_kses_post( $note ),
            'visibility' => in_array( $visibility, array( 'patient', 'internal' ), true ) ? $visibility : 'internal',
            'created_at' => current_time( 'mysql' ),
        ) );
        return (int) $wpdb->insert_id;
    }
    public static function delete_note( int $note_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'repose_result_notes', array( 'id' => $note_id ) );
    }

    public static function get_notes( int $result_id, bool $include_internal = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'repose_result_notes';
        $where = $include_internal ? '' : "AND visibility = 'patient'";
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE result_id = %d {$where} ORDER BY created_at ASC",
            $result_id
        ) );
    }

    // -----------------------------------------------------------------------
    // Secure file delivery
    //
    // Patient-facing links use a signed HMAC token so they work for
    // logged-out users and don't expire when the session changes.
    // Admin links (results queue) continue to use nonces.
    // -----------------------------------------------------------------------

    /**
     * Generate a permanent HMAC token for a specific result.
     * Token = HMAC-SHA256( wp_salt('auth'), "result:{id}:{order_id}" )
     */
    public static function generate_result_token( int $result_id, int $order_id ): string {
        $secret  = (string) get_option( 'repose_api_token', wp_salt( 'auth' ) );
        $payload = "result:{$result_id}:{$order_id}";
        return hash_hmac( 'sha256', $payload, $secret );
    }

    public static function verify_result_token( int $result_id, int $order_id, string $token ): bool {
        return hash_equals( self::generate_result_token( $result_id, $order_id ), $token );
    }

    /**
     * Build a signed URL suitable for emailing to patients.
     * Uses a token (not a nonce) so it works without a logged-in session.
     */
    public static function get_result_download_url( int $result_id ): string {
        global $wpdb;
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
        ) );
        return add_query_arg( array(
            'action'    => 'repose_view_result',
            'result_id' => $result_id,
            'token'     => self::generate_result_token( $result_id, $order_id ),
        ), admin_url( 'admin-ajax.php' ) );
    }

    public static function maybe_serve_download() {
        if ( empty( $_GET['repose_download'] ) ) return;

        $result_id = (int) $_GET['repose_download'];
        $nonce     = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'repose_download_' . $result_id ) ) {
            wp_die( 'Invalid or expired request. Please try again.' );
        }

        $is_admin = current_user_can( 'manage_woocommerce' );

        global $wpdb;

        // Admins can preview any result (pending_review or reported)
        // Patients can only download reported results
        if ( $is_admin ) {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d",
                $result_id
            ) );
        } else {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d AND status = 'reported'",
                $result_id
            ) );
        }

        if ( ! $result ) {
            if ( $is_admin ) {
                wp_die( 'Result record not found (ID: ' . $result_id . '). It may have been deleted.' );
            }
            wp_die( 'Result not yet available. It will appear here once approved by our clinical team.' );
        }

        // Non-admins: verify they own this order
        if ( ! $is_admin ) {
            $order = wc_get_order( $result->order_id );
            if ( ! $order || get_current_user_id() !== (int) $order->get_customer_id() ) {
                wp_die( 'Access denied.' );
            }
        }

        // Resolve file path
        $upload_dir = wp_upload_dir();
        $file_path  = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/' . $result->file_path;

        if ( ! file_exists( $file_path ) ) {
            // Try companion HTML file if PDF not found (JSON import fallback)
            $html_path = preg_replace( '/\.pdf$/i', '.html', $file_path );
            if ( file_exists( $html_path ) ) {
                $file_path = $html_path;
            } else {
                wp_die( '<p><strong>File not found.</strong></p><p>Expected: <code>repose-results/' . esc_html( $result->file_path ) . '</code></p><p>Please re-upload the result or contact support.</p>' );
            }
        }

        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $mime_type = ( $ext === 'html' ) ? 'text/html' : 'application/pdf';
        $disp      = ( $ext === 'html' ) ? 'inline' : 'inline';

        // Stream the file — clean all output buffers first
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: ' . $mime_type . '; charset=UTF-8' );
        header( 'Content-Disposition: ' . $disp . '; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        readfile( $file_path );
        exit;
    }

    // -----------------------------------------------------------------------
    // REST API for automated lab import
    // -----------------------------------------------------------------------

    public static function register_rest_routes() {
        register_rest_route( 'repose/v1', '/results/import', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_import_result' ),
            'permission_callback' => array( __CLASS__, 'rest_auth' ),
        ) );
    }

    public static function rest_auth( WP_REST_Request $request ): bool {
        $token = $request->get_header( 'X-Repose-Token' );
        return hash_equals( (string) get_option( 'repose_api_token', '' ), (string) $token );
    }

    public static function rest_import_result( WP_REST_Request $request ) {
        // ── HL7 key-based flow ────────────────────────────────────────────────
        // When the payload contains a "key" (or nested data.key) the caller wants
        // us to fetch the HL7 result from the lab API and store it in all HL7
        // tables.  Delegate entirely to Repose_JSON_Import::handle_import().
        $body = $request->get_json_params();
        $key  = $body['data']['key'] ?? $body['key'] ?? '';
        if ( $key && class_exists( 'Repose_JSON_Import' ) ) {
            return Repose_JSON_Import::handle_import( $request );
        }

        // ── Legacy PDF-upload flow ────────────────────────────────────────────
        $ref_number = sanitize_text_field( $request->get_param( 'reference_number' ) );
        $test_type  = sanitize_text_field( $request->get_param( 'test_type' ) );
        $pdf_base64 = $request->get_param( 'pdf_base64' );

        $orders = wc_get_orders( array(
            'meta_key'   => '_repose_reference_number',
            'meta_value' => $ref_number,
            'limit'      => 1,
        ) );

        if ( empty( $orders ) ) {
            return new WP_REST_Response( array( 'error' => 'Order not found for reference ' . $ref_number ), 404 );
        }

        $order    = $orders[0];
        $order_id = $order->get_id();

        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        wp_mkdir_p( $dest_dir );

        $filename = sanitize_file_name( "result-{$order_id}-" . time() . '.pdf' );
        file_put_contents( $dest_dir . $filename, base64_decode( (string) ( $pdf_base64 ?? '' ) ) );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_results', array(
            'order_id'      => $order_id,
            'reference_num' => $ref_number,
            'test_type'     => $test_type,
            'file_path'     => $filename,
            'status'        => 'pending_review',
            'uploaded_by'   => 0,
            'uploaded_at'   => current_time( 'mysql' ),
        ) );

        $result_id = (int) $wpdb->insert_id;

        // Generate branded PDF
        if ( class_exists( 'Repose_PDF_Brander' ) ) {
            $branded = Repose_PDF_Brander::brand( $result_id, $dest_dir . $filename );
            if ( ! is_wp_error( $branded ) && $branded !== $dest_dir . $filename ) {
                $wpdb->update(
                    $wpdb->prefix . 'repose_results',
                    array( 'file_path' => basename( $branded ) ),
                    array( 'id' => $result_id )
                );
            }
        }

        Repose_Audit_Log::record( $order_id, 0, 'api_result_imported', "Ref: {$ref_number}, Result ID: {$result_id}" );

        return new WP_REST_Response( array( 'success' => true, 'result_id' => $result_id ), 201 );
    }

    // -----------------------------------------------------------------------
    // Scheduled review email
    // -----------------------------------------------------------------------

    public static function send_review_email( int $result_id ) {
        global $wpdb;
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
        ) );
        if ( ! $result ) return;

        $order = wc_get_order( $result->order_id );
        if ( ! $order ) return;

        $to           = $order->get_billing_email();
        $name         = $order->get_billing_first_name();
        $company_name = get_option( 'repose_company_name', 'Repose Healthcare' );
        $brand_color  = get_option( 'repose_brand_color', '#1a6e8c' );
        $logo_url     = get_option( 'repose_logo_url', '' );
        $review_url   = get_option( 'repose_review_url', 'https://g.page/r/CbeI7sxByQWwEAE/review' );

        $subject = get_option( 'repose_review_email_subject',
            'How was your experience with Repose Healthcare?' );

        $raw_body = get_option( 'repose_review_email_body',
            "Dear {name},\n\nI hope this email finds you well. Thank you once again for choosing Repose Healthcare — we truly appreciate your support.\n\nWe're always looking to improve and ensure we're delivering the best possible experience. If you have a moment, we would be grateful if you could leave us a quick review and share your thoughts about your experience with us.\n\nYour feedback not only helps us grow but also helps others make confident decisions.\n\nThank you again for your time and support. If there's anything we can do better, please don't hesitate to let us know.\n\nWarm regards,\nRepose Healthcare" );

        $raw_body = str_replace(
            array( '{name}', '{site_name}', '{review_url}' ),
            array( esc_html( $name ), esc_html( $company_name ), esc_url( $review_url ) ),
            $raw_body
        );

        $logo_html = $logo_url
            ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $company_name ) . '" style="max-height:50px;max-width:180px;">'
            : '<span style="font-size:20px;font-weight:700;color:#fff;">' . esc_html( $company_name ) . '</span>';

        $body_paragraphs = '';
        foreach ( explode( "\n", $raw_body ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $body_paragraphs .= '<p style="margin:0 0 14px;color:#374151;font-size:15px;line-height:1.6;">' . esc_html( $line ) . '</p>';
            }
        }

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
      <tr>
        <td style="background:' . esc_attr( $brand_color ) . ';padding:28px 32px;text-align:center;">
          ' . $logo_html . '
        </td>
      </tr>
      <tr>
        <td style="padding:36px 32px 24px;">
          ' . $body_paragraphs . '
          <div style="text-align:center;margin:28px 0 8px;">
            <a href="' . esc_url( $review_url ) . '"
               style="display:inline-block;padding:14px 32px;background:' . esc_attr( $brand_color ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:700;letter-spacing:0.3px;">
              &#11088; Leave a Review
            </a>
          </div>
        </td>
      </tr>
      <tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:0;"></td></tr>
      <tr>
        <td style="padding:20px 32px 28px;text-align:center;">
          <p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#374151;">Repose Healthcare</p>
          <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">1 Skylark Way, Sawston, Cambridgeshire, CB22 3GL, United Kingdom</p>
          <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">T: 0330 133 4245 &nbsp;|&nbsp; M: 07307 534441</p>
          <p style="margin:0 0 8px;font-size:12px;">
            <a href="https://reposehealthcare.co.uk" style="color:' . esc_attr( $brand_color ) . ';">reposehealthcare.co.uk</a>
            &nbsp;|&nbsp;
            <a href="https://mrsatest.co.uk" style="color:' . esc_attr( $brand_color ) . ';">mrsatest.co.uk</a>
          </p>
          <p style="margin:0;font-size:11px;color:#d1d5db;">Registered in England &amp; Wales No: 16179671</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

        add_filter( 'wp_mail_content_type', array( 'Repose_Notifications', 'set_html_content_type' ) );
        wp_mail( $to, $subject, $html );
        remove_filter( 'wp_mail_content_type', array( 'Repose_Notifications', 'set_html_content_type' ) );
    }

    // -----------------------------------------------------------------------
    // Customer account dashboard
    // -----------------------------------------------------------------------

    public static function add_account_menu( array $items ): array {
        $items['test-results'] = __( 'My Test Results', 'repose-healthcare' );
        return $items;
    }

    public static function add_query_var( array $vars ): array {
        $vars['test-results'] = 'test-results';
        return $vars;
    }

    public static function render_customer_results() {
        global $wpdb;
        $user_id = get_current_user_id();
        $orders  = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => -1 ) );

        $results = array();
        foreach ( $orders as $order ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE order_id = %d AND status = 'reported'",
                $order->get_id()
            ) );
            foreach ( $rows as $row ) {
                $results[] = $row;
            }
        }

        include REPOSE_PLUGIN_DIR . 'templates/customer-results.php';
    }

    // -----------------------------------------------------------------------
    // AJAX file serving (works in admin context, avoids template_redirect issue)
    // -----------------------------------------------------------------------

    public static function ajax_serve_file() {
        $result_id = (int) ( $_GET['result_id'] ?? 0 );
        $token     = sanitize_text_field( $_GET['token'] ?? '' );
        $nonce     = sanitize_text_field( $_GET['nonce'] ?? '' );

        // ── Auth: accept either a signed token (patient email link) or a nonce (admin) ──
        $is_admin       = current_user_can( 'manage_woocommerce' );
        $nonce_valid    = $nonce && wp_verify_nonce( $nonce, 'repose_download_' . $result_id );
        $token_valid    = false;

        global $wpdb;

        // To validate the token we need the order_id — look it up first (any status)
        $order_id_for_token = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
        ) );

        if ( $token && $order_id_for_token ) {
            $token_valid = self::verify_result_token( $result_id, $order_id_for_token, $token );
        }

        if ( ! $nonce_valid && ! $token_valid && ! $is_admin ) {
            wp_die( 'This link is invalid or has expired. Please check your email for the latest results link.' );
        }

        // ── Fetch result row ──────────────────────────────────────────────────
        // Admins and valid nonces can see pending_review results (preview).
        // Token holders (patients) only see approved/reported results.
        if ( $is_admin || $nonce_valid ) {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
            ) );
        } else {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d AND status = 'reported'", $result_id
            ) );
        }

        if ( ! $result ) {
            wp_die( ( $is_admin || $nonce_valid )
                ? 'Result #' . $result_id . ' not found in database.'
                : 'Your result is not yet available. It will be released once approved by our clinical team.'
            );
        }

        $upload_dir = wp_upload_dir();
        $file_path  = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/' . $result->file_path;

        // Try HTML fallback if PDF not found
        if ( ! file_exists( $file_path ) ) {
            $html_path = preg_replace( '/\.pdf$/i', '.html', $file_path );
            if ( file_exists( $html_path ) ) {
                $file_path = $html_path;
            } elseif ( class_exists( 'Repose_JSON_Import' ) ) {
                // Generate report dynamically from HL7 database tables
                $html = Repose_JSON_Import::build_report_from_db( $result_id );
                if ( $html ) {
                    while ( ob_get_level() > 0 ) { ob_end_clean(); }
                    header( 'Content-Type: text/html; charset=UTF-8' );
                    header( 'Cache-Control: no-store' );
                    echo $html;
                    exit;
                }
                wp_die( '<p><strong>File not found and no HL7 data in database for result #' . $result_id . '.</strong></p><p>Please re-upload the result.</p>' );
            } else {
                wp_die( '<p><strong>File not found on server.</strong></p>'
                      . '<p>Expected: <code>' . esc_html( 'repose-results/' . $result->file_path ) . '</code></p>'
                      . '<p>The file may have been deleted or failed to generate. Please re-upload.</p>' );
            }
        }

        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $mime_type = ( $ext === 'html' ) ? 'text/html; charset=UTF-8' : 'application/pdf';

        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Cache-Control: no-store' );
        readfile( $file_path );
        exit;
    }

}