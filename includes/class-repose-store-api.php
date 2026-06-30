<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_Store_API {

    const SESSION_KEY = 'repose_patient_fields';

    public static function init() {
        // AJAX: save patient fields to WC session as user types (guest + logged-in)
        add_action( 'wp_ajax_repose_save_patient_session',        array( __CLASS__, 'ajax_save_session' ) );
        add_action( 'wp_ajax_nopriv_repose_save_patient_session', array( __CLASS__, 'ajax_save_session' ) );

        // Block Checkout: read from session and write to order meta
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array( __CLASS__, 'save_to_order' ), 10, 2
        );

        // Classic checkout: save from POST
        add_action( 'woocommerce_checkout_create_order',      array( __CLASS__, 'classic_save' ), 10, 2 );
        add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'classic_save_meta' ) );
    }

    // -----------------------------------------------------------------------
    // AJAX: store ALL patient fields in WC session (supports multi-patient)
    // -----------------------------------------------------------------------

    public static function ajax_save_session() {
        if ( ! check_ajax_referer( 'repose_checkout_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }

        if ( ! function_exists('WC') || ! WC()->session ) {
            wp_send_json_error( 'No session', 500 );
        }

        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }

        // Read patient count (1–5); default 1
        $patient_count = max( 1, min( 5, (int) ( $_POST['repose_patient_count'] ?? 1 ) ) );

        $data = array(
            'repose_patient_count' => $patient_count,
        );

        // Patient 1 — no suffix
        $data['repose_patient_forename'] = sanitize_text_field( wp_unslash( $_POST['repose_patient_forename'] ?? '' ) );
        $data['repose_patient_surname']  = sanitize_text_field( wp_unslash( $_POST['repose_patient_surname']  ?? '' ) );
        $data['repose_sex_at_birth']     = sanitize_text_field( wp_unslash( $_POST['repose_sex_at_birth']     ?? '' ) );
        $data['repose_date_of_birth']    = sanitize_text_field( wp_unslash( $_POST['repose_date_of_birth']    ?? '' ) );
        $data['repose_additional_notes'] = sanitize_textarea_field( wp_unslash( $_POST['repose_additional_notes'] ?? '' ) );
        $data['repose_patient_tests']    = self::sanitize_test_ids( $_POST['repose_patient_tests'] ?? '' );

        // Additional patients 2–5 (suffix _2, _3, _4, _5)
        for ( $i = 2; $i <= 5; $i++ ) {
            $suffix = '_' . $i;
            $data[ 'repose_patient_forename' . $suffix ] = sanitize_text_field( wp_unslash( $_POST[ 'repose_patient_forename' . $suffix ] ?? '' ) );
            $data[ 'repose_patient_surname'  . $suffix ] = sanitize_text_field( wp_unslash( $_POST[ 'repose_patient_surname'  . $suffix ] ?? '' ) );
            $data[ 'repose_sex_at_birth'     . $suffix ] = sanitize_text_field( wp_unslash( $_POST[ 'repose_sex_at_birth'     . $suffix ] ?? '' ) );
            $data[ 'repose_date_of_birth'    . $suffix ] = sanitize_text_field( wp_unslash( $_POST[ 'repose_date_of_birth'    . $suffix ] ?? '' ) );
            $data[ 'repose_additional_notes' . $suffix ] = sanitize_textarea_field( wp_unslash( $_POST[ 'repose_additional_notes' . $suffix ] ?? '' ) );
            $data[ 'repose_patient_tests'    . $suffix ] = self::sanitize_test_ids( $_POST[ 'repose_patient_tests' . $suffix ] ?? '' );
        }

        WC()->session->set( self::SESSION_KEY, $data );
        wp_send_json_success( array( 'saved' => true, 'count' => $patient_count ) );
    }

    // -----------------------------------------------------------------------
    // Block Checkout: read session → save all patient fields to order meta
    // -----------------------------------------------------------------------

    public static function save_to_order( \WC_Order $order, \WP_REST_Request $request ) {
        $saved = false;

        // Source 1: WC session (populated by our AJAX handler on each keystroke)
        if ( function_exists('WC') && WC()->session ) {
            $session = WC()->session->get( self::SESSION_KEY, array() );
            if ( is_array( $session ) && ! empty( array_filter( $session ) ) ) {
                self::write_meta( $order, $session );
                WC()->session->__unset( self::SESSION_KEY );
                $saved = true;
            }
        }

        // Source 2: extensions payload (future-proof)
        if ( ! $saved ) {
            $extensions = $request->get_param( 'extensions' );
            if ( is_array( $extensions ) && ! empty( $extensions['repose-healthcare'] ) ) {
                self::write_meta( $order, $extensions['repose-healthcare'] );
                $saved = true;
            }
        }

        // Source 3: WC native additional_fields (WC 8.9+)
        if ( ! $saved ) {
            $additional = $request->get_param( 'additional_fields' );
            if ( is_array( $additional ) ) {
                $native = array(
                    'repose-healthcare/patient-forename' => 'repose_patient_forename',
                    'repose-healthcare/patient-surname'  => 'repose_patient_surname',
                    'repose-healthcare/sex-at-birth'     => 'repose_sex_at_birth',
                    'repose-healthcare/date-of-birth'    => 'repose_date_of_birth',
                );
                $mapped = array();
                foreach ( $native as $api_key => $data_key ) {
                    if ( ! empty( $additional[ $api_key ] ) ) {
                        $mapped[ $data_key ] = $additional[ $api_key ];
                    }
                }
                if ( ! empty( $mapped ) ) {
                    self::write_meta( $order, $mapped );
                    $saved = true;
                }
            }
        }

        $order->save();
    }

    // -----------------------------------------------------------------------
    // Classic Checkout fallback
    // -----------------------------------------------------------------------

    public static function classic_save( \WC_Order $order, array $data ) {
        self::write_meta( $order, $_POST );
    }

    public static function classic_save_meta( int $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        self::write_meta( $order, $_POST );
        $order->save();
    }

    // Public wrapper so classic checkout can call write_meta
    public static function write_meta_public( \WC_Order $order, array $source ): void {
        self::write_meta( $order, $source );
    }

    // -----------------------------------------------------------------------
    // Shared helper: write patient meta from source array
    // Handles patient 1 (no suffix) + patients 2–5 (_2 ... _5)
    // -----------------------------------------------------------------------

    private static function write_meta( \WC_Order $order, array $source ) {
        // Patient 1 — no suffix
        $map = array(
            'repose_patient_forename' => '_repose_patient_forename',
            'repose_patient_surname'  => '_repose_patient_surname',
            'repose_sex_at_birth'     => '_repose_sex_at_birth',
            'repose_date_of_birth'    => '_repose_date_of_birth',
            'repose_additional_notes' => '_repose_additional_notes',
        );
        foreach ( $map as $key => $meta_key ) {
            if ( isset( $source[ $key ] ) && trim( (string) $source[ $key ] ) !== '' ) {
                $order->update_meta_data( $meta_key, sanitize_text_field( (string) $source[ $key ] ) );
            }
        }

        // Patient 1 test assignments
        $p1_tests = self::sanitize_test_ids( $source['repose_patient_tests'] ?? '' );
        if ( ! empty( $p1_tests ) ) {
            $p1_test_data = array();
            foreach ( $p1_tests as $pid ) {
                $product        = wc_get_product( $pid );
                $p1_test_data[] = array(
                    'product_id' => $pid,
                    'name'       => $product ? $product->get_name() : '',
                );
            }
            $order->update_meta_data( '_repose_patient_1_tests', $p1_test_data );
        }

        // Patient count
        $count = (int) ( $source['repose_patient_count'] ?? 1 );
        if ( $count > 1 ) {
            $order->update_meta_data( '_repose_patient_count', $count );
        }

        // Additional patients 2–5
        $additional = array();
        for ( $i = 2; $i <= 5; $i++ ) {
            $suffix   = '_' . $i;
            $forename = sanitize_text_field( (string) ( $source[ 'repose_patient_forename' . $suffix ] ?? '' ) );
            $surname  = sanitize_text_field( (string) ( $source[ 'repose_patient_surname'  . $suffix ] ?? '' ) );
            if ( ! $forename && ! $surname ) continue;

            // Test assignments for additional patient
            $ap_tests     = self::sanitize_test_ids( $source[ 'repose_patient_tests' . $suffix ] ?? '' );
            $ap_test_data = array();
            foreach ( $ap_tests as $pid ) {
                $product        = wc_get_product( $pid );
                $ap_test_data[] = array(
                    'product_id' => $pid,
                    'name'       => $product ? $product->get_name() : '',
                );
            }

            $additional[] = array(
                'forename' => $forename,
                'surname'  => $surname,
                'sex'      => sanitize_text_field( (string) ( $source[ 'repose_sex_at_birth'     . $suffix ] ?? '' ) ),
                'dob'      => sanitize_text_field( (string) ( $source[ 'repose_date_of_birth'    . $suffix ] ?? '' ) ),
                'notes'    => sanitize_textarea_field( (string) ( $source[ 'repose_additional_notes' . $suffix ] ?? '' ) ),
                'tests'    => $ap_test_data,
            );

            // Also save as standalone meta so TDL CSV and order-retrieval can read it directly
            if ( ! empty( $ap_test_data ) ) {
                $order->update_meta_data( "_repose_patient_{$i}_tests", $ap_test_data );
            }
        }
        if ( ! empty( $additional ) ) {
            $order->update_meta_data( '_repose_additional_patients', $additional );
        }
    }

    // -----------------------------------------------------------------------
    // Helper: parse and sanitize test product IDs from JSON string or array
    // -----------------------------------------------------------------------

    private static function sanitize_test_ids( $raw ): array {
        if ( is_array( $raw ) ) {
            $ids = $raw;
        } elseif ( is_string( $raw ) && ! empty( $raw ) ) {
            $decoded = json_decode( stripslashes( $raw ), true );
            $ids     = is_array( $decoded ) ? $decoded : array();
        } else {
            return array();
        }
        return array_values( array_filter( array_map( 'intval', $ids ) ) );
    }

    // -----------------------------------------------------------------------
    // Validation — runs AFTER save_to_order, only on Place Order
    // -----------------------------------------------------------------------

    public static function validate_fields( \WC_Order $order ) {
        $required = array(
            '_repose_patient_forename' => 'Patient Forename',
            '_repose_patient_surname'  => 'Patient Surname',
            '_repose_sex_at_birth'     => 'Sex at Birth',
            '_repose_date_of_birth'    => 'Date of Birth',
        );

        $missing = array();
        foreach ( $required as $meta_key => $label ) {
            if ( empty( $order->get_meta( $meta_key ) ) ) {
                $missing[] = $label;
            }
        }

        if ( empty( $missing ) ) return;

        $message = 'Please complete the Patient Information section: ' . implode( ', ', $missing ) . '.';

        if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'repose_missing_patient_fields', $message, 400
            );
        }

        throw new \Exception( $message );
    }
}
