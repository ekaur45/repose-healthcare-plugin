<?php
/**
 * Plugin Name: Repose Healthcare WooCommerce Plugin
 * Plugin URI:  https://reposehealthcare.com
 * Description: Automates order processing, laboratory integration, and diagnostic result reporting for Repose Healthcare home testing kits.
 * Version:     1.4.13
 * Author:      Repose Healthcare
 * Text Domain: repose-healthcare
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH'))
    exit;

define('REPOSE_VERSION', '1.4.13');
define('REPOSE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REPOSE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REPOSE_PLUGIN_FILE', __FILE__);
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
function repose_check_woocommerce()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Repose Healthcare</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return false;
    }
    return true;
}

add_action('plugins_loaded', 'repose_init', 20);


// Self Collect 

add_action('woocommerce_product_options_general_product_data', function () {

    woocommerce_wp_checkbox([
        'id' => '_is_self_collect',
        'label' => __('Self-Collect Test', 'textdomain'),
        'description' => __('Check if this is a self-collect test product'),
    ]);

});
add_action('woocommerce_process_product_meta', function ($product_id) {

    $is_self_collect = isset($_POST['_is_self_collect']) ? 'yes' : 'no';

    update_post_meta($product_id, '_is_self_collect', $is_self_collect);

});
add_filter('manage_edit-product_columns', function ($columns) {
    $columns['is_self_collect'] = 'Self-Collect';
    return $columns;
});

add_action('manage_product_posts_custom_column', function ($column, $post_id) {

    if ($column === 'is_self_collect') {
        echo get_post_meta($post_id, '_is_self_collect', true) === 'yes'
            ? '<span style="color:green;font-weight:bold;">Yes</span>'
            : '—';
    }

}, 10, 2);

// End Self Collect


add_action('woocommerce_product_options_general_product_data', function () {

    woocommerce_wp_checkbox([
        'id' => '_not_transfer_product',
        'label' => __('Not Transfer Product', 'textdomain'),
        'description' => __('Check if this product should not be transferred'),
    ]);

});

add_action('woocommerce_process_product_meta', function ($product_id) {

    $not_transfer_product = isset($_POST['_not_transfer_product']) ? 'yes' : 'no';
    update_post_meta($product_id, '_not_transfer_product', $not_transfer_product);

});

add_filter('manage_edit-product_columns', function ($columns) {
    $columns['not_transfer_product'] = 'Auto transfer';
    return $columns;
});

add_action('manage_product_posts_custom_column', function ($column, $post_id) {

    if ($column === 'not_transfer_product') {
        echo get_post_meta($post_id, '_not_transfer_product', true) === 'yes'
            ? '<span style="color:red;font-weight:bold;">No</span>'
            : 'Yes';
    }

}, 10, 2);

function repose_init()
{
    if (!repose_check_woocommerce())
        return;

    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-db.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-reference.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-order-validator.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-lab-transmission.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-results-manager.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-comment-library.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-notifications.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-audit-log.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-store-api.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-pdf-brander.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-lab-portal.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-json-import.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-patient-registry.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-tdl-csv.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-hl7-parser.php';
    require_once REPOSE_PLUGIN_DIR . 'includes/class-repose-hl7-inbound.php';
    require_once REPOSE_PLUGIN_DIR . 'admin/class-repose-admin.php';

    Repose_DB::init();
    HN_Repose_Admin::init();
    Repose_Lab_Transmission::init();
    HN_Repose_Results_Manager::init();
    Repose_Notifications::init();
    Repose_Store_API::init();
    Repose_Lab_Portal::init();
    Repose_JSON_Import::init();
    Repose_HL7_Inbound::init();

    // Validation fires only on Place Order
    add_action(
        'woocommerce_store_api_checkout_order_processed',
        array('Repose_Store_API', 'validate_fields')
    );

    // Flush rewrite AJAX
    add_action('wp_ajax_repose_flush_rewrite', 'repose_ajax_flush_rewrite');

    // Cart quantity sync: update product quantities based on per-patient test assignments
    add_action('wp_ajax_repose_update_cart_qty', 'repose_ajax_update_cart_qty');
    add_action('wp_ajax_nopriv_repose_update_cart_qty', 'repose_ajax_update_cart_qty');

    add_action('wp_enqueue_scripts', 'repose_enqueue_checkout_script');
}

function repose_ajax_flush_rewrite()
{
    check_ajax_referer('repose_admin_nonce', 'nonce');
    flush_rewrite_rules();
    wp_send_json_success();
}

/**
 * AJAX: Sync WooCommerce cart item quantities to match per-patient test assignments.
 *
 * Payload (POST):
 *   nonce           – repose_checkout_nonce
 *   assignments     – JSON: { "<product_id>": <patient_count>, ... }
 *                     patient_count = number of patients who have that product assigned.
 *                     0 means no patient currently has that product — the line is left
 *                     unchanged (products are never removed from the cart by this action).
 *
 * Returns JSON success with { updated: { "<product_id>": <new_qty> } }
 */
function repose_ajax_update_cart_qty()
{
    if (!check_ajax_referer('repose_checkout_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce', 403);
        return;
    }

    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error('No cart', 500);
        return;
    }

    // Parse assignments: { product_id (string) => count (int) }
    $raw = wp_unslash($_POST['assignments'] ?? '{}');
    $assignments = json_decode($raw, true);
    if (!is_array($assignments)) {
        wp_send_json_error('Invalid assignments payload', 400);
        return;
    }

    $cart = WC()->cart;
    $updated = array();

    foreach ($cart->get_cart() as $cart_key => $cart_item) {
        $pid = (string) $cart_item['product_id'];
        if (!array_key_exists($pid, $assignments)) {
            continue;
        }

        $desired_qty = max(0, (int) $assignments[$pid]);

        // Never remove lines: unassigned products stay in the cart at their current qty.
        if ($desired_qty <= 0) {
            continue;
        }

        if ((int) $cart_item['quantity'] !== $desired_qty) {
            $cart->set_quantity($cart_key, $desired_qty, true);
            $updated[$pid] = $desired_qty;
        }
    }

    $cart->calculate_totals();

    wp_send_json_success(array('updated' => $updated));
}

function repose_enqueue_checkout_script()
{
    if (!is_checkout())
        return;
    wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13');

    // Inline CSS: style the patient section and hide any leftover PHP placeholder
    $inline_css = '
        @import url(\'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap\');
        #repose-patient-fields-wrap { margin-bottom: 0; }
        #rh-patient-fields-dom-target:empty { display: none; }
        /* Ensure flatpickr calendar appears above WC overlay */
        .flatpickr-calendar { z-index: 999999 !important; }
        /* Patient section spacing — sits above payment block */
        #rh-patient-fields-dom { margin-bottom: 8px; }
        /* Smooth input focus states */
        #rh-patient-fields-dom input:focus,
        #rh-patient-fields-dom select:focus,
        #rh-patient-fields-dom textarea:focus {
            border-color: #1a6e8c !important;
            box-shadow: 0 0 0 3px rgba(26,110,140,0.12) !important;
            background-color: #fff !important;
            outline: none !important;
        }
        #rh-patient-fields-dom .rh-pb { transition: box-shadow 0.2s; }
        #rh-patient-fields-dom .rh-pb:hover { box-shadow: 0 4px 16px rgba(26,110,140,0.10); }
    ';
    wp_register_style('repose-checkout', false);
    wp_enqueue_style('repose-checkout');
    wp_add_inline_style('repose-checkout', $inline_css);
    wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '4.6.13', true);
    wp_enqueue_script(
        'repose-checkout-fields',
        REPOSE_PLUGIN_URL . 'assets/js/checkout-fields.js',
        array('wp-plugins', 'wp-element', 'wp-hooks', 'jquery', 'flatpickr'),
        REPOSE_VERSION,
        true  // load in footer after WC blocks scripts
    );
    // Build cart items list for test assignment UI
    $cart_items = array();
    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_key => $cart_item) {
            $product = $cart_item['data'];
            if (!$product)
                continue;
            $cart_items[] = array(
                'product_id' => $cart_item['product_id'],
                'name' => $product->get_name(),
                'qty' => (int) $cart_item['quantity'],
            );
        }
    }

    // Detect block checkout so JS knows which mode to use
    $checkout_page_id = wc_get_page_id('checkout');
    $checkout_post = $checkout_page_id > 0 ? get_post($checkout_page_id) : null;
    $is_block_checkout = $checkout_post && function_exists('has_block') && has_block('woocommerce/checkout', $checkout_post);

    wp_localize_script('repose-checkout-fields', 'reposeCheckout', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('repose_checkout_nonce'),
        'cartItems' => $cart_items,
        'isBlockCheckout' => $is_block_checkout ? '1' : '0',
    ));
}

register_activation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'includes/class-repose-db.php';
    Repose_DB::install();
    // Register endpoints and flush
    add_rewrite_endpoint('test-results', EP_ROOT | EP_PAGES);
    add_rewrite_rule('^lab-portal/?$', 'index.php?repose_lab_portal=1', 'top');
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'includes/class-repose-db.php';
    Repose_DB::deactivate();
    flush_rewrite_rules();
});
