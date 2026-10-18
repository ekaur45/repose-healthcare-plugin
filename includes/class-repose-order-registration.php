<?php
if (!defined('ABSPATH'))
    exit;

class Repose_Order_Registration
{
    const HOOK = 'repose_register_order_with_middleware';

    public static function init()
    {
        add_action(self::HOOK, array(__CLASS__, 'send'), 10, 2);
    }

    public static function schedule_for_order(WC_Order $order, string $ref)
    {
        if (!class_exists('Repose_TDL_CSV')) {
            return;
        }

        $rows = Repose_TDL_CSV::rows_for_order($order);
        foreach ($rows as $row) {
            $payload = self::row_to_payload($row, $ref);
            wp_schedule_single_event(time(), self::HOOK, array($order->get_id(), $payload));
        }
    }

    private static function row_to_payload(array $row, string $ref): array
    {
        $tests = array();
        foreach ($row as $key => $value) {
            if (preg_match('/^Test\d+$/', $key) && $value !== '') {
                $tests[] = $value;
            }
        }

        return array(
            'patientId' => $row['PatientID'] ?? '',
            'orderReference' => $row['OrderReference'] ?? $ref,
            'forename' => $row['Forename'] ?? '',
            'surname' => $row['Surname'] ?? '',
            'dob' => $row['DOB'] ?? '',
            'sex' => $row['Sex'] ?? '',
            'address1' => $row['Address1'] ?? '',
            'address2' => $row['Address2'] ?? '',
            'address3' => $row['Address3'] ?? '',
            'postcode' => $row['Postcode'] ?? '',
            'venous' => $row['Venous'] ?? '',
            'phoneNumber' => $row['PhoneNumber'] ?? '',
            'test1' => implode(', ', $tests),
        );
    }

    public static function send(int $order_id, array $payload)
    {
        $ref = $payload['orderReference'] ?? '';
        $patient = $payload['patientId'] ?? '';

        $api_url = get_option('API_URL', 'http://35.178.62.52/api');
        $api_username = get_option('API_USERNAME', 'admin@admin.com');
        $api_password = get_option('API_PASSWORD', 'admin');

        if (!$api_url || !$api_username || !$api_password) {
            self::fail($order_id, $ref, $patient, 'API credentials not configured (API_URL/API_USERNAME/API_PASSWORD).');
            return;
        }

        $auth_url = rtrim($api_url, '/') . '/auth/login';
        $auth_response = wp_remote_post($auth_url, array(
            'body' => array('email' => $api_username, 'password' => $api_password),
            'timeout' => 15,
        ));

        if (is_wp_error($auth_response)) {
            self::fail($order_id, $ref, $patient, 'Auth request failed — ' . $auth_response->get_error_message());
            return;
        }

        $auth_data = json_decode(wp_remote_retrieve_body($auth_response), true);
        $access_token = $auth_data['data']['access_token'] ?? '';

        if (!$access_token) {
            self::fail($order_id, $ref, $patient, 'Auth returned no access_token. HTTP ' . wp_remote_retrieve_response_code($auth_response));
            return;
        }

        $register_url = rtrim($api_url, '/') . '/hl7/register-order';
        $response = wp_remote_post($register_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            self::fail($order_id, $ref, $patient, 'Register-order request failed — ' . $response->get_error_message());
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            self::fail($order_id, $ref, $patient, "Register-order returned HTTP {$status} — " . wp_remote_retrieve_body($response));
            return;
        }

        if (class_exists('Repose_Audit_Log')) {
            Repose_Audit_Log::record($order_id, 0, 'order_registered', "Reference: {$ref}, Patient: {$patient}");
        }
    }

    private static function fail(int $order_id, string $ref, string $patient, string $reason)
    {
        error_log("Repose: order registration failed for order {$order_id} (ref {$ref}) — {$reason}");
        if (class_exists('Repose_Audit_Log')) {
            Repose_Audit_Log::record($order_id, 0, 'order_registration_failed', "Reference: {$ref}, Patient: {$patient} — {$reason}");
        }
    }
}
