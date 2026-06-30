<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Inbound HL7 v2 Message Handler
 *
 * REST endpoint: POST /wp-json/repose/v1/hl7
 * Content-Type: text/plain  (raw pipe-delimited HL7)
 *   OR application/json     { "hl7": "<raw message>" }
 *
 * Header: X-Repose-Token: <api_token>
 *
 * Routes:
 *   ORR — Sample received at lab → triggers ORR confirmation email
 *   ORU — Results ready → stores observations, triggers result notification
 *
 * Matching order:
 *   1. PatientID (PID-3) → look up repose_patients.patient_uid → get order via patient_tests
 *   2. OrderReference (PV1-19 or ORC-2) → match _repose_reference_number meta (10-char short ref)
 *   3. Full reference number pattern (R + digits)
 */
class Repose_HL7_Inbound {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'repose/v1', '/hl7', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            'permission_callback' => array( __CLASS__, 'authenticate' ),
        ) );
    }

    public static function authenticate( \WP_REST_Request $request ): bool {
        $token    = $request->get_header( 'X-Repose-Token' );
        $expected = get_option( 'repose_api_token', '' );
        return $expected && hash_equals( $expected, (string) $token );
    }

    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // Accept raw HL7 text or JSON wrapper
        $content_type = $request->get_content_type()['value'] ?? '';
        if ( strpos( $content_type, 'application/json' ) !== false ) {
            $body = $request->get_json_params();
            $raw  = $body['hl7'] ?? '';
        } else {
            $raw = $request->get_body();
        }

        $raw = trim( (string) $raw );
        if ( empty( $raw ) ) {
            return new \WP_REST_Response( array( 'error' => 'Empty HL7 message body.' ), 400 );
        }

        // Parse the HL7 message
        $parsed = Repose_HL7_Parser::parse( $raw );
        $type   = Repose_HL7_Parser::get_type( $parsed );

        // Store raw message for audit
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_hl7_messages', array(
            'message'    => $raw,
            'created_at' => current_time( 'mysql' ),
        ) );

        switch ( $type ) {
            case 'ORR':
                return self::handle_orr( $parsed, $raw );
            case 'ORU':
                return self::handle_oru( $parsed, $raw );
            default:
                return new \WP_REST_Response( array(
                    'success' => false,
                    'message' => "Unsupported HL7 message type: {$type}. Supported: ORR, ORU.",
                    'type'    => $type,
                ), 200 );
        }
    }

    // ── ORR: Sample received at lab ──────────────────────────────────────
    private static function handle_orr( array $parsed, string $raw ): \WP_REST_Response {
        $order = self::resolve_order( $parsed );

        if ( ! $order ) {
            return new \WP_REST_Response( array(
                'error'            => 'Could not match ORR message to a WooCommerce order.',
                'patient_uid_used' => Repose_HL7_Parser::get_patient_uid( $parsed ),
                'order_ref_used'   => Repose_HL7_Parser::get_order_reference( $parsed ),
            ), 404 );
        }

        $order_id = $order->get_id();

        // Idempotent — don't process twice
        if ( $order->get_meta( '_repose_sample_received' ) === '1' ) {
            return new \WP_REST_Response( array(
                'success'  => true,
                'message'  => "ORR already processed for order #{$order_id}.",
                'order_id' => $order_id,
            ), 200 );
        }

        // Update order meta
        $order->update_meta_data( '_repose_sample_received',    '1' );
        $order->update_meta_data( '_repose_sample_received_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_repose_orr_raw',            $raw );
        $order->save();

        $order->add_order_note( 'Repose: ORR-HL7 received — sample confirmed at TDL laboratory.', 0, false );

        Repose_Audit_Log::record( $order_id, 0, 'orr_received',
            'ORR-HL7 sample receipt confirmation received from TDL. Patient UID: '
            . Repose_HL7_Parser::get_patient_uid( $parsed ) );

        // Send confirmation email to customer
        Repose_Notifications::notify_sample_received( $order_id );

        return new \WP_REST_Response( array(
            'success'  => true,
            'type'     => 'ORR',
            'order_id' => $order_id,
            'message'  => "Sample receipt confirmed for order #{$order_id}. Customer notified.",
        ), 200 );
    }

    // ── ORU: Results ready ───────────────────────────────────────────────
    private static function handle_oru( array $parsed, string $raw ): \WP_REST_Response {
        $order = self::resolve_order( $parsed );

        if ( ! $order ) {
            return new \WP_REST_Response( array(
                'error'            => 'Could not match ORU message to a WooCommerce order.',
                'patient_uid_used' => Repose_HL7_Parser::get_patient_uid( $parsed ),
                'order_ref_used'   => Repose_HL7_Parser::get_order_reference( $parsed ),
            ), 404 );
        }

        $order_id  = $order->get_id();
        $ref       = $order->get_meta( '_repose_reference_number' ) ?: '';
        $patient_uid = Repose_HL7_Parser::get_patient_uid( $parsed );

        // Collect test name from first OBR
        $test_name = 'Lab Result';
        foreach ( $parsed['orders'] as $ord ) {
            foreach ( $ord['obr_list'] ?? array() as $obr ) {
                if ( ! empty( $obr['test_name'] ) ) {
                    $test_name = $obr['test_name'];
                    break 2;
                }
            }
        }

        // Store in wp_repose_results
        global $wpdb;
        $result_id = 0;

        // Save raw HL7 to results directory
        $upload_dir  = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        wp_mkdir_p( $results_dir );
        if ( ! file_exists( $results_dir . '.htaccess' ) ) {
            file_put_contents( $results_dir . '.htaccess', "deny from all\n" );
        }
        $hl7_filename = sanitize_file_name( 'oru-' . $order_id . '-' . time() . '.hl7' );
        file_put_contents( $results_dir . $hl7_filename, $raw );

        $wpdb->insert( $wpdb->prefix . 'repose_results', array(
            'order_id'      => $order_id,
            'reference_num' => $ref,
            'test_type'     => sanitize_text_field( $test_name ),
            'file_path'     => $hl7_filename,
            'status'        => 'pending_review',
            'uploaded_by'   => 0,
            'uploaded_at'   => current_time( 'mysql' ),
        ) );
        $result_id = (int) $wpdb->insert_id;

        // Save structured HL7 observations into existing HL7 tables
        foreach ( $parsed['orders'] as $ord ) {
            foreach ( $ord['obr_list'] ?? array() as $obr ) {
                $hl7_report_id = 0;
                $wpdb->insert( $wpdb->prefix . 'repose_hl7_reports', array(
                    'wc_result_id'         => $result_id,
                    'test_code'            => sanitize_text_field( $obr['test_code']    ?? '' ),
                    'test_name'            => sanitize_text_field( $obr['test_name']    ?? '' ),
                    'result_status'        => sanitize_text_field( $obr['result_status'] ?? '' ),
                    'observation_datetime' => Repose_HL7_Parser::hl7_datetime( $obr['observation_datetime'] ?? '' ),
                    'created_at'           => current_time( 'mysql' ),
                ) );
                $hl7_report_id = (int) $wpdb->insert_id;

                foreach ( $obr['observations'] ?? array() as $obs ) {
                    $wpdb->insert( $wpdb->prefix . 'repose_hl7_observations', array(
                        'hl7_report_id'      => $hl7_report_id,
                        'observation_code'   => sanitize_text_field( $obs['observation_id']   ?? '' ),
                        'observation_name'   => sanitize_text_field( $obs['observation_name'] ?? '' ),
                        'value'              => sanitize_text_field( $obs['value']            ?? '' ),
                        'units'              => sanitize_text_field( $obs['units']            ?? '' ),
                        'reference_range'    => sanitize_text_field( $obs['reference_range']  ?? '' ),
                        'abnormal_flag'      => sanitize_text_field( $obs['abnormal_flags']   ?? '' ),
                        'result_status'      => sanitize_text_field( $obs['result_status']    ?? '' ),
                        'observation_datetime' => Repose_HL7_Parser::hl7_datetime( $obs['observation_datetime'] ?? '' ),
                        'created_at'         => current_time( 'mysql' ),
                    ) );
                }

                foreach ( $obr['notes'] ?? array() as $note ) {
                    $wpdb->insert( $wpdb->prefix . 'repose_hl7_notes', array(
                        'hl7_report_id' => $hl7_report_id,
                        'comment'       => sanitize_textarea_field( $note ),
                        'created_at'    => current_time( 'mysql' ),
                    ) );
                }
            }
        }

        // Match result to specific patient in registry
        $matched_uid = null;
        if ( class_exists( 'Repose_Patient_Registry' ) && $patient_uid ) {
            $patient = Repose_Patient_Registry::get_by_uid( $patient_uid );
            if ( $patient ) {
                // Mark patient's test as complete
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}repose_patient_tests
                     SET status='complete', result_id=%d
                     WHERE patient_id=%d AND order_id=%d AND status='pending'
                     LIMIT 1",
                    $result_id, $patient->id, $order_id
                ) );
                $matched_uid = $patient_uid;
            }
        }

        // If no patient_uid match, fall back to patient 1
        if ( ! $matched_uid ) {
            $matched_uid = $order->get_meta( '_repose_patient_1_uid' );
        }

        $order->update_meta_data( '_repose_last_hl7_result_id', $result_id );
        $order->update_meta_data( '_repose_result_patient_uid', $matched_uid );
        $order->save();

        $order->add_order_note( "Repose: ORU-HL7 received — test result for '{$test_name}'. Result ID {$result_id}. Pending admin review.", 0, false );

        Repose_Audit_Log::record( $order_id, 0, 'json_result_imported',
            "ORU-HL7: Test '{$test_name}', Result #{$result_id}, Patient UID: {$patient_uid}" );

        return new \WP_REST_Response( array(
            'success'             => true,
            'type'                => 'ORU',
            'order_id'            => $order_id,
            'result_id'           => $result_id,
            'test'                => $test_name,
            'matched_patient_uid' => $matched_uid,
            'message'             => "ORU result stored for order #{$order_id}. Pending admin review.",
        ), 201 );
    }

    // ── Resolve WC order from parsed HL7 ─────────────────────────────────
    private static function resolve_order( array $parsed ): ?\WC_Order {
        $patient_uid = Repose_HL7_Parser::get_patient_uid( $parsed );
        $order_ref   = Repose_HL7_Parser::get_order_reference( $parsed );

        // 1. Match by PatientID → find patient in registry → get their most recent order
        if ( $patient_uid && class_exists( 'Repose_Patient_Registry' ) ) {
            $patient = Repose_Patient_Registry::get_by_uid( $patient_uid );
            if ( $patient ) {
                global $wpdb;
                $order_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT order_id FROM {$wpdb->prefix}repose_patient_tests
                     WHERE patient_id = %d ORDER BY assigned_at DESC LIMIT 1",
                    $patient->id
                ) );
                if ( $order_id ) {
                    $o = wc_get_order( $order_id );
                    if ( $o ) return $o;
                }
            }
        }

        // 2. Match by OrderReference (10-char short ref) in order meta
        if ( $order_ref ) {
            global $wpdb;
            // Try exact match on full reference
            $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_repose_reference_number' AND meta_value LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like( $order_ref ) . '%'
            ) );
            if ( $post_id ) {
                $o = wc_get_order( $post_id );
                if ( $o ) return $o;
            }

            // Try extracting numeric order ID from reference
            $extracted = Repose_Reference::extract_order_id( $order_ref );
            if ( $extracted > 0 ) {
                $o = wc_get_order( $extracted );
                if ( $o ) return $o;
            }
        }

        return null;
    }
}
