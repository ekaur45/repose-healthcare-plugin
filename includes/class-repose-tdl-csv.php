<?php
if (!defined('ABSPATH'))
    exit;

/**
 * TDL CSV Generator
 *
 * Produces the TDL-format ordering CSV where each row is one patient+test pair.
 * Columns conform to the TDL ordering specification:
 *
 *   OrderReference   | Repose reference number truncated to 10 chars
 *   SourceCode       | Always "REPOSE" (max 10 chars)
 *   PatientID        | Patient UID from registry e.g. RHP-00001 (max 10 chars)
 *   Forename         | A-Z only, max 40 chars
 *   Surname          | A-Z only, max 40 chars
 *   DOB              | DD/MM/YYYY
 *   Sex              | M or F
 *   Address1         | A-Z 0-9 max 40 chars
 *   Address2         | A-Z 0-9 max 40 chars
 *   Address3         | A-Z 0-9 max 40 chars (city)
 *   Postcode         | max 10 chars
 *   Email            | max 100 chars
 *   TDLTestCode      | TDL test code mapped from WC product
 *   CollectionType   | SC (self-collect) or VE (venous)
 *   Notes            | Additional patient notes, max 200 chars
 *
 * Rules:
 * - One row per patient per test (if a patient has 3 tests → 3 rows)
 * - Multi-patient orders produce multiple row groups
 * - All text fields stripped to Latin A-Z 0-9 and basic punctuation
 * - PatientID and OrderReference max 10 characters
 */
class Repose_TDL_CSV
{

    const SOURCE_CODE = 'REPOSE';

    // ── TDL Test Code mapping: WC Product ID → TDL code ──────────────────
    // Admin can configure these in Settings → Laboratory → TDL Test Codes
    // Falls back to the product slug if no mapping is configured.
    public static function get_tdl_code(int $product_id, string $product_name): string
    {
        // Load from option (stored as JSON: {"123":"MRSA1","456":"CTNG2",...})
        $map = json_decode(get_option('repose_tdl_test_codes', '{}'), true);
        if (!empty($map[$product_id])) {
            return strtoupper(self::latin_only($map[$product_id], 20));
        }
        // Fallback: derive from product name
        return strtoupper(self::latin_only($product_name, 20));
    }

    // ── Sanitise to Latin A-Z 0-9 and safe punctuation ───────────────────
    public static function latin_only(string $input, int $max_len = 0): string
    {
        // Transliterate accented characters
        if (function_exists('iconv')) {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
        } else {
            $s = preg_replace('/[^\x00-\x7F]/', '', $input);
        }
        // Keep A-Z a-z 0-9 space hyphen apostrophe dot comma forward-slash
        $s = preg_replace('/[^A-Za-z0-9 \-\'.,\/]/', '', (string) $s);
        $s = trim($s);
        if ($max_len > 0) {
            $s = substr($s, 0, $max_len);
        }
        return $s;
    }

    // ── Truncate reference to max 10 chars ───────────────────────────────
    public static function short_ref(string $ref): string
    {
        // Remove leading R, keep last 9 chars → total 10 with R prefix truncated
        // e.g. R25C290069 = 10 chars exactly → fine
        // if somehow longer, take last 10 chars
        $r = self::latin_only($ref, 10);
        return $r;
    }

    // ── Short patient UID (max 10 chars) ──────────────────────────────────
    public static function short_uid(string $uid): string
    {
        // RHP-00001 = 9 chars, RHP-00010 = 9, RHP-99999 = 9 → always ≤ 10
        return substr(self::latin_only($uid), 0, 10);
    }

    // ── DOB normaliser → DD/MM/YYYY ──────────────────────────────────────
    public static function normalise_dob(string $dob): string
    {
        // Accept YYYY-MM-DD (HTML date input) or DD/MM/YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob)) {
            return $dob;
        }
        return $dob; // return as-is if unexpected format
    }

    // ── Sex code ─────────────────────────────────────────────────────────
    public static function sex_code(string $sex): string
    {
        return strtolower($sex) === 'female' ? 'F' : 'M';
    }

    // ── Collection type ───────────────────────────────────────────────────
    public static function collection_type(string $order_type): string
    {
        return strtolower($order_type) === 'venous' ? 'VE' : 'SC';
    }

    /**
     * Generate CSV rows for a single WC order (all patients, all tests).
     * Returns array of row arrays (not yet written to file).
     */
    public static function rows_for_order(\WC_Order $order): array
    {
        $rows = array();
        $ref = self::short_ref($order->get_meta('_repose_reference_number') ?: '');
        $col_type = self::collection_type($order->get_meta('_repose_order_type') ?: 'SC');
        $email = substr($order->get_billing_email(), 0, 100);
        $phoneNumber = self::latin_only($order->get_billing_phone(), 10);

        // Shipping/billing address
        $addr1 = self::latin_only($order->get_shipping_address_1() ?: $order->get_billing_address_1(), 40);
        $addr2 = self::latin_only($order->get_shipping_address_2() ?: $order->get_billing_address_2(), 40);
        $addr3 = self::latin_only($order->get_shipping_city() ?: $order->get_billing_city(), 40);
        $post = self::latin_only($order->get_shipping_postcode() ?: $order->get_billing_postcode(), 10);

        // ── Build patient list ──────────────────────────────────────────
        $patients = array();

        // Patient 1
        $p1_uid = self::short_uid($order->get_meta('_repose_patient_1_uid') ?: 'RHP-00000');
        $p1_meta = $order->get_meta('_repose_patient_1_tests');
        $p1_tests = is_array($p1_meta) ? $p1_meta : array();
        $p1_items = array(); // fallback: all order items

        foreach ($order->get_items() as $item) {
            $p1_items[] = array('product_id' => $item->get_product_id(), 'name' => $item->get_name());
        }

        $patients[] = array(
            'uid' => $p1_uid,
            'forename' => self::latin_only($order->get_meta('_repose_patient_forename') ?: $order->get_billing_first_name(), 40),
            'surname' => self::latin_only($order->get_meta('_repose_patient_surname') ?: $order->get_billing_last_name(), 40),
            'dob' => self::normalise_dob($order->get_meta('_repose_date_of_birth') ?: ''),
            'sex' => self::sex_code($order->get_meta('_repose_sex_at_birth') ?: 'male'),
            'notes' => self::latin_only($order->get_meta('_repose_additional_notes') ?: '', 200),
            'tests' => !empty($p1_tests) ? $p1_tests : $p1_items,
        );

        // Additional patients (2-5) — must be a real array; (array) '' would yield one bogus '' entry → duplicate CSV rows.
        $add_meta = $order->get_meta('_repose_additional_patients');
        $additional = is_array($add_meta) ? $add_meta : array();
        foreach ($additional as $i => $ap) {
            if (!is_array($ap)) {
                continue;
            }
            $pn = $i + 2;
            $ap_uid = self::short_uid($order->get_meta("_repose_patient_{$pn}_uid") ?: 'RHP-00000');
            $ap_meta = $order->get_meta("_repose_patient_{$pn}_tests");
            $ap_tests = is_array($ap_meta) ? $ap_meta : array();
            $patients[] = array(
                'uid' => $ap_uid,
                'forename' => self::latin_only($ap['forename'] ?? '', 40),
                'surname' => self::latin_only($ap['surname'] ?? '', 40),
                'dob' => self::normalise_dob($ap['dob'] ?? ''),
                'sex' => self::sex_code($ap['sex'] ?? 'male'),
                'notes' => self::latin_only($ap['notes'] ?? '', 200),
                'tests' => !empty($ap_tests) ? $ap_tests : $p1_items,
            );
        }

        // ── One row per patient per test ────────────────────────────────
        foreach ($patients as $patient) {
            $tests = $patient['tests'];
            if (empty($tests)) {
                // No tests assigned — emit one row with empty test code
                $rows[] = self::build_row($ref, $patient, array('product_id' => 0, 'name' => ''), $addr1, $addr2, $addr3, $post, $phoneNumber, $col_type);
            } else {
                // Check collection type
                // get from product meta by product id and key is_self_collect_
                
                $rows[] = self::build_row($ref, $patient, $tests, $addr1, $addr2, $addr3, $post, $phoneNumber, $col_type);
                // foreach ( $tests as $test ) {
                //     if ( ! is_array( $test ) ) {
                //         continue;
                //     }
                //     $rows[] = self::build_row( $ref, $patient, $test, $addr1, $addr2, $addr3, $post, $email, $col_type );
                // }
            }
        }

        return $rows;
    }

    private static function build_row(string $ref, array $patient, array $tests, string $addr1, string $addr2, string $addr3, string $post, string $phoneNumber, string $col_type): array
    {
        $returnArray = array(
            'PatientID' => $patient['uid'],
            'OrderReference' => $ref,
            'Forename' => $patient['forename'],
            'Surname' => $patient['surname'],
            'DOB' => $patient['dob'],
            'Sex' => $patient['sex'],
            'Address1' => $addr1,
            'Address2' => $addr2,
            'Address3' => $addr3,
            'Postcode' => $post,
            'Venous' => $col_type == 'SC' ? 'N' : 'Y',
            'PhoneNumber' => $phoneNumber,
        );

        if(count($tests)==1){
            $product_id = (int) ($tests[0]['product_id'] ?? 0);
            $isSelfCollect = get_post_meta($product_id, '_is_self_collect', true);
            $returnArray['Venous'] = $isSelfCollect === 'yes' ? 'N' : 'Y';
        }
        $__i = 1;
        foreach ($tests as $test) {
            $product_id = (int) ($test['product_id'] ?? 0);
            
            $test_name = $test['name'] ?? '';
            $tdl_code = self::get_tdl_code($product_id, $test_name);
            $returnArray["Test{$__i}"] = $tdl_code;
            $__i++;
        }



        return $returnArray;
    }

    // private static function build_row( string $ref, array $patient, array $test, string $addr1, string $addr2, string $addr3, string $post, string $email, string $col_type ): array {
    //     $product_id = (int) ( $test['product_id'] ?? 0 );
    //     $test_name  = $test['name'] ?? '';
    //     $tdl_code   = self::get_tdl_code( $product_id, $test_name );

    //     return array(
    //         'PatientID'      => $patient['uid'],
    //         'OrderReference' => $ref,
    //         'Forename'       => $patient['forename'],
    //         'Surname'        => $patient['surname'],
    //         'DOB'            => $patient['dob'],
    //         'Sex'            => $patient['sex'],
    //         'Address1'       => $addr1,
    //         'Address2'       => $addr2,
    //         'Address3'       => $addr3,
    //         'Postcode'       => $post,
    //         'Email'          => $email,
    //         'TDLTestCode'    => $tdl_code,
    //         'CollectionType' => $col_type,
    //         'Notes'          => $patient['notes'],
    //         'SourceCode'     => self::SOURCE_CODE,
    //     );
    // }

    /**
     * Generate a batch CSV for multiple orders.
     * Returns the path to the temp file.
     */
    public static function generate_batch(array $order_ids): string
    {
        $file = sys_get_temp_dir() . '/repose_batch_' . date('Ymd_His') . '.csv';
        $fp = fopen($file, 'w');

        $headers_written = false;
        foreach ($order_ids as $oid) {
            $order = wc_get_order((int) $oid);
            if (!$order)
                continue;
            $rows = self::rows_for_order($order);
            foreach ($rows as $row) {
                if (!$headers_written) {
                    fputcsv($fp, array_keys($row));
                    $headers_written = true;
                }
                fputcsv($fp, array_values($row));
            }
        }
        fclose($fp);
        return $file;
    }

    /**
     * Generate a single-order CSV (backwards compatible).
     */
    public static function generate_single(\WC_Order $order, string $ref): string
    {
        return self::generate_batch(array($order->get_id()));
    }

    /**
     * Get all transmitted-but-not-yet-batched order IDs.
     * Used for the "generate batch now" feature.
     */
    public static function get_pending_batch_orders(): array
    {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT l.order_id
             FROM {$wpdb->prefix}repose_audit_log l
             WHERE l.action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta} pm
                 WHERE pm.post_id = l.order_id
                 AND pm.meta_key = '_repose_tdl_batch_sent'
             )
             ORDER BY l.created_at ASC"
        );
    }
}
