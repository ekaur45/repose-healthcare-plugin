<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_Order_Validator {

    /** Required custom checkout / order meta fields */
    const REQUIRED_FIELDS = array(
        '_repose_patient_forename',
        '_repose_patient_surname',
        '_repose_sex_at_birth',
        '_repose_date_of_birth',
        '_billing_email',
    );

    /**
     * Validate an order.
     *
     * @param  WC_Order $order
     * @return array  { valid: bool, missing: string[] }
     */
    public static function validate( WC_Order $order ): array {
        $missing = array();

        foreach ( self::REQUIRED_FIELDS as $field ) {
            if ( $field === '_billing_email' ) {
                $val = $order->get_billing_email();
            } else {
                $val = $order->get_meta( $field );
            }
            if ( empty( $val ) ) {
                $missing[] = self::field_label( $field );
            }
        }

        // Shipping address check
        if ( ! $order->get_shipping_address_1() && ! $order->get_billing_address_1() ) {
            $missing[] = 'Shipping Address';
        }

        return array(
            'valid'   => empty( $missing ),
            'missing' => $missing,
        );
    }

    /**
     * Classify order type.
     *
     * @param  WC_Order $order
     * @return string  'self_collect' | 'venous'
     */
    public static function classify( WC_Order $order ): string {
        $type = $order->get_meta( '_repose_order_type' );
        if ( in_array( $type, array( 'self_collect', 'venous' ), true ) ) {
            return $type;
        }
        // Auto-detect from product meta if not explicitly set
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            if ( get_post_meta( $product_id, '_repose_venous', true ) === 'yes' ) {
                return 'venous';
            }
        }
        return 'self_collect';
    }

    /**
     * Get all test names from order line items.
     *
     * @param  WC_Order $order
     * @return string[]
     */
    public static function get_tests( WC_Order $order ): array {
        $tests = array();
        foreach ( $order->get_items() as $item ) {
            $tests[] = $item->get_name();
        }
        return array_slice( $tests, 0, 10 );
    }

    /**
     * Detect anomalies beyond missing fields.
     *
     * @param  WC_Order $order
     * @return string[]  List of flag reasons
     */
    public static function detect_anomalies( WC_Order $order ): array {
        $flags = array();

        $email = $order->get_billing_email();
        $tests = self::get_tests( $order );

        // Duplicate check: same email + same tests in last 30 days
        if ( $email ) {
            $recent_orders = wc_get_orders( array(
                'limit'        => 5,
                'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
                'exclude'      => array( $order->get_id() ),
            ) );
            foreach ( $recent_orders as $prev ) {
                if ( strtolower( $prev->get_billing_email() ) === strtolower( $email ) ) {
                    $prev_tests = self::get_tests( $prev );
                    if ( array_intersect( $tests, $prev_tests ) ) {
                        $flags[] = 'Possible duplicate order (same email + overlapping tests within 30 days)';
                        break;
                    }
                }
            }
        }

        // Multi-test order
        if ( count( $tests ) > 1 ) {
            $flags[] = 'Multiple tests — manual authorisation required';
        }

        return $flags;
    }

    private static function field_label( string $field ): string {
        $map = array(
            '_repose_patient_forename' => 'Patient Forename',
            '_repose_patient_surname'  => 'Patient Surname',
            '_repose_sex_at_birth'     => 'Sex at Birth',
            '_repose_date_of_birth'    => 'Date of Birth',
            '_billing_email'           => 'Email Address',
        );
        return $map[ $field ] ?? $field;
    }
}
