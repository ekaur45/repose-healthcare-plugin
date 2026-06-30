<?php
if (!defined('ABSPATH'))
    exit;

class Repose_Lab_Transmission
{

    public static function init()
    {
        // Trigger processing when WooCommerce payment is complete
        add_action('woocommerce_payment_complete', array(__CLASS__, 'process_order'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'process_order'));
    }

    /**
     * Entry point: validate → classify → route.
     */
    public static function process_order(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;

        // Prevent double-processing
        if ($order->get_meta('_repose_processed') === 'yes')
            return;

        $validation = Repose_Order_Validator::validate($order);

        if (!$validation['valid']) {
            self::flag_incomplete($order, $validation['missing']);
            return;
        }

        $auto_transmit_on = get_option('repose_auto_transmit', '0') === '1';

        // When auto-transmit is ON — skip anomaly/duplicate checks and transmit immediately
        $tests = Repose_Order_Validator::get_tests($order);
        if ($auto_transmit_on) {
            if (count($tests) > 1) {
            } else {
                $product_id = null;
                foreach ($order->get_items() as $item_id => $item) {
                    $product_id = $item->get_product_id();
                }
                if (self::is_auto_transmittable($product_id)) {
                    self::queue_for_auto_auth($order, 'Auto-transmit is enabled — order meets criteria for auto approval.');
                    self::auto_transmit($order);
                    self::close_queue_item($order_id, 'approved');
                    Repose_Audit_Log::record(
                        $order_id,
                        get_current_user_id(),
                        'auto_approved',
                        'Approved and transmitted by ' . wp_get_current_user()->display_name
                    );
                    return;
                } else {
                    return;
                }
            }
        }
        if (count($tests) > 1) {
        } else {
            $product_id = null;
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
            }
            if (self::is_auto_transmittable($product_id)) {
            } else {
                return;
            }
        }
        // Auto-transmit is OFF — run anomaly checks then queue everything for manual auth
        $anomalies = Repose_Order_Validator::detect_anomalies($order);
        $tests = Repose_Order_Validator::get_tests($order);

        if (!empty($anomalies) || count($tests) > 1) {
            $reason = implode('; ', $anomalies);
            self::queue_for_manual_auth($order, $reason);
            return;
        }

        self::queue_for_manual_auth($order, 'Auto-transmit is disabled — manual authorisation required.');
    }
    // $product_id is the ID of the WooCommerce product
    private static function is_auto_transmittable($product_id): bool
    {

        if (!$product_id) {
            return false;
        }
        // get _not_transfer_product from metadata of the product
        $not_transfer = get_post_meta($product_id, '_not_transfer_product', true);
        return $not_transfer !== 'yes';
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
    // Auto transmission
    // -----------------------------------------------------------------------

    public static function auto_transmit(WC_Order $order): bool
    {
        $ref = Repose_Reference::generate($order->get_id());
        $order->update_meta_data('_repose_reference_number', $ref);
        $order->update_meta_data('_repose_processed', 'yes');
        $order->update_meta_data('_repose_order_type', Repose_Order_Validator::classify($order));
        $order->save();

        // Generate TDL-format CSV using the TDL CSV generator
        $csv_path = class_exists('Repose_TDL_CSV')
            ? Repose_TDL_CSV::generate_single($order, $ref)
            : self::generate_csv($order, $ref);

        $method = get_option('repose_transmission_method', 'email');

        if ($method === 'azure') {
            $ok = self::upload_to_azure($csv_path);
        } else {
            $ok = self::send_csv_email($csv_path, $ref);
        }

        // Mark order as included in a batch
        $order->update_meta_data('_repose_tdl_batch_sent', current_time('mysql'));
        $order->save();

        Repose_Audit_Log::record(
            $order->get_id(),
            get_current_user_id() ?: 0,
            'auto_transmitted',
            "Reference: {$ref}, Method: {$method}, Success: " . ($ok ? 'yes' : 'no')
        );

        $order->add_order_note(sprintf(
            __('Repose: Auto-transmitted to lab. Reference: %s', 'repose-healthcare'),
            $ref
        ));

        // Clean up temp CSV
        @unlink($csv_path);

        return $ok;
    }

    // -----------------------------------------------------------------------
    // CSV generation (legacy fallback — use Repose_TDL_CSV::generate_single() instead)
    // -----------------------------------------------------------------------

    public static function generate_csv(WC_Order $order, string $ref): string
    {
        if (class_exists('Repose_TDL_CSV')) {
            return Repose_TDL_CSV::generate_single($order, $ref);
        }
        // Legacy simple CSV
        $tests = Repose_Order_Validator::get_tests($order);
        $row = array(
            'SourceCode' => 'REPOSE',
            'PatientID' => substr($order->get_meta('_repose_patient_1_uid') ?: 'RHP-00000', 0, 10),
            'OrderReference' => substr($ref, 0, 10),
            'Forename' => substr($order->get_meta('_repose_patient_forename') ?: $order->get_billing_first_name(), 0, 40),
            'Surname' => substr($order->get_meta('_repose_patient_surname') ?: $order->get_billing_last_name(), 0, 40),
            'DOB' => $order->get_meta('_repose_date_of_birth'),
            'Sex' => strtolower($order->get_meta('_repose_sex_at_birth')) === 'female' ? 'F' : 'M',
            'Email' => $order->get_billing_email(),
            'TDLTestCode' => $tests[0] ?? '',
        );
        $file = sys_get_temp_dir() . "/repose_{$ref}.csv";
        $fp = fopen($file, 'w');
        fputcsv($fp, array_keys($row));
        fputcsv($fp, array_values($row));
        fclose($fp);
        return $file;
    }

    // -----------------------------------------------------------------------
    // Batch upload: generate one CSV with all un-sent transmitted orders
    // -----------------------------------------------------------------------

    public static function generate_and_upload_batch(): array
    {
        if (!class_exists('Repose_TDL_CSV')) {
            return array('ok' => false, 'message' => 'TDL CSV class not loaded.');
        }

        $order_ids = Repose_TDL_CSV::get_pending_batch_orders();
        if (empty($order_ids)) {
            return array('ok' => true, 'message' => 'No pending orders to batch.', 'count' => 0);
        }

        $csv_path = Repose_TDL_CSV::generate_batch($order_ids);
        $method = get_option('repose_transmission_method', 'email');
        $batch_ref = 'BATCH-' . date('Ymd-His');

        if ($method === 'azure') {
            $ok = self::upload_to_azure($csv_path, $batch_ref . '.csv');
        } else {
            $ok = self::send_csv_email($csv_path, $batch_ref);
        }

        if ($ok) {
            // Mark all included orders as sent
            global $wpdb;
            foreach ($order_ids as $oid) {
                $o = wc_get_order((int) $oid);
                if ($o) {
                    $o->update_meta_data('_repose_tdl_batch_sent', current_time('mysql'));
                    $o->save();
                }
            }
        }

        @unlink($csv_path);

        return array(
            'ok' => $ok,
            'count' => count($order_ids),
            'message' => $ok
                ? count($order_ids) . ' orders included in batch CSV sent via ' . $method . '.'
                : 'Batch generated but transmission failed — check settings.',
        );
    }

    // -----------------------------------------------------------------------
    // Email transmission
    // -----------------------------------------------------------------------

    private static function send_csv_email(string $csv_path, string $ref): bool
    {
        $to = get_option('repose_lab_email', '');
        $subject = sprintf('[Repose Healthcare] Lab Order — %s', $ref);
        $message = "Please find attached the laboratory order {$ref} from Repose Healthcare.";

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        return wp_mail($to, $subject, $message, $headers, array($csv_path));
    }

    // -----------------------------------------------------------------------
    // Azure Blob Storage upload
    // -----------------------------------------------------------------------

    private static function upload_to_azure(string $csv_path, string $blob_name = ''): bool
    {
        try {
            $account = get_option('repose_azure_account', '');
            // Use requests container for outbound CSV orders
            $container = get_option('repose_azure_requests_container', get_option('repose_azure_container', 'requests'));
            $sas_token = get_option('repose_azure_sas_token', '');

            if (!$account || !$container || !$sas_token) {
                error_log('Repose Healthcare: Azure credentials not configured.');
                return false;
            }
            $connectionString = "DefaultEndpointsProtocol=https;AccountName=$account;AccountKey=$sas_token;EndpointSuffix=core.windows.net";
            $blobClient = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($connectionString);
            if (!$blob_name) {
                $blob_name = basename($csv_path);
            }
            $body = file_get_contents($csv_path);
            $blobClient->createBlockBlob(
                $container,
                $blob_name,
                $body
            );

            return true;
        } catch (\Throwable $th) {
            error_log('Repose Healthcare: Error uploading to Azure — ' . $th->getMessage());
            throw $th;
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Queue helpers
    // -----------------------------------------------------------------------

    public static function flag_incomplete(WC_Order $order, array $missing)
    {
        global $wpdb;
        $order->update_status('on-hold', __('Repose: Incomplete patient data — ' . implode(', ', $missing), 'repose-healthcare'));
        $order->update_meta_data('_repose_incomplete_fields', implode(', ', $missing));
        $order->save();

        $wpdb->insert($wpdb->prefix . 'repose_order_queue', array(
            'order_id' => $order->get_id(),
            'queue_type' => 'incomplete',
            'flag_reason' => 'Missing fields: ' . implode(', ', $missing),
            'status' => 'pending',
        ));
    }

    public static function queue_for_auto_auth(WC_Order $order, string $reason)
    {
        global $wpdb;
        $order->update_status('on-hold', __('Repose: Auto authorisation required — ' . $reason, 'repose-healthcare'));
        $order->update_meta_data('_repose_auto_auth_reason', $reason);
        $order->update_meta_data('_repose_processed', 'yes');  // prevent double-processing
        $order->save();

        $wpdb->insert($wpdb->prefix . 'repose_order_queue', array(
            'order_id' => $order->get_id(),
            'queue_type' => 'auto_auth',
            'flag_reason' => $reason,
            'status' => 'pending',
        ));
    }


    public static function queue_for_manual_auth(WC_Order $order, string $reason)
    {
        global $wpdb;
        $order->update_status('on-hold', __('Repose: Manual authorisation required — ' . $reason, 'repose-healthcare'));
        $order->update_meta_data('_repose_manual_auth_reason', $reason);
        $order->update_meta_data('_repose_processed', 'yes');  // prevent double-processing
        $order->save();

        $wpdb->insert($wpdb->prefix . 'repose_order_queue', array(
            'order_id' => $order->get_id(),
            'queue_type' => 'manual_auth',
            'flag_reason' => $reason,
            'status' => 'pending',
        ));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function format_address(WC_Order $order): string
    {
        $parts = array_filter(array(
            $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
            $order->get_shipping_city() ?: $order->get_billing_city(),
            $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            $order->get_shipping_country() ?: $order->get_billing_country(),
        ));
        return implode(', ', $parts);
    }
}
