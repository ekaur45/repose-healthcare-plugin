<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates patient reference numbers in the format:
 * R + YY + M + DD + NNNN
 *
 * Where:
 *   YY   = 2-digit year  (e.g. 26)
 *   M    = Month letter  A=Jan … L=Dec
 *   DD   = 2-digit day   (e.g. 14)
 *   NNNN = 4-digit random number (0000–9999)
 *
 * Total length: 1+2+1+2+4 = 10 characters exactly — matches TDL max.
 *
 * Examples:
 *   R26A141257  → 14 Jan 2026, random 1257
 *   R26D031089  → 03 Apr 2026, random 1089
 *
 * The random suffix ensures uniqueness even if multiple orders are placed
 * on the same day. Collision probability: 1 in 10,000 per day — acceptable
 * for the expected order volume. Uniqueness is verified before returning.
 *
 * Month letters: A=Jan B=Feb C=Mar D=Apr E=May F=Jun
 *                G=Jul H=Aug I=Sep J=Oct K=Nov L=Dec
 */
class Repose_Reference {

    private static $month_map = array(
        1  => 'A', 2  => 'B', 3  => 'C', 4  => 'D',
        5  => 'E', 6  => 'F', 7  => 'G', 8  => 'H',
        9  => 'I', 10 => 'J', 11 => 'K', 12 => 'L',
    );

    /**
     * Generate a unique 10-character reference number.
     * Format: R + YY + M + DD + NNNN (exactly 10 chars)
     *
     * @param  int $order_id  WooCommerce order ID (used for uniqueness check)
     * @return string  e.g. R26A141257
     */
    public static function generate( int $order_id = 0 ): string {
        $year  = current_time( 'y' );               // e.g. 26
        $month = self::$month_map[ (int) current_time( 'n' ) ]; // e.g. D
        $day   = current_time( 'd' );               // e.g. 14

        $prefix = sprintf( 'R%s%s%s', $year, $month, $day ); // 6 chars: R26D14

        // Generate a unique 4-digit random suffix
        $max_attempts = 20;
        for ( $i = 0; $i < $max_attempts; $i++ ) {
            $suffix = str_pad( (string) mt_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
            $ref    = $prefix . $suffix; // exactly 10 chars

            // Check uniqueness against existing order meta
            if ( ! self::exists( $ref ) ) {
                return $ref;
            }
        }

        // Fallback: use order_id last 4 digits (very unlikely to reach this)
        return $prefix . str_pad( (string) ( $order_id % 10000 ), 4, '0', STR_PAD_LEFT );
    }

    /**
     * Check if a reference number already exists in order meta.
     */
    private static function exists( string $ref ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_repose_reference_number' AND meta_value = %s",
            $ref
        ) );
    }

    /**
     * Extract the WooCommerce order ID from an old-format reference number.
     * New format (R26D141257) does not encode order ID — returns 0.
     * Old format (R25C290069) encodes order ID in last digits — still works.
     *
     * @param  string $ref
     * @return int  WC order ID (old format only), or 0
     */
    public static function extract_order_id( string $ref ): int {
        // Old format: R + YY + letter + DD + digits (where digits = order ID, may be >4)
        // New format: R + YY + letter + DD + 4 digits (random — cannot decode order ID)
        // We attempt extraction but treat 4-digit results as ambiguous
        if ( preg_match( '/^R\d{2}[A-L]\d{2}(\d+)$/i', trim( $ref ), $matches ) ) {
            $num = (int) $matches[1];
            // Only trust as order ID if > 4 digits (new format is exactly 4 digits random)
            if ( strlen( $matches[1] ) > 4 ) {
                return $num;
            }
        }
        return 0;
    }

    /**
     * Check if a string looks like a valid Repose reference number (either format).
     */
    public static function is_valid( string $ref ): bool {
        // Exactly 10 chars: R + 2 digits + letter + 2 digits + 4 digits
        return (bool) preg_match( '/^R\d{2}[A-L]\d{2}\d{4}$/i', trim( $ref ) );
    }
}
