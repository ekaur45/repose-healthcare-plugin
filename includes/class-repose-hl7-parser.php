<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * HL7 v2 Pipe-Delimited Message Parser
 *
 * Parses raw HL7 pipe-delimited messages (ORR and ORU types) received from TDL.
 *
 * TDL HL7 Specification notes:
 * - Source: REPOSE
 * - PatientID: max 10 chars (maps to our patient_uid)
 * - OrderReference: max 10 chars (maps to _repose_reference_number truncated)
 * - Field separator: |
 * - Component separator: ^
 * - Repetition separator: ~
 * - Encoding chars in MSH-2: ^~\&
 *
 * Segment types handled:
 *   MSH — Message header (message type ORR or ORU)
 *   PID — Patient identification
 *   PV1 — Patient visit (order reference in PV1-19)
 *   ORC — Order control (order status, placer/filler numbers)
 *   OBR — Observation request (test info)
 *   OBX — Observation result (individual analyte)
 *   NTE — Notes and comments
 */
class Repose_HL7_Parser {

    // ── Parse a raw HL7 message string ────────────────────────────────────
    public static function parse( string $raw ): array {
        $result = array(
            'message_type' => '',   // ORR, ORU, etc.
            'message_id'   => '',
            'datetime'     => '',
            'patient'      => array(),
            'orders'       => array(),
            'raw_segments' => array(),
            'errors'       => array(),
        );

        // Normalise line endings
        $raw = str_replace( array( "\r\n", "\r" ), "\n", trim( $raw ) );
        $lines = array_filter( explode( "\n", $raw ) );

        $current_order = null;
        $current_obr   = null;

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) continue;

            $fields = explode( '|', $line );
            $seg    = strtoupper( $fields[0] ?? '' );
            $result['raw_segments'][] = $seg;

            switch ( $seg ) {
                case 'MSH':
                    $result['message_type'] = self::f( $fields, 8 );  // e.g. ORR^O02 or ORU^R01
                    $result['message_id']   = self::f( $fields, 9 );
                    $result['datetime']     = self::f( $fields, 6 );
                    $result['sending_facility'] = self::f( $fields, 3 );
                    break;

                case 'PID':
                    $result['patient'] = array(
                        'internal_id'  => self::component( self::f( $fields, 3 ), 0 ), // PID-3 patient ID (our PatientUID)
                        'external_id'  => self::component( self::f( $fields, 4 ), 0 ), // PID-4 alternate ID
                        'surname'      => self::component( self::f( $fields, 5 ), 0 ), // PID-5.1
                        'forename'     => self::component( self::f( $fields, 5 ), 1 ), // PID-5.2
                        'dob'          => self::f( $fields, 7 ),                        // PID-7 YYYYMMDD
                        'sex'          => self::f( $fields, 8 ),                        // PID-8 M/F
                        'address'      => self::f( $fields, 11 ),                       // PID-11
                        'phone'        => self::f( $fields, 13 ),                       // PID-13
                        'email'        => self::component( self::f( $fields, 13 ), 3 ), // PID-13.4
                    );
                    break;

                case 'PV1':
                    // PV1-19 = order reference (our OrderReference)
                    if ( empty( $result['order_reference'] ) ) {
                        $result['order_reference'] = self::f( $fields, 19 );
                    }
                    break;

                case 'ORC':
                    // Start a new order block
                    $current_order = array(
                        'control_code'        => self::f( $fields, 1 ),  // ORC-1: NW=new,CA=cancel,SC=status
                        'placer_order_number' => self::component( self::f( $fields, 2 ), 0 ), // ORC-2.1
                        'filler_order_number' => self::component( self::f( $fields, 3 ), 0 ), // ORC-3.1
                        'order_status'        => self::f( $fields, 5 ),  // ORC-5: IP,CM,etc
                        'datetime_order'      => self::f( $fields, 9 ),  // ORC-9
                        'ordering_provider'   => self::f( $fields, 12 ), // ORC-12
                        'order_reference'     => self::f( $fields, 4 ),  // ORC-4 group (alternate ref)
                        'obr_list'            => array(),
                    );
                    $result['orders'][] = &$current_order;
                    $current_obr = null;
                    break;

                case 'OBR':
                    $current_obr = array(
                        'set_id'              => self::f( $fields, 1 ),
                        'placer_order_number' => self::component( self::f( $fields, 2 ), 0 ),
                        'filler_order_number' => self::component( self::f( $fields, 3 ), 0 ),
                        'test_code'           => self::component( self::f( $fields, 4 ), 0 ),  // OBR-4.1
                        'test_name'           => self::component( self::f( $fields, 4 ), 1 ),  // OBR-4.2
                        'coding_system'       => self::component( self::f( $fields, 4 ), 2 ),  // OBR-4.3
                        'observation_datetime'=> self::f( $fields, 7 ),
                        'result_status'       => self::f( $fields, 25 ), // OBR-25: P=preliminary,F=final,C=corrected
                        'observations'        => array(),
                        'notes'               => array(),
                    );
                    if ( $current_order !== null ) {
                        $current_order['obr_list'][] = &$current_obr;
                    } else {
                        // OBR without ORC — create a wrapper
                        $current_order = array( 'obr_list' => array( &$current_obr ), 'placer_order_number' => '' );
                        $result['orders'][] = &$current_order;
                    }
                    break;

                case 'OBX':
                    if ( $current_obr !== null ) {
                        $current_obr['observations'][] = array(
                            'set_id'           => self::f( $fields, 1 ),
                            'value_type'       => self::f( $fields, 2 ),   // NM, ST, FT, CE
                            'observation_id'   => self::component( self::f( $fields, 3 ), 0 ),
                            'observation_name' => self::component( self::f( $fields, 3 ), 1 ),
                            'sub_id'           => self::f( $fields, 4 ),
                            'value'            => self::f( $fields, 5 ),
                            'units'            => self::component( self::f( $fields, 6 ), 1 ),
                            'reference_range'  => self::f( $fields, 7 ),
                            'abnormal_flags'   => self::f( $fields, 8 ),   // H, L, N, A, AA, LL, HH
                            'result_status'    => self::f( $fields, 11 ),  // F, P, C
                            'observation_datetime' => self::f( $fields, 14 ),
                        );
                    }
                    break;

                case 'NTE':
                    $note_text = self::f( $fields, 3 );
                    if ( $current_obr !== null ) {
                        $current_obr['notes'][] = $note_text;
                    } elseif ( $current_order !== null ) {
                        $current_order['notes'][] = $note_text;
                    } else {
                        $result['global_notes'][] = $note_text;
                    }
                    break;
            }
        }

        // Unset references to avoid pointer issues
        unset( $current_order, $current_obr );

        return $result;
    }

    // ── Get message type (ORR, ORU, etc.) ────────────────────────────────
    public static function get_type( array $parsed ): string {
        return strtoupper( self::component( $parsed['message_type'], 0 ) );
    }

    // ── Extract PatientID (our patient_uid, max 10 chars) ─────────────────
    public static function get_patient_uid( array $parsed ): string {
        return substr( trim( $parsed['patient']['internal_id'] ?? '' ), 0, 10 );
    }

    // ── Extract OrderReference (our short ref, max 10 chars) ─────────────
    public static function get_order_reference( array $parsed ): string {
        // Check PV1-19 first, then ORC placer_order_number
        if ( ! empty( $parsed['order_reference'] ) ) {
            return substr( trim( $parsed['order_reference'] ), 0, 10 );
        }
        foreach ( $parsed['orders'] as $order ) {
            if ( ! empty( $order['placer_order_number'] ) ) {
                return substr( trim( $order['placer_order_number'] ), 0, 10 );
            }
        }
        return '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private static function f( array $fields, int $index ): string {
        return trim( $fields[ $index ] ?? '' );
    }

    private static function component( string $field, int $idx ): string {
        $parts = explode( '^', $field );
        return trim( $parts[ $idx ] ?? '' );
    }

    // ── Format HL7 datetime (YYYYMMDDHHMMSS) to MySQL ────────────────────
    public static function hl7_datetime( string $dt ): string {
        if ( strlen( $dt ) >= 8 ) {
            $y = substr( $dt, 0, 4 );
            $m = substr( $dt, 4, 2 );
            $d = substr( $dt, 6, 2 );
            $h = substr( $dt, 8, 2 ) ?: '00';
            $i = substr( $dt, 10, 2 ) ?: '00';
            $s = substr( $dt, 12, 2 ) ?: '00';
            return "{$y}-{$m}-{$d} {$h}:{$i}:{$s}";
        }
        return current_time( 'mysql' );
    }
}
