<?php
if (!defined('ABSPATH'))
    exit;

class HN_Repose_Admin
{

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'register_menus'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        // AJAX handlers
        add_action('wp_ajax_repose_approve_order', array(__CLASS__, 'ajax_approve_order'));
        add_action('wp_ajax_repose_reject_order', array(__CLASS__, 'ajax_reject_order'));
        add_action('wp_ajax_repose_transmit_order', array(__CLASS__, 'ajax_transmit_order'));
        add_action('wp_ajax_repose_approve_result', array(__CLASS__, 'ajax_approve_result'));
        add_action('wp_ajax_repose_upload_result', array(__CLASS__, 'ajax_upload_result'));
        add_action('wp_ajax_repose_add_note', array(__CLASS__, 'ajax_add_note'));
        add_action('wp_ajax_repose_save_template', array(__CLASS__, 'ajax_save_template'));
        add_action('wp_ajax_repose_delete_template', array(__CLASS__, 'ajax_delete_template'));
        add_action('wp_ajax_repose_save_settings', array(__CLASS__, 'ajax_save_settings'));
        add_action('wp_ajax_repose_save_order_edit', array(__CLASS__, 'ajax_save_order_edit'));
        add_action('wp_ajax_repose_add_tracking', array(__CLASS__, 'ajax_add_tracking'));
        add_action('wp_ajax_repose_receive_orr', array(__CLASS__, 'ajax_receive_orr'));
        add_action('wp_ajax_repose_toggle_auto_transmit', array(__CLASS__, 'ajax_toggle_auto_transmit'));
        add_action('wp_ajax_repose_transmit_all_pending', array(__CLASS__, 'ajax_transmit_all_pending'));
        add_action('wp_ajax_repose_lookup_customer_email', array(__CLASS__, 'ajax_lookup_customer_email'));
        add_action('wp_ajax_repose_create_manual_order', array(__CLASS__, 'ajax_create_manual_order'));
        add_action('wp_ajax_repose_create_patient', array(__CLASS__, 'ajax_create_patient'));
        add_action('wp_ajax_repose_update_patient', array(__CLASS__, 'ajax_update_patient'));
        add_action('wp_ajax_repose_assign_patient_test', array(__CLASS__, 'ajax_assign_patient_test'));
        add_action('wp_ajax_repose_retransmit_order', array(__CLASS__, 'ajax_retransmit_order'));
        add_action('wp_ajax_repose_send_batch_csv', array(__CLASS__, 'ajax_send_batch_csv'));
        add_action('wp_ajax_repose_search_patients', array(__CLASS__, 'ajax_search_patients'));
        add_action('wp_ajax_repose_get_patient_orders', array(__CLASS__, 'ajax_get_patient_orders'));
        add_action('wp_ajax_repose_assign_result_patient', array(__CLASS__, 'ajax_assign_result_patient'));


        // REST endpoint for inbound ORR-HL7 from laboratory
        add_action('rest_api_init', array(__CLASS__, 'register_orr_endpoint'));

        // Auto-sync patients to registry after order is created
        add_action('woocommerce_payment_complete', array(__CLASS__, 'sync_patient_registry'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'sync_patient_registry'));

        // ── Block Checkout: native field registration (WC 8.9+) ────────────
        add_action('woocommerce_init', array(__CLASS__, 'register_block_checkout_fields'));

        // ── Block Checkout: save fields from Store API extensions payload ───
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array(__CLASS__, 'block_save_fields'),
            10,
            2
        );

        // ── Classic Checkout fallback — only when block checkout is NOT active ──
        if (!self::is_block_checkout()) {
            // Render patient section BEFORE the payment methods block
            add_action('woocommerce_review_order_before_payment', array(__CLASS__, 'classic_checkout_fields'));
            add_action('woocommerce_checkout_process', array(__CLASS__, 'classic_validate_fields'));
            add_action('woocommerce_checkout_create_order', array(__CLASS__, 'order_save_fields'), 10, 2);
            add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'classic_save_fields'));
        }

        // ── Hide native WooCommerce "Order notes" field ──────────────────────
        add_filter('woocommerce_checkout_fields', function ($fields) {
            unset($fields['order']['order_comments']);
            return $fields;
        });
    }

    // -----------------------------------------------------------------------
    // Admin menus
    // -----------------------------------------------------------------------

    public static function register_menus()
    {
        add_menu_page(
            __('Repose Healthcare', 'repose-healthcare'),
            __('Repose Healthcare', 'repose-healthcare'),
            'manage_woocommerce',
            'repose-dashboard',
            array(__CLASS__, 'page_dashboard'),
            'dashicons-heart',
            56
        );
        add_submenu_page('repose-dashboard', 'Create Order', 'Create Order', 'manage_woocommerce', 'repose-create-order', array(__CLASS__, 'page_create_order'));
        add_submenu_page('repose-dashboard', 'Order Retrieval', 'Order Retrieval', 'manage_woocommerce', 'repose-order-retrieval', array(__CLASS__, 'page_order_retrieval'));
        add_submenu_page('repose-dashboard', 'Manual Auth Queue', 'Auth Queue', 'manage_woocommerce', 'repose-auth-queue', array(__CLASS__, 'page_auth_queue'));
        add_submenu_page('repose-dashboard', 'Results Review Queue', 'Results Queue', 'manage_woocommerce', 'repose-results-queue', array(__CLASS__, 'page_results_queue'));
        add_submenu_page('repose-dashboard', 'Upload Result', 'Upload Result', 'manage_woocommerce', 'repose-upload-result', array(__CLASS__, 'page_upload_result'));
        add_submenu_page('repose-dashboard', 'Transmission Log', 'Transmission Log', 'manage_woocommerce', 'repose-transmission-log', array(__CLASS__, 'page_transmission_log'));
        add_submenu_page('repose-dashboard', 'Patient Registry', 'Patient Registry', 'manage_woocommerce', 'repose-patient-registry', array(__CLASS__, 'page_patient_registry'));
        add_submenu_page('repose-dashboard', 'Comment Library', 'Comment Library', 'manage_woocommerce', 'repose-comments', array(__CLASS__, 'page_comment_library'));
        add_submenu_page('repose-dashboard', 'Settings', 'Settings', 'manage_options', 'repose-settings', array(__CLASS__, 'page_settings'));
    }

    public static function enqueue_assets(string $hook)
    {
        if (strpos($hook, 'repose') === false)
            return;
        wp_enqueue_style('repose-admin', REPOSE_PLUGIN_URL . 'assets/css/admin.css', array(), REPOSE_VERSION);
        wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13');
        wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '4.6.13', true);
        wp_enqueue_script('repose-admin', REPOSE_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'flatpickr'), REPOSE_VERSION, true);
        wp_localize_script('repose-admin', 'reposeAdmin', array(
            'nonce' => wp_create_nonce('repose_admin_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ));
    }

    // -----------------------------------------------------------------------
    // Page renderers
    // -----------------------------------------------------------------------

    public static function page_dashboard()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    public static function page_create_order()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/create-order.php';
    }
    public static function page_order_retrieval()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/order-retrieval.php';
    }
    public static function page_auth_queue()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/auth-queue.php';
    }
    public static function page_results_queue()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/results-queue.php';
    }
    public static function page_upload_result()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/upload-result.php';
    }
    public static function page_transmission_log()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/transmission-log.php';
    }
    public static function page_patient_registry()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/patient-registry.php';
    }
    public static function page_comment_library()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/comment-library.php';
    }
    public static function page_product_catalogue()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/product-catalogue.php';
    }
    public static function page_settings()
    {
        include REPOSE_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // -----------------------------------------------------------------------
    // -----------------------------------------------------------------------
    // Helper: detect whether the site is using the WooCommerce block checkout
    // -----------------------------------------------------------------------

    public static function is_block_checkout(): bool
    {
        // Method 1: WooCommerce 8+ built-in helper
        if (function_exists('wc_current_theme_is_fse_theme')) {
            // Not quite right — check the checkout page post content instead
        }

        // Method 2: check checkout page post for the woocommerce/checkout block
        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id > 0) {
            $post = get_post($checkout_page_id);
            if ($post && function_exists('has_block') && has_block('woocommerce/checkout', $post)) {
                return true;
            }
        }

        // Method 3: WooCommerce Blocks class check (older WC versions)
        if (
            class_exists('\Automattic\WooCommerce\Blocks\BlockTemplatesController') ||
            class_exists('\Automattic\WooCommerce\StoreApi\Routes\V1\Checkout')
        ) {
            // Blocks are available; still rely on post content check above
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Block Checkout: native fields API (WC 8.9+)
    // -----------------------------------------------------------------------

    public static function register_block_checkout_fields()
    {
        if (!function_exists('__experimentalWooRegisterCheckoutField'))
            return;

        $fields = array(
            array('id' => 'repose-healthcare/patient-forename', 'label' => 'Patient Forename', 'location' => 'contact', 'required' => true),
            array('id' => 'repose-healthcare/patient-surname', 'label' => 'Patient Surname', 'location' => 'contact', 'required' => true),
            array(
                'id' => 'repose-healthcare/date-of-birth',
                'label' => 'Date of Birth',
                'location' => 'contact',
                'required' => true,
                'attributes' => array('type' => 'text', 'placeholder' => 'DD/MM/YYYY  e.g. 15/06/1985', 'readonly' => 'readonly')
            ),
        );
        foreach ($fields as $field) {
            try {
                __experimentalWooRegisterCheckoutField($field);
            } catch (\Exception $e) {
            }
        }

        if (version_compare(WC()->version, '9.2', '>=')) {
            try {
                __experimentalWooRegisterCheckoutField(array(
                    'id' => 'repose-healthcare/sex-at-birth',
                    'label' => 'Sex at Birth',
                    'location' => 'contact',
                    'required' => true,
                    'type' => 'select',
                    'options' => array(
                        array('value' => '', 'label' => 'Please select'),
                        array('value' => 'male', 'label' => 'Male'),
                        array('value' => 'female', 'label' => 'Female'),
                    ),
                ));
            } catch (\Exception $e) {
            }
        }
    }

    // -----------------------------------------------------------------------
    // Block Checkout: save fields from Store API request
    // -----------------------------------------------------------------------

    public static function block_save_fields(\WC_Order $order, \WP_REST_Request $request)
    {
        $saved = false;

        // Method 1: WC native additional_fields (WC 8.9+ native API)
        $additional = $request->get_param('additional_fields');
        if (is_array($additional)) {
            $native_map = array(
                'repose-healthcare/patient-forename' => '_repose_patient_forename',
                'repose-healthcare/patient-surname' => '_repose_patient_surname',
                'repose-healthcare/sex-at-birth' => '_repose_sex_at_birth',
                'repose-healthcare/date-of-birth' => '_repose_date_of_birth',
            );
            foreach ($native_map as $block_key => $meta_key) {
                if (!empty($additional[$block_key])) {
                    $order->update_meta_data($meta_key, sanitize_text_field($additional[$block_key]));
                    $saved = true;
                }
            }
        }

        // Method 2: Our custom extensions payload (from checkout-fields.js)
        $extensions = $request->get_param('extensions');
        if (is_array($extensions) && !empty($extensions['repose-healthcare'])) {
            $ext = $extensions['repose-healthcare'];
            if (!empty($ext['repose_patient_fields'])) {
                $pf = $ext['repose_patient_fields'];
                if (!empty($pf['forename'])) {
                    $order->update_meta_data('_repose_patient_forename', sanitize_text_field($pf['forename']));
                    $saved = true;
                }
                if (!empty($pf['surname'])) {
                    $order->update_meta_data('_repose_patient_surname', sanitize_text_field($pf['surname']));
                    $saved = true;
                }
                if (!empty($pf['sex'])) {
                    $order->update_meta_data('_repose_sex_at_birth', sanitize_text_field($pf['sex']));
                    $saved = true;
                }
                if (!empty($pf['dob'])) {
                    $order->update_meta_data('_repose_date_of_birth', sanitize_text_field($pf['dob']));
                    $saved = true;
                }
            }
        }

        // Method 3: Fallback — read straight from POST (hidden inputs injected by JS)
        if (!$saved) {
            self::save_patient_meta_from_post($order);
        }

        $order->save();
    }

    // -----------------------------------------------------------------------
    // Classic Checkout fallback
    // -----------------------------------------------------------------------

    public static function classic_checkout_fields($checkout)
    {
        // Never render classic fields when block checkout is active
        if (self::is_block_checkout())
            return;

        // Output a styled placeholder — the JS DOM injection replaces this with
        // the full multi-patient form. No PHP fields are rendered here.
        echo '<div id="repose-patient-fields-wrap" style="margin-bottom:24px;">
            <div id="rh-patient-fields-dom-target"></div>
        </div>';
    }

    public static function classic_validate_fields()
    {
        if (self::is_block_checkout())
            return;

        // Validate from WC session (populated by the JS AJAX on every keystroke).
        // Never validate $_POST classic fields — those fields no longer exist.
        if (!function_exists('WC') || !WC()->session) {
            wc_add_notice('Session unavailable. Please refresh and try again.', 'error');
            return;
        }

        $session = WC()->session->get('repose_patient_fields', array());

        if (empty($session) || !is_array($session)) {
            wc_add_notice('Please complete the Patient Information section before placing your order.', 'error');
            return;
        }

        $required = array(
            'repose_patient_forename' => 'Patient First Name',
            'repose_patient_surname' => 'Patient Last Name',
            'repose_sex_at_birth' => 'Sex at Birth',
            'repose_date_of_birth' => 'Date of Birth',
        );
        foreach ($required as $key => $label) {
            if (empty(trim((string) ($session[$key] ?? '')))) {
                wc_add_notice($label . ' is required in the Patient Information section.', 'error');
            }
        }
    }

    /** woocommerce_checkout_create_order — fires before save, works for classic */
    public static function order_save_fields(\WC_Order $order, array $data)
    {
        // Read from WC session (JS AJAX saves here on every keystroke)
        if (function_exists('WC') && WC()->session) {
            $session = WC()->session->get('repose_patient_fields', array());
            if (!empty($session) && is_array($session)) {
                Repose_Store_API::write_meta_public($order, $session);
                WC()->session->__unset('repose_patient_fields');
                return;
            }
        }
        // Fallback: try POST (shouldn't happen but keeps save safe)
        self::save_patient_meta_from_post($order);
    }

    /** woocommerce_checkout_update_order_meta — older fallback hook */
    public static function classic_save_fields(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;
        // Only save from POST if session-based save hasn't already run
        if ($order->get_meta('_repose_patient_forename'))
            return;
        self::save_patient_meta_from_post($order);
        $order->save();
    }

    /** Read patient fields from $_POST and write to order meta */
    private static function save_patient_meta_from_post(\WC_Order $order)
    {
        $map = array(
            'repose_patient_forename' => '_repose_patient_forename',
            'repose_patient_surname' => '_repose_patient_surname',
            'repose_sex_at_birth' => '_repose_sex_at_birth',
            'repose_date_of_birth' => '_repose_date_of_birth',
            'repose_additional_notes' => '_repose_additional_notes',
        );
        foreach ($map as $post_key => $meta_key) {
            if (!empty($_POST[$post_key])) {
                $order->update_meta_data($meta_key, sanitize_text_field(wp_unslash($_POST[$post_key])));
            }
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: Order queue
    // -----------------------------------------------------------------------

    public static function ajax_approve_order()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found');

        $ok = Repose_Lab_Transmission::auto_transmit($order);
        self::close_queue_item($order_id, 'approved');
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'manual_approved',
            'Approved and transmitted by ' . wp_get_current_user()->display_name
        );

        wp_send_json_success(array('message' => $ok ? 'Order approved and transmitted to lab.' : 'Approved but transmission failed — check settings.'));
    }

    public static function ajax_reject_order()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found');

        $order->update_status('cancelled', 'Repose: Rejected — ' . $reason);
        self::close_queue_item($order_id, 'rejected');
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'manual_rejected',
            'Rejected by ' . wp_get_current_user()->display_name . '. Reason: ' . $reason
        );

        wp_send_json_success(array('message' => 'Order rejected.'));
    }

    public static function ajax_transmit_order()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found');

        $ok = Repose_Lab_Transmission::auto_transmit($order);
        self::close_queue_item($order_id, 'transmitted');
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'manual_transmitted',
            'Manually transmitted by ' . wp_get_current_user()->display_name
        );

        wp_send_json_success(array('message' => $ok ? 'Transmitted to lab.' : 'Transmission failed — check settings.'));
    }

    private static function close_queue_item(int $order_id, string $status)
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'repose_order_queue',
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('order_id' => $order_id, 'status' => 'pending')
        );
    }

    // -----------------------------------------------------------------------
    // AJAX: Results
    // -----------------------------------------------------------------------

    public static function ajax_approve_result()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $result_id = (int) ($_POST['result_id'] ?? 0);
        $schedule_review = !empty($_POST['schedule_review']);

        $ok = HN_Repose_Results_Manager::approve_result($result_id, get_current_user_id(), $schedule_review);
        $ok ? wp_send_json_success(array('message' => 'Result approved and patient notified.'))
            : wp_send_json_error('Result not found.');
    }

    public static function ajax_upload_result()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $test_type = sanitize_text_field($_POST['test_type'] ?? '');
        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        $patient_uid = sanitize_text_field($_POST['patient_uid'] ?? '');

        if (empty($_FILES['repose_result_file']))
            wp_send_json_error('No file uploaded.');

        $result_id = HN_Repose_Results_Manager::upload_result($order_id, get_current_user_id(), $_FILES['repose_result_file'], $test_type);

        if (is_wp_error($result_id)) {
            wp_send_json_error($result_id->get_error_message());
        }

        // If a patient was specified, link the result to them
        if ($patient_id && $patient_uid && $order_id) {
            global $wpdb;
            // Save patient UID on the order
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_repose_result_patient_uid', $patient_uid);
                $order->save();
            }
            // Mark their patient_test as complete
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}repose_patient_tests
                 SET status='complete', result_id=%d
                 WHERE patient_id=%d AND order_id=%d AND status='pending'
                 LIMIT 1",
                $result_id,
                $patient_id,
                $order_id
            ));
            Repose_Audit_Log::record(
                $order_id,
                get_current_user_id(),
                'result_uploaded',
                "Result #{$result_id} uploaded and assigned to patient {$patient_uid} by " . wp_get_current_user()->display_name
            );
        }

        wp_send_json_success(array(
            'result_id' => $result_id,
            'message' => 'Result uploaded' . ($patient_uid ? " and assigned to patient {$patient_uid}." : '. Go to Results Queue to review and approve it.'),
        ));
    }

    public static function ajax_add_note()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $result_id = (int) ($_POST['result_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        $visibility = sanitize_text_field($_POST['visibility'] ?? 'internal');

        $id = HN_Repose_Results_Manager::add_note($result_id, get_current_user_id(), $note, $visibility);
        wp_send_json_success(array('note_id' => $id));
    }

    // -----------------------------------------------------------------------
    // AJAX: Comment library
    // -----------------------------------------------------------------------

    public static function ajax_save_template()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $id = (int) ($_POST['template_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        $body = sanitize_textarea_field($_POST['body'] ?? '');
        $visibility = sanitize_text_field($_POST['visibility'] ?? 'patient');

        if ($id > 0) {
            Repose_Comment_Library::update($id, $title, $body, $visibility);
            wp_send_json_success(array('message' => 'Template updated.'));
        } else {
            $new_id = Repose_Comment_Library::add($title, $body, $visibility);
            wp_send_json_success(array('message' => 'Template created.', 'id' => $new_id));
        }
    }

    public static function ajax_delete_template()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $id = (int) ($_POST['template_id'] ?? 0);
        Repose_Comment_Library::delete($id);
        wp_send_json_success(array('message' => 'Template deleted.'));
    }

    // -----------------------------------------------------------------------
    // AJAX: Settings
    // -----------------------------------------------------------------------

    public static function ajax_save_settings()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Unauthorized', 403);

        $settings = array(
            'repose_lab_email' => sanitize_email($_POST['lab_email'] ?? ''),
            'repose_transmission_method' => in_array($_POST['transmission_method'] ?? '', array('email', 'azure'), true) ? $_POST['transmission_method'] : 'email',
            // Azure
            'repose_azure_account' => sanitize_text_field($_POST['azure_account'] ?? ''),
            'repose_azure_requests_container' => sanitize_text_field($_POST['azure_requests_container'] ?? 'requests'),
            'repose_azure_container' => sanitize_text_field($_POST['azure_container'] ?? 'results'),
            'repose_azure_sas_token' => sanitize_text_field($_POST['azure_sas_token'] ?? ''),
            'repose_api_token' => sanitize_text_field($_POST['api_token'] ?? ''),
            // Email templates
            'repose_result_email_subject' => sanitize_text_field($_POST['result_ready_email_subject'] ?? ''),
            'repose_result_email_body' => sanitize_textarea_field($_POST['result_ready_email_body'] ?? ''),
            'repose_review_email_subject' => sanitize_text_field($_POST['review_email_subject'] ?? ''),
            'repose_review_email_body' => sanitize_textarea_field($_POST['review_email_body'] ?? ''),
            'repose_review_url' => esc_url_raw($_POST['review_url'] ?? 'https://g.page/r/CbeI7sxByQWwEAE/review'),
            'repose_sample_received_email_subject' => sanitize_text_field($_POST['sample_received_email_subject'] ?? ''),
            'repose_sample_received_email_body' => sanitize_textarea_field($_POST['sample_received_email_body'] ?? ''),
            'repose_dispatch_email_subject' => sanitize_text_field($_POST['dispatch_email_subject'] ?? ''),
            'repose_dispatch_email_body' => sanitize_textarea_field($_POST['dispatch_email_body'] ?? ''),
            // Branding
            'repose_company_name' => sanitize_text_field($_POST['company_name'] ?? ''),
            'repose_company_address' => sanitize_textarea_field($_POST['company_address'] ?? ''),
            'repose_company_phone' => sanitize_text_field($_POST['company_phone'] ?? ''),
            'repose_company_email' => sanitize_email($_POST['company_email'] ?? ''),
            'repose_company_website' => sanitize_text_field($_POST['company_website'] ?? ''),
            'repose_logo_url' => esc_url_raw($_POST['logo_url'] ?? ''),
            'repose_brand_color' => sanitize_hex_color($_POST['brand_color'] ?? '#1a6e8c') ?: '#1a6e8c',
            // Lab portal
            'repose_lab_portal_token' => sanitize_text_field($_POST['lab_portal_token'] ?? ''),
            // Outbound lab API credentials
            'API_URL' => esc_url_raw($_POST['API_URL'] ?? ''),
            'API_USERNAME' => sanitize_email($_POST['API_USERNAME'] ?? ''),
            'API_PASSWORD' => sanitize_text_field($_POST['API_PASSWORD'] ?? ''),
            // Auto-transmit
            'repose_auto_transmit' => !empty($_POST['auto_transmit']) ? '1' : '0',
        );
        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        // Save TDL test code mapping (product_id → tdl_code)
        $tdl_map = array();
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'tdl_code_') === 0) {
                $pid = (int) substr($k, 9);
                $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(sanitize_text_field($v))));
                if ($pid > 0 && $code) {
                    $tdl_map[$pid] = $code;
                }
            }
        }
        update_option('repose_tdl_test_codes', wp_json_encode($tdl_map));

        wp_send_json_success(array('message' => 'Settings saved.'));
    }

    // -----------------------------------------------------------------------
    // AJAX: Inline order edit with field-level audit logging
    // -----------------------------------------------------------------------

    public static function ajax_save_order_edit()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found');

        $staff_note = sanitize_textarea_field($_POST['edit_note'] ?? '');
        $changes = array();

        // ── Patient meta fields ─────────────────────────────────────────────
        $meta_fields = array(
            'forename' => array('_repose_patient_forename', 'Patient Forename'),
            'surname' => array('_repose_patient_surname', 'Patient Surname'),
            'sex' => array('_repose_sex_at_birth', 'Sex at Birth'),
            'dob' => array('_repose_date_of_birth', 'Date of Birth'),
            'order_type' => array('_repose_order_type', 'Order Type'),
            'p1_notes' => array('_repose_additional_notes', 'Additional Notes'),
        );
        foreach ($meta_fields as $post_key => $info) {
            list($meta_key, $label) = $info;
            $new_val = sanitize_text_field(wp_unslash($_POST[$post_key] ?? ''));
            $old_val = (string) $order->get_meta($meta_key);
            if ($new_val !== $old_val) {
                $changes[] = $label . ': "' . $old_val . '" → "' . $new_val . '"';
                $order->update_meta_data($meta_key, $new_val);
            }
        }

        // ── Billing email ───────────────────────────────────────────────────
        $new_email = sanitize_email($_POST['email'] ?? '');
        $old_email = $order->get_billing_email();
        if ($new_email && $new_email !== $old_email) {
            $changes[] = 'Email: "' . $old_email . '" → "' . $new_email . '"';
            $order->set_billing_email($new_email);
        }

        // ── Shipping address ────────────────────────────────────────────────
        $ship_map = array(
            'ship_addr1' => 'set_shipping_address_1',
            'ship_addr2' => 'set_shipping_address_2',
            'ship_city' => 'set_shipping_city',
            'ship_county' => 'set_shipping_state',
            'ship_post' => 'set_shipping_postcode',
        );
        $ship_get_map = array(
            'ship_addr1' => 'get_shipping_address_1',
            'ship_addr2' => 'get_shipping_address_2',
            'ship_city' => 'get_shipping_city',
            'ship_county' => 'get_shipping_state',
            'ship_post' => 'get_shipping_postcode',
        );
        foreach ($ship_map as $post_key => $setter) {
            $new_val = sanitize_text_field(wp_unslash($_POST[$post_key] ?? ''));
            $getter = $ship_get_map[$post_key];
            $old_val = (string) $order->$getter();
            if ($new_val !== $old_val) {
                $label = ucwords(str_replace(array('ship_', '_'), array('', ' '), $post_key));
                $changes[] = 'Shipping ' . $label . ': "' . $old_val . '" → "' . $new_val . '"';
                $order->$setter($new_val);
            }
        }

        $order->save();

        // ── Extra / additional patients ─────────────────────────────────────
        $extra_json = sanitize_text_field(wp_unslash($_POST['extra_patients'] ?? '[]'));
        $extra_patients = json_decode($extra_json, true);
        $extra_count = 0;

        // ── Edits to existing additional patients ───────────────────────────
        $existing_json = sanitize_text_field(wp_unslash($_POST['existing_patients'] ?? '[]'));
        $existing_edits = json_decode($existing_json, true);
        $current_additional = (array) $order->get_meta('_repose_additional_patients');

        if (is_array($existing_edits) && !empty($existing_edits)) {
            foreach ($existing_edits as $edit) {
                $ap_idx = isset($edit['_apidx']) ? (int) $edit['_apidx'] : null;
                if ($ap_idx === null || !isset($current_additional[$ap_idx]))
                    continue;
                $old = $current_additional[$ap_idx];
                $new = array_merge($old, array(
                    'forename' => sanitize_text_field($edit['forename'] ?? $old['forename']),
                    'surname' => sanitize_text_field($edit['surname'] ?? $old['surname']),
                    'dob' => sanitize_text_field($edit['dob'] ?? $old['dob']),
                    'sex' => sanitize_text_field($edit['sex'] ?? $old['sex']),
                    'notes' => sanitize_textarea_field($edit['notes'] ?? $old['notes'] ?? ''),
                ));
                if ($new !== $old) {
                    $current_additional[$ap_idx] = $new;
                    $changes[] = 'Patient ' . ($ap_idx + 2) . ' updated';
                }
            }
            $order->update_meta_data('_repose_additional_patients', array_values($current_additional));
            $order->save();
        }

        if (is_array($extra_patients) && !empty($extra_patients)) {
            foreach ($extra_patients as $ep) {
                $forename = sanitize_text_field($ep['forename'] ?? '');
                $surname = sanitize_text_field($ep['surname'] ?? '');
                $dob = sanitize_text_field($ep['dob'] ?? '');
                $sex = sanitize_text_field($ep['sex'] ?? '');
                $notes = sanitize_textarea_field($ep['notes'] ?? '');
                if (!$forename || !$surname)
                    continue;
                $current_additional[] = array(
                    'forename' => $forename,
                    'surname' => $surname,
                    'dob' => $dob,
                    'sex' => $sex,
                    'notes' => $notes,
                    'added_by' => wp_get_current_user()->display_name,
                    'added_at' => current_time('mysql'),
                );
                $extra_count++;
            }
            $order->update_meta_data('_repose_additional_patients', array_values($current_additional));
            $order->save();
            if ($extra_count) {
                $changes[] = $extra_count . ' additional patient(s) added';
            }
        }

        // ── Audit + note ────────────────────────────────────────────────────
        if (!empty($changes)) {
            $detail = 'Fields edited by ' . wp_get_current_user()->display_name . ': ' . implode('; ', $changes);
            if ($staff_note)
                $detail .= ' | Note: ' . $staff_note;
            Repose_Audit_Log::record($order_id, get_current_user_id(), 'fields_edited', $detail);
            $order->add_order_note('Repose: ' . $detail, 0, false);
        }

        wp_send_json_success(array(
            'message' => empty($changes)
                ? 'No changes detected.'
                : count($changes) . ' change(s) saved and logged.',
            'changes' => $changes,
        ));
    }

    // -----------------------------------------------------------------------
    // AJAX: Toggle auto-transmit setting
    // -----------------------------------------------------------------------

    public static function ajax_toggle_auto_transmit()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
        update_option('repose_auto_transmit', $enabled);

        $flushed = 0;

        // If turning ON, flush all pending orders immediately
        if ($enabled === '1') {
            global $wpdb;
            $pending = $wpdb->get_col(
                "SELECT order_id FROM {$wpdb->prefix}repose_order_queue WHERE status = 'pending'"
            );
            foreach ($pending as $order_id) {
                $order = wc_get_order((int) $order_id);
                if ($order) {
                    $tests = Repose_Order_Validator::get_tests($order);
                    if (count($tests) > 1) {
                        continue;
                    }
                    Repose_Lab_Transmission::auto_transmit($order);
                    self::close_queue_item((int) $order_id, 'approved');
                    $flushed++;
                }
            }
        }

        wp_send_json_success(array('enabled' => $enabled, 'flushed' => $flushed));
    }

    // -----------------------------------------------------------------------
    // AJAX: Transmit all currently-pending orders
    // -----------------------------------------------------------------------

    public static function ajax_transmit_all_pending()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        global $wpdb;
        $pending = $wpdb->get_col(
            "SELECT order_id FROM {$wpdb->prefix}repose_order_queue WHERE status = 'pending'"
        );

        $flushed = 0;
        foreach ($pending as $order_id) {
            $order = wc_get_order((int) $order_id);
            if ($order) {
                $tests = Repose_Order_Validator::get_tests($order);
                if (count($tests) > 1) {
                    continue;
                }
                Repose_Lab_Transmission::auto_transmit($order);
                self::close_queue_item((int) $order_id, 'approved');
                Repose_Audit_Log::record(
                    (int) $order_id,
                    get_current_user_id(),
                    'manual_transmitted',
                    'Bulk transmitted by ' . wp_get_current_user()->display_name
                );
                $flushed++;
            }
        }

        wp_send_json_success(array('flushed' => $flushed));
    }

    // -----------------------------------------------------------------------
    // AJAX: Add Royal Mail tracking number and send dispatch email
    // -----------------------------------------------------------------------

    public static function ajax_add_tracking()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $tracking_number = sanitize_text_field(wp_unslash($_POST['tracking_number'] ?? ''));

        if (!$order_id)
            wp_send_json_error('Invalid order ID.');
        if (!$tracking_number)
            wp_send_json_error('Tracking number is required.');

        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found.');

        // Save tracking number to order meta
        $order->update_meta_data('_repose_tracking_number', $tracking_number);
        $order->update_meta_data('_repose_dispatched_at', current_time('mysql'));
        $order->update_meta_data('_repose_dispatched_by', wp_get_current_user()->display_name);
        $order->save();

        // Add WC order note
        $order->add_order_note(
            'Repose: Royal Mail tracking number added — ' . $tracking_number
            . ' by ' . wp_get_current_user()->display_name,
            0,
            false
        );

        // Audit log
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'tracking_added',
            'Tracking number ' . $tracking_number . ' added by ' . wp_get_current_user()->display_name
        );

        // Send dispatch / follow-up email to customer
        Repose_Notifications::notify_dispatch($order_id, $tracking_number);

        wp_send_json_success(array(
            'message' => 'Tracking number saved and dispatch email sent to customer.',
            'tracking_number' => $tracking_number,
        ));
    }

    // -----------------------------------------------------------------------
    // AJAX: Manual ORR-HL7 receipt confirmation (admin-triggered)
    // -----------------------------------------------------------------------

    public static function ajax_receive_orr()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        if (!$order_id)
            wp_send_json_error('Invalid order ID.');

        self::process_orr_confirmation($order_id);

        wp_send_json_success(array('message' => 'Sample receipt confirmed and customer notified.'));
    }

    // -----------------------------------------------------------------------
    // REST: Inbound ORR-HL7 endpoint for laboratory systems
    // POST /wp-json/repose/v1/orr-confirm
    // Header: X-Repose-Token: <token>
    // Body JSON: { "order_id": 123 } or { "reference": "RH-..." }
    // -----------------------------------------------------------------------

    public static function register_orr_endpoint()
    {
        register_rest_route('repose/v1', '/orr-confirm', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_orr_confirm'),
            'permission_callback' => array(__CLASS__, 'rest_orr_permission'),
        ));
    }

    public static function rest_orr_permission(\WP_REST_Request $request)
    {
        $token = $request->get_header('X-Repose-Token');
        $expected = get_option('repose_api_token', '');
        return $expected && hash_equals($expected, (string) $token);
    }

    public static function rest_orr_confirm(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $order_id = 0;

        if (!empty($params['order_id'])) {
            $order_id = (int) $params['order_id'];
        } elseif (!empty($params['reference_number'])) {
            // Look up by reference number stored in order meta
            global $wpdb;
            $ref = sanitize_text_field($params['reference_number']);
            $order_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_repose_reference_number' AND meta_value = %s
                 LIMIT 1",
                $ref
            ));
        }

        if (!$order_id) {
            return new \WP_REST_Response(array('success' => false, 'message' => 'Order not found.'), 404);
        }

        self::process_orr_confirmation($order_id);

        return new \WP_REST_Response(array('success' => true, 'message' => 'Sample receipt confirmed and customer notified.'), 200);
    }

    /**
     * Shared logic: mark sample received and send confirmation email.
     */
    private static function process_orr_confirmation(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;

        // Prevent duplicate confirmations
        if ($order->get_meta('_repose_sample_received') === '1')
            return;

        $order->update_meta_data('_repose_sample_received', '1');
        $order->update_meta_data('_repose_sample_received_at', current_time('mysql'));
        $order->save();

        $order->add_order_note(
            'Repose: ORR-HL7 receipt confirmation received — sample confirmed at lab. Customer notified.',
            0,
            false
        );

        Repose_Audit_Log::record(
            $order_id,
            0,
            'orr_received',
            'ORR-HL7 sample receipt confirmation received from laboratory.'
        );

        // Send sample-received email to customer
        Repose_Notifications::notify_sample_received($order_id);
    }

    // -----------------------------------------------------------------------
    // Auto-sync: register patients to registry when order payment completes
    // -----------------------------------------------------------------------

    public static function sync_patient_registry(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;
        if ($order->get_meta('_repose_registry_synced') === '1')
            return;
        
        Repose_Patient_Registry::sync_from_order($order);
        $order->update_meta_data('_repose_registry_synced', '1');
        $order->save();
    }

    // -----------------------------------------------------------------------
    // AJAX: Look up customer by email — searches WC users AND patient registry
    // -----------------------------------------------------------------------

    public static function ajax_lookup_customer_email()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email)
            wp_send_json_error('Email required.');

        $result = array('user' => null, 'patients' => array(), 'orders' => array());

        // 1. Search WP/WC user account by email
        $user = get_user_by('email', $email);
        if ($user) {
            $result['user'] = array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'first_name' => get_user_meta($user->ID, 'first_name', true) ?: $user->first_name,
                'last_name' => get_user_meta($user->ID, 'last_name', true) ?: $user->last_name,
                'billing_phone' => get_user_meta($user->ID, 'billing_phone', true),
                'billing_address_1' => get_user_meta($user->ID, 'billing_address_1', true),
                'billing_address_2' => get_user_meta($user->ID, 'billing_address_2', true),
                'billing_city' => get_user_meta($user->ID, 'billing_city', true),
                'billing_postcode' => get_user_meta($user->ID, 'billing_postcode', true),
            );
        }

        // 2. Search patient registry by email
        $registry_patients = Repose_Patient_Registry::search_by_email($email);
        foreach ($registry_patients as $p) {
            $result['patients'][] = array(
                'id' => $p->id,
                'patient_uid' => $p->patient_uid,
                'forename' => $p->forename,
                'surname' => $p->surname,
                'dob' => $p->dob,
                'sex' => $p->sex,
                'phone' => $p->phone,
            );
            // If no WC user found yet, use patient data to pre-fill
            if (!$result['user']) {
                $result['user'] = array(
                    'id' => 0,
                    'email' => $email,
                    'first_name' => $p->forename,
                    'last_name' => $p->surname,
                    'billing_phone' => $p->phone,
                    'billing_address_1' => '',
                    'billing_address_2' => '',
                    'billing_city' => '',
                    'billing_postcode' => '',
                );
            }
        }

        // 3. Find recent WC orders by billing email (even for guest orders)
        if (!$result['user']) {
            global $wpdb;
            // Search guest orders via billing email postmeta
            $guest_order_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_billing_email' AND meta_value = %s
                 ORDER BY post_id DESC LIMIT 5",
                $email
            ));
            foreach ($guest_order_ids as $goid) {
                $go = wc_get_order((int) $goid);
                if ($go && !$result['user']) {
                    $result['user'] = array(
                        'id' => 0,
                        'email' => $email,
                        'first_name' => $go->get_billing_first_name(),
                        'last_name' => $go->get_billing_last_name(),
                        'billing_phone' => $go->get_billing_phone(),
                        'billing_address_1' => $go->get_billing_address_1(),
                        'billing_address_2' => $go->get_billing_address_2(),
                        'billing_city' => $go->get_billing_city(),
                        'billing_postcode' => $go->get_billing_postcode(),
                    );
                    break;
                }
            }
        }

        wp_send_json_success($result);
    }

    // -----------------------------------------------------------------------
    // AJAX: Create manual order from admin dashboard
    // -----------------------------------------------------------------------

    public static function ajax_create_manual_order()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $mode = sanitize_text_field($_POST['mode'] ?? 'queue');   // 'queue' | 'transmit'
        $payload = json_decode(stripslashes($_POST['payload'] ?? '{}'), true);

        if (!is_array($payload) || empty($payload['email'])) {
            wp_send_json_error('Invalid payload.');
        }

        // ── Create WC order ─────────────────────────────────────────────
        $order = wc_create_order(array(
            'customer_id' => !empty($payload['wc_user_id']) ? (int) $payload['wc_user_id'] : 0,
            'status' => 'on-hold',
        ));

        if (is_wp_error($order)) {
            wp_send_json_error('Could not create WooCommerce order: ' . $order->get_error_message());
        }

        // Billing address
        $order->set_billing_first_name(sanitize_text_field($payload['first_name'] ?? ''));
        $order->set_billing_last_name(sanitize_text_field($payload['last_name'] ?? ''));
        $order->set_billing_email(sanitize_email($payload['email']));
        $order->set_billing_phone(sanitize_text_field($payload['phone'] ?? ''));
        $order->set_billing_address_1(sanitize_text_field($payload['addr1'] ?? ''));
        $order->set_billing_address_2(sanitize_text_field($payload['addr2'] ?? ''));
        $order->set_billing_city(sanitize_text_field($payload['city'] ?? ''));
        $order->set_billing_postcode(sanitize_text_field($payload['postcode'] ?? ''));
        $order->set_billing_country('GB');

        // Shipping = billing
        $order->set_shipping_address_1(sanitize_text_field($payload['addr1'] ?? ''));
        $order->set_shipping_address_2(sanitize_text_field($payload['addr2'] ?? ''));
        $order->set_shipping_city(sanitize_text_field($payload['city'] ?? ''));
        $order->set_shipping_postcode(sanitize_text_field($payload['postcode'] ?? ''));
        $order->set_shipping_country('GB');

        // ── Add products from first patient's tests (WC line items) ─────
        $patients = $payload['patients'] ?? array();
        $added_products = array(); // track product_ids to avoid duplicate line items

        foreach ($patients as $patient) {
            foreach (($patient['tests'] ?? array()) as $t) {
                $pid = (int) $t['product_id'];
                if ($pid && !in_array($pid, $added_products, true)) {
                    $product = wc_get_product($pid);
                    if ($product) {
                        $order->add_product($product, 1);
                        $added_products[] = $pid;
                    }
                }
            }
        }

        // ── Save patient meta for patient 1 ─────────────────────────────
        if (!empty($patients)) {
            $p1 = $patients[0];
            $order->update_meta_data('_repose_patient_forename', sanitize_text_field($p1['forename'] ?? ''));
            $order->update_meta_data('_repose_patient_surname', sanitize_text_field($p1['surname'] ?? ''));
            $order->update_meta_data('_repose_date_of_birth', sanitize_text_field($p1['dob'] ?? ''));
            $order->update_meta_data('_repose_sex_at_birth', sanitize_text_field($p1['sex'] ?? ''));
            $order->update_meta_data('_repose_additional_notes', sanitize_textarea_field($p1['notes'] ?? ''));

            // Per-patient test assignment
            $p1_tests = array_map(function ($t) {
                return array('product_id' => (int) ($t['product_id'] ?? 0), 'name' => sanitize_text_field($t['name'] ?? '')); }, $p1['tests'] ?? array());
            $order->update_meta_data('_repose_patient_1_tests', $p1_tests);

            // Additional patients 2-5
            if (count($patients) > 1) {
                $additional = array();
                foreach (array_slice($patients, 1) as $idx => $ap) {
                    $pn = $idx + 2;
                    $entry = array(
                        'forename' => sanitize_text_field($ap['forename'] ?? ''),
                        'surname' => sanitize_text_field($ap['surname'] ?? ''),
                        'dob' => sanitize_text_field($ap['dob'] ?? ''),
                        'sex' => sanitize_text_field($ap['sex'] ?? ''),
                        'notes' => sanitize_textarea_field($ap['notes'] ?? ''),
                    );
                    $additional[] = $entry;
                    $pn_tests = array_map(function ($t) {
                        return array('product_id' => (int) ($t['product_id'] ?? 0), 'name' => sanitize_text_field($t['name'] ?? '')); }, $ap['tests'] ?? array());
                    $order->update_meta_data("_repose_patient_{$pn}_tests", $pn_tests);
                }
                $order->update_meta_data('_repose_additional_patients', $additional);
                $order->update_meta_data('_repose_patient_count', count($patients));
            }
        }

        // Staff note
        if (!empty($payload['order_notes'])) {
            $order->add_order_note(sanitize_textarea_field($payload['order_notes']), 0, false);
        }

        $order->update_meta_data('_repose_created_by_staff', wp_get_current_user()->display_name);
        $order->calculate_totals();
        $order->save();

        $order_id = $order->get_id();

        // ── Sync to patient registry ─────────────────────────────────────
        Repose_Patient_Registry::sync_from_order($order);
        $order->update_meta_data('_repose_registry_synced', '1');
        $order->save();

        // ── Audit ────────────────────────────────────────────────────────
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'manual_created',
            'Order created manually by ' . wp_get_current_user()->display_name . ' for ' . sanitize_email($payload['email'])
        );

        // ── Route: queue or transmit immediately ─────────────────────────
        if ($mode === 'transmit') {
            $ok = Repose_Lab_Transmission::auto_transmit($order);
            $msg = $ok
                ? "Order #{$order_id} created and transmitted to laboratory."
                : "Order #{$order_id} created but transmission failed — check settings.";
        } else {
            Repose_Lab_Transmission::queue_for_manual_auth($order, 'Manually created by ' . wp_get_current_user()->display_name);
            $msg = "Order #{$order_id} created and added to Auth Queue for review.";
        }

        wp_send_json_success(array('order_id' => $order_id, 'message' => $msg));
    }

    // -----------------------------------------------------------------------
    // AJAX: Create patient in registry
    // -----------------------------------------------------------------------

    public static function ajax_create_patient()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $id = Repose_Patient_Registry::upsert(array(
            'forename' => sanitize_text_field($_POST['forename'] ?? ''),
            'surname' => sanitize_text_field($_POST['surname'] ?? ''),
            'dob' => sanitize_text_field($_POST['dob'] ?? ''),
            'sex' => sanitize_text_field($_POST['sex'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'wc_user_id' => !empty($_POST['wc_user_id']) ? (int) $_POST['wc_user_id'] : null,
        ));

        $patient = Repose_Patient_Registry::get($id);
        wp_send_json_success(array('id' => $id, 'uid' => $patient ? $patient->patient_uid : ''));
    }

    // -----------------------------------------------------------------------
    // AJAX: Update patient in registry
    // -----------------------------------------------------------------------

    public static function ajax_update_patient()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $id = (int) ($_POST['patient_id'] ?? 0);
        if (!$id)
            wp_send_json_error('Invalid patient ID.');

        Repose_Patient_Registry::upsert(array(
            'id' => $id,
            'forename' => sanitize_text_field($_POST['forename'] ?? ''),
            'surname' => sanitize_text_field($_POST['surname'] ?? ''),
            'dob' => sanitize_text_field($_POST['dob'] ?? ''),
            'sex' => sanitize_text_field($_POST['sex'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        ));

        wp_send_json_success(array('message' => 'Patient updated.'));
    }

    // -----------------------------------------------------------------------
    // AJAX: Assign test to patient (from patient profile page)
    // -----------------------------------------------------------------------

    public static function ajax_assign_patient_test()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $test_name = sanitize_text_field($_POST['test_name'] ?? '');

        if (!$patient_id || !$order_id || !$test_name) {
            wp_send_json_error('Patient ID, Order ID and test name are all required.');
        }

        $pt_id = Repose_Patient_Registry::assign_test($patient_id, $order_id, $test_name);
        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'test_assigned',
            "Test '{$test_name}' assigned to patient #{$patient_id} by " . wp_get_current_user()->display_name
        );

        wp_send_json_success(array('pt_id' => $pt_id, 'message' => 'Test assigned.'));
    }

    // -----------------------------------------------------------------------
    // AJAX: Save amendments then retransmit order to laboratory
    // -----------------------------------------------------------------------

    public static function ajax_retransmit_order()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order)
            wp_send_json_error('Order not found.');

        // Save any amendments first
        $meta_map = array(
            'forename' => '_repose_patient_forename',
            'surname' => '_repose_patient_surname',
            'dob' => '_repose_date_of_birth',
            'sex' => '_repose_sex_at_birth',
        );
        $changes = array();
        foreach ($meta_map as $post_key => $meta_key) {
            $new = sanitize_text_field(wp_unslash($_POST[$post_key] ?? ''));
            $old = (string) $order->get_meta($meta_key);
            if ($new && $new !== $old) {
                $changes[] = ucwords(str_replace('_', ' ', $post_key)) . ': "' . $old . '" → "' . $new . '"';
                $order->update_meta_data($meta_key, $new);
            }
        }
        $note = sanitize_textarea_field($_POST['edit_note'] ?? '');
        if (!empty($changes)) {
            $detail = 'Pre-retransmit amendment by ' . wp_get_current_user()->display_name . ': ' . implode('; ', $changes);
            if ($note)
                $detail .= ' | Note: ' . $note;
            Repose_Audit_Log::record($order_id, get_current_user_id(), 'fields_edited', $detail);
            $order->add_order_note('Repose: ' . $detail, 0, false);
        }
        $order->save();

        // ── Selective patient retransmit ─────────────────────────────────
        // If include_patients is set, temporarily override additional_patients
        // on the order object so only the selected patients appear in the CSV.
        $include_raw = sanitize_text_field($_POST['include_patients'] ?? '');
        $original_additional = null;

        if ($include_raw !== '') {
            $include_nums = array_map('intval', explode(',', $include_raw));
            // include_nums contains 1-based patient numbers (1 = patient 1, 2 = additional[0], etc.)

            $include_p1 = in_array(1, $include_nums, true);

            // Filter additional patients — only keep those whose patient number is in $include_nums
            $original_additional = (array) $order->get_meta('_repose_additional_patients');
            $filtered = array();
            foreach ($original_additional as $i => $ap) {
                $pn = $i + 2; // additional[0] = patient 2, etc.
                if (in_array($pn, $include_nums, true)) {
                    $filtered[] = $ap;
                }
            }

            // If patient 1 is excluded, promote first included additional patient to P1
            if (!$include_p1 && !empty($filtered)) {
                $promoted = array_shift($filtered);
                $order->update_meta_data('_repose_patient_forename', sanitize_text_field($promoted['forename'] ?? ''));
                $order->update_meta_data('_repose_patient_surname', sanitize_text_field($promoted['surname'] ?? ''));
                $order->update_meta_data('_repose_date_of_birth', sanitize_text_field($promoted['dob'] ?? ''));
                $order->update_meta_data('_repose_sex_at_birth', sanitize_text_field($promoted['sex'] ?? ''));
                $order->update_meta_data('_repose_additional_notes', sanitize_text_field($promoted['notes'] ?? ''));
            }

            // Write filtered additional patients temporarily
            $order->update_meta_data('_repose_additional_patients', $filtered);
            $order->save();

            // Log which patients were excluded
            $excluded = array();
            if (!$include_p1)
                $excluded[] = 'Patient 1';
            foreach ($original_additional as $i => $ap) {
                $pn = $i + 2;
                if (!in_array($pn, $include_nums, true)) {
                    $excluded[] = 'Patient ' . $pn . ' (' . trim(($ap['forename'] ?? '') . ' ' . ($ap['surname'] ?? '')) . ')';
                }
            }
            if (!empty($excluded)) {
                $excl_note = 'Selective retransmit: excluded ' . implode(', ', $excluded) . '.';
                $order->add_order_note('Repose: ' . $excl_note, 0, false);
                Repose_Audit_Log::record($order_id, get_current_user_id(), 'fields_edited', $excl_note);
            }
        }
        $ok = Repose_Lab_Transmission::auto_transmit($order);

        // Restore original additional_patients if we temporarily modified them for selective retransmit
        if ($original_additional !== null) {
            $order->update_meta_data('_repose_additional_patients', $original_additional);
            $order->save();
        }

        Repose_Audit_Log::record(
            $order_id,
            get_current_user_id(),
            'manual_transmitted',
            'Retransmitted by ' . wp_get_current_user()->display_name . ($note ? ' | Note: ' . $note : '')
        );

        wp_send_json_success(array(
            'message' => $ok
                ? "Order #{$order_id} amendments saved and retransmitted to laboratory successfully."
                : "Order #{$order_id} amendments saved but transmission failed — check lab settings.",
        ));
    }

    // -----------------------------------------------------------------------
    // AJAX: Search patients by name, UID or email
    // -----------------------------------------------------------------------

    public static function ajax_search_patients()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $term = sanitize_text_field($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            wp_send_json_success(array('patients' => array()));
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}repose_patient_tests pt WHERE pt.patient_id = p.id) AS test_count
             FROM {$wpdb->prefix}repose_patients p
             WHERE p.patient_uid LIKE %s OR p.email LIKE %s
                OR CONCAT(p.forename,' ',p.surname) LIKE %s
                OR p.forename LIKE %s OR p.surname LIKE %s
             ORDER BY p.surname, p.forename LIMIT 30",
            $like,
            $like,
            $like,
            $like,
            $like
        ));

        $patients = array();
        foreach ($rows as $p) {
            $patients[] = array(
                'id' => (int) $p->id,
                'patient_uid' => $p->patient_uid,
                'forename' => $p->forename,
                'surname' => $p->surname,
                'dob' => $p->dob,
                'email' => $p->email,
                'test_count' => (int) $p->test_count,
            );
        }

        wp_send_json_success(array('patients' => $patients));
    }

    // -----------------------------------------------------------------------
    // AJAX: Get all orders for a patient (for the upload-result order selector)
    // -----------------------------------------------------------------------

    public static function ajax_get_patient_orders()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $patient_id = (int) ($_POST['patient_id'] ?? 0);
        if (!$patient_id)
            wp_send_json_error('Invalid patient ID.');

        global $wpdb;
        $tests = $wpdb->get_results($wpdb->prepare(
            "SELECT pt.order_id, pt.test_name, pt.status, pt.assigned_at
             FROM {$wpdb->prefix}repose_patient_tests pt
             WHERE pt.patient_id = %d
             ORDER BY pt.assigned_at DESC",
            $patient_id
        ));

        $orders = array();
        foreach ($tests as $t) {
            $orders[] = array(
                'order_id' => (int) $t->order_id,
                'test_name' => $t->test_name,
                'status' => $t->status,
            );
        }

        wp_send_json_success(array('orders' => $orders));
    }

    // -----------------------------------------------------------------------
    // AJAX: Assign an existing result to a specific patient
    // -----------------------------------------------------------------------

    public static function ajax_assign_result_patient()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $result_id = (int) ($_POST['result_id'] ?? 0);
        $patient_id = (int) ($_POST['patient_id'] ?? 0);

        if (!$result_id || !$patient_id) {
            wp_send_json_error('Result ID and patient ID are required.');
        }

        global $wpdb;

        // Get the result row to find order_id
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d",
            $result_id
        ));
        if (!$result)
            wp_send_json_error('Result not found.');

        // Get patient UID
        $patient = Repose_Patient_Registry::get($patient_id);
        if (!$patient)
            wp_send_json_error('Patient not found.');

        // Save patient UID on the order meta
        $order = wc_get_order($result->order_id);
        if ($order) {
            $order->update_meta_data('_repose_result_patient_uid', $patient->patient_uid);
            $order->save();
        }

        // Mark the patient's test as complete and link result
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}repose_patient_tests
             SET status='complete', result_id=%d
             WHERE patient_id=%d AND order_id=%d AND status='pending'
             LIMIT 1",
            $result_id,
            $patient_id,
            $result->order_id
        ));

        Repose_Audit_Log::record(
            (int) $result->order_id,
            get_current_user_id(),
            'result_assigned',
            "Result #{$result_id} assigned to patient {$patient->patient_uid} by " . wp_get_current_user()->display_name
        );

        wp_send_json_success(array(
            'message' => "Result #{$result_id} assigned to {$patient->forename} {$patient->surname} ({$patient->patient_uid}).",
            'patient_uid' => $patient->patient_uid,
        ));
    }

    // -----------------------------------------------------------------------
    // AJAX: Generate and send batch CSV for all pending transmitted orders
    // -----------------------------------------------------------------------

    public static function ajax_send_batch_csv()
    {
        check_ajax_referer('repose_admin_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce'))
            wp_send_json_error('Unauthorized', 403);

        $result = Repose_Lab_Transmission::generate_and_upload_batch();

        if ($result['ok']) {
            Repose_Audit_Log::record(
                0,
                get_current_user_id(),
                'batch_csv_sent',
                ($result['count'] ?? 0) . ' orders in batch CSV sent by ' . wp_get_current_user()->display_name
            );
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message'] ?? 'Batch failed.');
        }
    }

}