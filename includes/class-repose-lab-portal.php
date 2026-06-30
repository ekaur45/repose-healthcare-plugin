<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lab Upload Portal
 * Provides a secure browser-based upload page for labs that are not API-connected.
 * Access is controlled by a secret portal token set in Settings.
 */
class Repose_Lab_Portal {

    public static function init() {
        add_action( 'init',                     array( __CLASS__, 'register_endpoint' ) );
        add_filter( 'query_vars',               array( __CLASS__, 'add_query_var' ) );
        add_action( 'template_redirect',        array( __CLASS__, 'handle_portal' ) );
        add_action( 'wp_ajax_nopriv_repose_lab_upload', array( __CLASS__, 'handle_upload' ) );
        add_action( 'wp_ajax_repose_lab_upload',        array( __CLASS__, 'handle_upload' ) );
    }

    public static function register_endpoint() {
        add_rewrite_rule( '^lab-portal/?$', 'index.php?repose_lab_portal=1', 'top' );
    }

    public static function add_query_var( array $vars ): array {
        $vars[] = 'repose_lab_portal';
        return $vars;
    }

    public static function handle_portal() {
        if ( ! get_query_var( 'repose_lab_portal' ) ) return;

        $portal_token = get_option( 'repose_lab_portal_token', '' );

        // Require token in URL: /lab-portal/?token=XXXX
        $submitted_token = sanitize_text_field( $_GET['token'] ?? '' );

        if ( ! $portal_token || ! hash_equals( $portal_token, $submitted_token ) ) {
            status_header( 403 );
            self::render_page( 'access_denied' );
            exit;
        }

        self::render_page( 'upload_form', $submitted_token );
        exit;
    }

    public static function handle_upload() {
        $portal_token    = get_option( 'repose_lab_portal_token', '' );
        $submitted_token = sanitize_text_field( $_POST['portal_token'] ?? '' );

        if ( ! $portal_token || ! hash_equals( $portal_token, $submitted_token ) ) {
            wp_send_json_error( 'Invalid portal token.', 403 );
        }

        $ref_number = sanitize_text_field( $_POST['reference_number'] ?? '' );
        $test_type  = sanitize_text_field( $_POST['test_type'] ?? '' );
        $lab_name   = sanitize_text_field( $_POST['lab_name'] ?? 'Unknown Lab' );

        if ( ! $ref_number ) wp_send_json_error( 'Reference number is required.' );
        if ( empty( $_FILES['lab_result_pdf'] ) ) wp_send_json_error( 'No file uploaded.' );
        if ( $_FILES['lab_result_pdf']['type'] !== 'application/pdf' ) wp_send_json_error( 'Only PDF files accepted.' );

        // Find matching order
        $orders = wc_get_orders( array(
            'meta_key'   => '_repose_reference_number',
            'meta_value' => $ref_number,
            'limit'      => 1,
        ) );

        if ( empty( $orders ) ) {
            wp_send_json_error( 'No order found for reference number: ' . $ref_number );
        }

        $order    = $orders[0];
        $order_id = $order->get_id();

        // Save file
        $upload_dir = wp_upload_dir();
        $dest_dir   = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        wp_mkdir_p( $dest_dir );

        if ( ! file_exists( $dest_dir . '.htaccess' ) ) {
            file_put_contents( $dest_dir . '.htaccess', "deny from all\n" );
        }

        $filename = sanitize_file_name( 'portal-' . $order_id . '-' . time() . '.pdf' );
        $dest     = $dest_dir . $filename;

        if ( ! move_uploaded_file( $_FILES['lab_result_pdf']['tmp_name'], $dest ) ) {
            wp_send_json_error( 'File upload failed. Check server permissions.' );
        }

        // Store result
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_results', array(
            'order_id'      => $order_id,
            'reference_num' => $ref_number,
            'test_type'     => $test_type ?: 'Unknown',
            'file_path'     => $filename,
            'status'        => 'pending_review',
            'uploaded_by'   => 0,
            'uploaded_at'   => current_time( 'mysql' ),
        ) );

        $result_id = (int) $wpdb->insert_id;

        // Attempt branded PDF generation
        if ( class_exists( 'Repose_PDF_Brander' ) ) {
            $branded = Repose_PDF_Brander::brand( $result_id, $dest );
            if ( ! is_wp_error( $branded ) && $branded !== $dest ) {
                $wpdb->update(
                    $wpdb->prefix . 'repose_results',
                    array( 'file_path' => basename( $branded ) ),
                    array( 'id' => $result_id )
                );
            }
        }

        Repose_Audit_Log::record( $order_id, 0, 'portal_result_uploaded',
            "Lab: {$lab_name}, Ref: {$ref_number}, Result ID: {$result_id}" );

        wp_send_json_success( array(
            'message'   => 'Result uploaded successfully. It will be reviewed by our clinical team before being released to the patient.',
            'result_id' => $result_id,
            'order_id'  => $order_id,
        ) );
    }

    private static function render_page( string $view, string $token = '' ) {
        $site_name = get_bloginfo('name');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lab Result Upload Portal — <?php echo esc_html($site_name); ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);width:100%;max-width:560px;overflow:hidden}
  .card-header{background:#1a6e8c;padding:24px 28px;color:#fff}
  .card-header h1{font-size:20px;margin-bottom:4px}
  .card-header p{font-size:13px;opacity:.8}
  .card-body{padding:28px}
  label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:5px}
  input,select{width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:6px;font-size:14px;margin-bottom:16px}
  input[type=file]{padding:8px}
  .btn{width:100%;padding:12px;background:#1a6e8c;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer}
  .btn:hover{background:#155f7a}
  .btn:disabled{background:#aaa;cursor:not-allowed}
  .notice{padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:13px}
  .notice-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
  .notice-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
  .notice-info{background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb}
  .denied{text-align:center;padding:40px 28px}
  .denied h2{color:#c0392b;margin-bottom:12px}
  .denied p{color:#666;font-size:14px}
  .spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-left:8px}
  @keyframes spin{to{transform:rotate(360deg)}}
  .required{color:#c0392b}
  small{display:block;margin-top:-12px;margin-bottom:12px;font-size:12px;color:#888}
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1>Lab Result Upload Portal</h1>
    <p><?php echo esc_html($site_name); ?> — Secure result submission</p>
  </div>
  <div class="card-body">
  <?php if ( $view === 'access_denied' ) : ?>
    <div class="denied">
      <h2>Access Denied</h2>
      <p>Invalid or missing portal token. Please contact <?php echo esc_html($site_name); ?> to obtain your secure upload link.</p>
    </div>
  <?php else : ?>
    <div class="notice notice-info">
      Upload the patient result PDF below. The reference number must match the order reference provided by <?php echo esc_html($site_name); ?>.
    </div>
    <div id="upload-feedback" style="display:none"></div>
    <form id="portal-upload-form" enctype="multipart/form-data">
      <input type="hidden" name="portal_token" value="<?php echo esc_attr($token); ?>">
      <input type="hidden" name="action" value="repose_lab_upload">

      <label>Patient Reference Number <span class="required">*</span></label>
      <input type="text" name="reference_number" placeholder="e.g. R26C170001" required>

      <label>Test Type <span class="required">*</span></label>
      <input type="text" name="test_type" placeholder="e.g. Full Blood Count" required>

      <label>Laboratory Name</label>
      <input type="text" name="lab_name" placeholder="Your laboratory name">

      <label>Result PDF <span class="required">*</span></label>
      <input type="file" name="lab_result_pdf" accept="application/pdf" required>
      <small>PDF files only. Maximum 10MB.</small>

      <button type="submit" class="btn" id="upload-btn">Upload Result</button>
    </form>
  <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('portal-upload-form') && document.getElementById('portal-upload-form').addEventListener('submit', function(e) {
  e.preventDefault();
  var btn = document.getElementById('upload-btn');
  btn.disabled = true;
  btn.innerHTML = 'Uploading<span class="spinner"></span>';

  var fd = new FormData(this);
  fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
  })
  .then(function(r){ return r.json(); })
  .then(function(r) {
    var fb = document.getElementById('upload-feedback');
    if (r.success) {
      fb.className = 'notice notice-success';
      fb.textContent = r.data.message;
      fb.style.display = 'block';
      document.getElementById('portal-upload-form').reset();
    } else {
      fb.className = 'notice notice-error';
      fb.textContent = r.data || 'Upload failed. Please try again.';
      fb.style.display = 'block';
    }
    btn.disabled = false;
    btn.textContent = 'Upload Result';
  })
  .catch(function() {
    var fb = document.getElementById('upload-feedback');
    fb.className = 'notice notice-error';
    fb.textContent = 'Network error. Please try again.';
    fb.style.display = 'block';
    btn.disabled = false;
    btn.textContent = 'Upload Result';
  });
});
</script>
</body>
</html>
        <?php
    }
}
