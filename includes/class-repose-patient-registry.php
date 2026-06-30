<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Repose Patient Registry
 * Manages the central patient database — unique patient IDs, test assignments, history.
 */
class Repose_Patient_Registry {

    // -----------------------------------------------------------------------
    // Generate unique patient UID: RHP-00001
    // -----------------------------------------------------------------------

    public static function generate_uid(): string {
        global $wpdb;
        $table = $wpdb->prefix . 'repose_patient_uid_counter';
        $wpdb->query( "UPDATE {$table} SET counter = counter + 1 WHERE id = 1" );
        $counter = (int) $wpdb->get_var( "SELECT counter FROM {$table} WHERE id = 1" );
        return 'RHP-' . str_pad( $counter, 5, '0', STR_PAD_LEFT );
    }

    // -----------------------------------------------------------------------
    // Create or update a patient record
    // -----------------------------------------------------------------------

    public static function upsert( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'repose_patients';

        // If existing patient_id provided, update
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, array(
                'forename'   => sanitize_text_field( $data['forename'] ?? '' ),
                'surname'    => sanitize_text_field( $data['surname']  ?? '' ),
                'dob'        => sanitize_text_field( $data['dob']      ?? '' ),
                'sex'        => sanitize_text_field( $data['sex']      ?? '' ),
                'email'      => sanitize_email( $data['email']         ?? '' ),
                'phone'      => sanitize_text_field( $data['phone']    ?? '' ),
                'notes'      => sanitize_textarea_field( $data['notes'] ?? '' ),
                'wc_user_id' => ! empty( $data['wc_user_id'] ) ? (int) $data['wc_user_id'] : null,
            ), array( 'id' => (int) $data['id'] ) );
            return (int) $data['id'];
        }

        // Check for existing match by email+dob+name (avoid duplicates)
        if ( ! empty( $data['email'] ) && ! empty( $data['dob'] ) ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE email = %s AND dob = %s AND forename = %s AND surname = %s LIMIT 1",
                sanitize_email( $data['email'] ),
                sanitize_text_field( $data['dob'] ),
                sanitize_text_field( $data['forename'] ?? '' ),
                sanitize_text_field( $data['surname']  ?? '' )
            ) );
            if ( $existing ) return (int) $existing;
        }

        // Insert new
        $uid = self::generate_uid();
        $wpdb->insert( $table, array(
            'patient_uid' => $uid,
            'forename'    => sanitize_text_field( $data['forename'] ?? '' ),
            'surname'     => sanitize_text_field( $data['surname']  ?? '' ),
            'dob'         => sanitize_text_field( $data['dob']      ?? '' ),
            'sex'         => sanitize_text_field( $data['sex']      ?? '' ),
            'email'       => sanitize_email( $data['email']         ?? '' ),
            'phone'       => sanitize_text_field( $data['phone']    ?? '' ),
            'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
            'wc_user_id'  => ! empty( $data['wc_user_id'] ) ? (int) $data['wc_user_id'] : null,
        ) );
        return (int) $wpdb->insert_id;
    }

    // -----------------------------------------------------------------------
    // Get patient by ID
    // -----------------------------------------------------------------------

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_patients WHERE id = %d", $id
        ) ) ?: null;
    }

    public static function get_by_uid( string $uid ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_patients WHERE patient_uid = %s", $uid
        ) ) ?: null;
    }

    // -----------------------------------------------------------------------
    // Search patients
    // -----------------------------------------------------------------------

    public static function search( string $term, int $limit = 20 ): array {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $term ) . '%';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_patients
             WHERE patient_uid LIKE %s OR forename LIKE %s OR surname LIKE %s OR email LIKE %s
             ORDER BY surname, forename LIMIT %d",
            $like, $like, $like, $like, $limit
        ) ) ?: array();
    }

    public static function search_by_email( string $email ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_patients WHERE email = %s ORDER BY surname, forename",
            sanitize_email( $email )
        ) ) ?: array();
    }

    // -----------------------------------------------------------------------
    // Assign test to patient+order
    // -----------------------------------------------------------------------

    public static function assign_test( int $patient_id, int $order_id, string $test_name, int $product_id = 0 ): int {
        global $wpdb;
        // Avoid duplicate
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}repose_patient_tests
             WHERE patient_id = %d AND order_id = %d AND test_name = %s LIMIT 1",
            $patient_id, $order_id, $test_name
        ) );
        if ( $existing ) return (int) $existing;

        $wpdb->insert( $wpdb->prefix . 'repose_patient_tests', array(
            'patient_id' => $patient_id,
            'order_id'   => $order_id,
            'test_name'  => sanitize_text_field( $test_name ),
            'product_id' => $product_id ?: null,
            'status'     => 'pending',
        ) );
        return (int) $wpdb->insert_id;
    }

    // -----------------------------------------------------------------------
    // Get all tests for a patient
    // -----------------------------------------------------------------------

    public static function get_patient_tests( int $patient_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT pt.*, p.forename, p.surname, p.patient_uid
             FROM {$wpdb->prefix}repose_patient_tests pt
             JOIN {$wpdb->prefix}repose_patients p ON p.id = pt.patient_id
             WHERE pt.patient_id = %d
             ORDER BY pt.assigned_at DESC",
            $patient_id
        ) ) ?: array();
    }

       // -----------------------------------------------------------------------
    // Get all tests for a patient
    // -----------------------------------------------------------------------

    public static function get_patient_tests_by_uid( int $uid ): array {
        $patient = self::get_by_uid( $uid );
        if ( ! $patient ) {
            return array();
        }
        return self::get_patient_tests( $patient->id );
    }

    // -----------------------------------------------------------------------
    // Get all tests for an order (all patients on that order)
    // -----------------------------------------------------------------------

    public static function get_order_patient_tests( int $order_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT pt.*, p.forename, p.surname, p.patient_uid, p.dob, p.sex, p.email
             FROM {$wpdb->prefix}repose_patient_tests pt
             JOIN {$wpdb->prefix}repose_patients p ON p.id = pt.patient_id
             WHERE pt.order_id = %d
             ORDER BY p.surname, p.forename, pt.test_name",
            $order_id
        ) ) ?: array();
    }

    // -----------------------------------------------------------------------
    // List all patients (paginated)
    // -----------------------------------------------------------------------

    public static function list_patients( int $page = 1, int $per_page = 25, string $search = '' ): array {
        global $wpdb;
        $offset = ( $page - 1 ) * $per_page;

        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}repose_patient_tests pt WHERE pt.patient_id = p.id) AS test_count,
                    (SELECT MAX(pt2.assigned_at) FROM {$wpdb->prefix}repose_patient_tests pt2 WHERE pt2.patient_id = p.id) AS last_test_at
                 FROM {$wpdb->prefix}repose_patients p
                 WHERE p.patient_uid LIKE %s OR p.forename LIKE %s OR p.surname LIKE %s OR p.email LIKE %s
                 ORDER BY p.updated_at DESC LIMIT %d OFFSET %d",
                $like, $like, $like, $like, $per_page, $offset
            ) );
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}repose_patients p
                 WHERE p.patient_uid LIKE %s OR p.forename LIKE %s OR p.surname LIKE %s OR p.email LIKE %s",
                $like, $like, $like, $like
            ) );
        } else {
            $rows  = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}repose_patient_tests pt WHERE pt.patient_id = p.id) AS test_count,
                    (SELECT MAX(pt2.assigned_at) FROM {$wpdb->prefix}repose_patient_tests pt2 WHERE pt2.patient_id = p.id) AS last_test_at
                 FROM {$wpdb->prefix}repose_patients p
                 ORDER BY p.updated_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ) );
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}repose_patients" );
        }

        return array( 'rows' => $rows ?: array(), 'total' => $total );
    }

    // -----------------------------------------------------------------------
    // Auto-register patients from a WC order (called after order is placed)
    // -----------------------------------------------------------------------

    public static function sync_from_order( \WC_Order $order ): void {
        $email     = $order->get_billing_email();
        $wc_user   = $order->get_user_id() ?: null;
        $order_id  = $order->get_id();

        // Available tests (product names) from order
        $all_tests = array();
        foreach ( $order->get_items() as $item ) {
            $all_tests[] = array( 'name' => $item->get_name(), 'product_id' => $item->get_product_id(), 'qty' => $item->get_quantity() );
        }

        // Patient 1
        $p1_id = self::upsert( array(
            'forename'   => $order->get_meta( '_repose_patient_forename' ) ?: $order->get_billing_first_name(),
            'surname'    => $order->get_meta( '_repose_patient_surname' )  ?: $order->get_billing_last_name(),
            'dob'        => $order->get_meta( '_repose_date_of_birth' ),
            'sex'        => $order->get_meta( '_repose_sex_at_birth' ),
            'email'      => $email,
            'notes'      => $order->get_meta( '_repose_additional_notes' ),
            'wc_user_id' => $wc_user,
        ) );

        // Assign tests to patient 1 — use per-patient tests if stored, else all order tests
        $p1_tests = $order->get_meta( '_repose_patient_1_tests' );
        if ( ! empty( $p1_tests ) && is_array( $p1_tests ) ) {
            foreach ( $p1_tests as $t ) {
                self::assign_test( $p1_id, $order_id, $t['name'], $t['product_id'] ?? 0 );
            }
        } else {
            foreach ( $all_tests as $t ) {
                self::assign_test( $p1_id, $order_id, $t['name'], $t['product_id'] );
            }
        }

        // Save patient_uid back to order meta for reference
        $p1 = self::get( $p1_id );
        if ( $p1 ) {
            $order->update_meta_data( '_repose_patient_1_uid', $p1->patient_uid );
        }

        // Additional patients 2-5
        $additional = (array) $order->get_meta( '_repose_additional_patients' );
        foreach ( $additional as $idx => $ap ) {
            $pn = $idx + 2;
            $pid = self::upsert( array(
                'forename'   => $ap['forename'] ?? '',
                'surname'    => $ap['surname']  ?? '',
                'dob'        => $ap['dob']      ?? '',
                'sex'        => $ap['sex']      ?? '',
                'email'      => $email,
                'notes'      => $ap['notes']    ?? '',
                'wc_user_id' => $wc_user,
            ) );

            // Per-patient tests or fallback to all
            $pn_tests = $order->get_meta( "_repose_patient_{$pn}_tests" );
            if ( ! empty( $pn_tests ) && is_array( $pn_tests ) ) {
                foreach ( $pn_tests as $t ) {
                    self::assign_test( $pid, $order_id, $t['name'], $t['product_id'] ?? 0 );
                }
            } else {
                foreach ( $all_tests as $t ) {
                    self::assign_test( $pid, $order_id, $t['name'], $t['product_id'] );
                }
            }

            $pobj = self::get( $pid );
            if ( $pobj ) {
                $order->update_meta_data( "_repose_patient_{$pn}_uid", $pobj->patient_uid );
            }
        }

        $order->save();
    }
}
