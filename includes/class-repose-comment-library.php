<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_Comment_Library {

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}repose_comment_templates ORDER BY title ASC" );
    }

    public static function add( string $title, string $body, string $visibility = 'patient' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'repose_comment_templates', array(
            'title'      => sanitize_text_field( $title ),
            'body'       => wp_kses_post( $body ),
            'visibility' => in_array( $visibility, array( 'patient', 'internal' ), true ) ? $visibility : 'patient',
            'created_by' => get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
        ) );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, string $title, string $body, string $visibility ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'repose_comment_templates',
            array(
                'title'      => sanitize_text_field( $title ),
                'body'       => wp_kses_post( $body ),
                'visibility' => in_array( $visibility, array( 'patient', 'internal' ), true ) ? $visibility : 'patient',
            ),
            array( 'id' => $id )
        );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'repose_comment_templates', array( 'id' => $id ) );
    }
}
