<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_Audit_Log {

    public static function record( int $order_id, int $user_id, string $action, string $detail = '' ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_audit_log', array(
            'order_id'   => $order_id,
            'user_id'    => $user_id,
            'action'     => sanitize_text_field( $action ),
            'detail'     => $detail,
            'created_at' => current_time( 'mysql' ),
        ) );
    }

    public static function get_for_order( int $order_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, u.user_login FROM {$wpdb->prefix}repose_audit_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             WHERE l.order_id = %d ORDER BY l.created_at DESC",
            $order_id
        ) );
    }
}
