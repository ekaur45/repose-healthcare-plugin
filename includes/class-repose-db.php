<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_DB {

    public static function init() {
        // Check if DB needs upgrading after plugin update
        if ( get_option( 'repose_db_version' ) !== REPOSE_VERSION ) {
            self::install();
        }
    }

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Original Repose tables ────────────────────────────────────────

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_reference_counters (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref_date    DATE            NOT NULL,
            counter     INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY ref_date (ref_date)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_order_queue (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id      BIGINT UNSIGNED NOT NULL,
            queue_type    VARCHAR(32)     NOT NULL DEFAULT 'manual_auth',
            flag_reason   TEXT,
            status        VARCHAR(32)     NOT NULL DEFAULT 'pending',
            assigned_to   BIGINT UNSIGNED,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_audit_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT UNSIGNED,
            user_id     BIGINT UNSIGNED NOT NULL,
            action      VARCHAR(128)    NOT NULL,
            detail      LONGTEXT,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_results (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT UNSIGNED NOT NULL,
            reference_num   VARCHAR(64),
            test_type       VARCHAR(128),
            file_path       VARCHAR(512),
            status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
            uploaded_by     BIGINT UNSIGNED,
            uploaded_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by     BIGINT UNSIGNED,
            approved_at     DATETIME,
            review_email_at DATETIME,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY reference_num (reference_num)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_result_notes (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            result_id   BIGINT UNSIGNED NOT NULL,
            author_id   BIGINT UNSIGNED NOT NULL,
            note        LONGTEXT        NOT NULL,
            visibility  VARCHAR(16)     NOT NULL DEFAULT 'internal',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY result_id (result_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_comment_templates (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255)    NOT NULL,
            body        LONGTEXT        NOT NULL,
            visibility  VARCHAR(16)     NOT NULL DEFAULT 'patient',
            created_by  BIGINT UNSIGNED,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};" );

        // ── HL7 ORU Tables ────────────────────────────────────────────────
        // Stores structured HL7 data received via the JSON import API.
        // All tables are prefixed with {wpdb->prefix}repose_hl7_

        // Raw HL7 messages — full JSON payload stored for audit and debug
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_messages (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wc_order_id    BIGINT UNSIGNED,
            reference_num  VARCHAR(64),
            message        LONGTEXT,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY wc_order_id   (wc_order_id),
            KEY reference_num (reference_num)
        ) {$charset};" );

        // Patients — demographic data from PID segment
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_patients (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id  VARCHAR(50),
            first_name  VARCHAR(100),
            last_name   VARCHAR(100),
            dob         DATE,
            gender      VARCHAR(10),
            phone       VARCHAR(20),
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY patient_id (patient_id)
        ) {$charset};" );

        // Visits — PV1 segment data
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_visits (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hl7_patient_id   BIGINT UNSIGNED,
            visit_number     VARCHAR(50),
            patient_class    VARCHAR(20),
            admit_datetime   DATETIME,
            attending_doctor VARCHAR(100),
            facility         VARCHAR(100),
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY visit_number   (visit_number),
            KEY hl7_patient_id (hl7_patient_id)
        ) {$charset};" );

        // Orders — ORC segment data
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_orders (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hl7_visit_id        BIGINT UNSIGNED,
            wc_order_id         BIGINT UNSIGNED,
            placer_order_number VARCHAR(100),
            filler_order_number VARCHAR(100),
            order_status        VARCHAR(20),
            order_datetime      DATETIME,
            ordering_provider   VARCHAR(100),
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY placer_order_number (placer_order_number),
            KEY wc_order_id         (wc_order_id)
        ) {$charset};" );

        // Reports — OBR segment data
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_reports (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hl7_order_id         BIGINT UNSIGNED,
            wc_result_id         BIGINT UNSIGNED,
            test_code            VARCHAR(50),
            test_name            VARCHAR(255),
            coding_system        VARCHAR(50),
            observation_datetime DATETIME,
            result_status        VARCHAR(20),
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY hl7_order_id (hl7_order_id),
            KEY wc_result_id (wc_result_id)
        ) {$charset};" );

        // Observations — OBX segment data (one row per test analyte)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_observations (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hl7_report_id        BIGINT UNSIGNED,
            observation_code     VARCHAR(50),
            observation_name     VARCHAR(255),
            value                TEXT,
            units                VARCHAR(50),
            reference_range      VARCHAR(100),
            abnormal_flag        VARCHAR(10),
            result_status        VARCHAR(10),
            observation_datetime DATETIME,
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY hl7_report_id (hl7_report_id)
        ) {$charset};" );

        // Notes — NTE segment data (lab notes/comments)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_hl7_notes (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hl7_report_id BIGINT UNSIGNED,
            comment       TEXT,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY hl7_report_id (hl7_report_id)
        ) {$charset};" );

        // ── Patient Registry ──────────────────────────────────────────────
        // Central patient records with a unique patient ID (e.g. RHP-0001)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_patients (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_uid     VARCHAR(32)     NOT NULL,
            forename        VARCHAR(100)    NOT NULL,
            surname         VARCHAR(100)    NOT NULL,
            dob             VARCHAR(20)     NOT NULL,
            sex             VARCHAR(10)     NOT NULL,
            email           VARCHAR(200),
            phone           VARCHAR(50),
            notes           TEXT,
            wc_user_id      BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY patient_uid (patient_uid),
            KEY email (email),
            KEY wc_user_id (wc_user_id)
        ) {$charset};" );

        // Patient ↔ Order ↔ Test mapping (which patient on which order gets which test)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_patient_tests (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id      BIGINT UNSIGNED NOT NULL,
            order_id        BIGINT UNSIGNED NOT NULL,
            test_name       VARCHAR(255)    NOT NULL,
            product_id      BIGINT UNSIGNED DEFAULT NULL,
            status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
            assigned_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            result_id       BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY patient_id (patient_id),
            KEY order_id   (order_id),
            KEY result_id  (result_id)
        ) {$charset};" );

        // UID counter for patient IDs
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}repose_patient_uid_counter (
            id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            counter INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset};" );

        // Seed the counter row if missing
        $wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}repose_patient_uid_counter (id, counter) VALUES (1, 0)" );

        // ── Migrations for existing installs ──────────────────────────────
        // Add result_id index to patient_tests if not already present
        $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}repose_patient_tests" );
        $col_names = array_column( $cols, 'Field' );
        if ( ! in_array( 'result_id', $col_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}repose_patient_tests ADD COLUMN result_id BIGINT UNSIGNED DEFAULT NULL" );
        }

        // ── Seed default comment templates ────────────────────────────────
        self::seed_comment_templates();

        update_option( 'repose_db_version', REPOSE_VERSION );
    }

    private static function seed_comment_templates() {
        global $wpdb;
        $table = $wpdb->prefix . 'repose_comment_templates';
        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) return;

        $defaults = array(
            array( 'title' => 'Normal Range',           'body' => 'Your result is within the normal range. No further action is required at this time.',             'visibility' => 'patient'  ),
            array( 'title' => 'Borderline Result',      'body' => 'Your result is borderline. We recommend repeating the test in 4-6 weeks.',                        'visibility' => 'patient'  ),
            array( 'title' => 'Positive – GP Advised',  'body' => 'Your result is positive. We strongly recommend consulting your GP or a healthcare professional.', 'visibility' => 'patient'  ),
            array( 'title' => 'Internal – Lab Error',   'body' => 'Sample flagged for potential lab processing error. Requires re-collection.',                       'visibility' => 'internal' ),
        );
        foreach ( $defaults as $tpl ) {
            $wpdb->insert( $table, array_merge( $tpl, array( 'created_by' => get_current_user_id() ) ) );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'repose_daily_reset' );
    }

    /**
     * Get all Repose table names for reference.
     */
    public static function get_table_names(): array {
        global $wpdb;
        return array(
            // Core tables
            'reference_counters'  => $wpdb->prefix . 'repose_reference_counters',
            'order_queue'         => $wpdb->prefix . 'repose_order_queue',
            'audit_log'           => $wpdb->prefix . 'repose_audit_log',
            'results'             => $wpdb->prefix . 'repose_results',
            'result_notes'        => $wpdb->prefix . 'repose_result_notes',
            'comment_templates'   => $wpdb->prefix . 'repose_comment_templates',
            // Patient registry
            'patients'            => $wpdb->prefix . 'repose_patients',
            'patient_tests'       => $wpdb->prefix . 'repose_patient_tests',
            'patient_uid_counter' => $wpdb->prefix . 'repose_patient_uid_counter',
            'hl7_messages'        => $wpdb->prefix . 'repose_hl7_messages',
            'hl7_patients'        => $wpdb->prefix . 'repose_hl7_patients',
            'hl7_visits'          => $wpdb->prefix . 'repose_hl7_visits',
            'hl7_orders'          => $wpdb->prefix . 'repose_hl7_orders',
            'hl7_reports'         => $wpdb->prefix . 'repose_hl7_reports',
            'hl7_observations'    => $wpdb->prefix . 'repose_hl7_observations',
            'hl7_notes'           => $wpdb->prefix . 'repose_hl7_notes',
        );
    }
}
