<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API endpoint for importing lab results posted as JSON.
 *
 * POST /wp-json/repose/v1/results/json-import
 * Header: X-Repose-Token: <api_token>
 *
 * Accepts the HL7 ORU JSON format from TDL Messaging or similar.
 * Generates a branded PDF from the JSON data and queues it for admin review.
 */
class Repose_JSON_Import {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // AJAX: generate (or serve cached) PDF from stored HL7 data
        add_action( 'wp_ajax_repose_generate_hl7_pdf',        array( __CLASS__, 'ajax_generate_hl7_pdf' ) );
        add_action( 'wp_ajax_nopriv_repose_generate_hl7_pdf', array( __CLASS__, 'ajax_generate_hl7_pdf' ) );
        add_action('admin_init',function () {
            register_setting('general', 'API_URL');
            register_setting('general', 'API_USERNAME');
            register_setting('general', 'API_PASSWORD');
            add_settings_field('API_URL', 'Api Url',function(){
                $value=get_option('API_URL','');
                echo '<input type="text" name="API_URL" value="' . esc_attr($value) . '" class="regular-text">';
            },'general');
            add_settings_field('API_USERNAME', 'Api Url',function(){
                $value=get_option('API_USERNAME','');
                echo '<input type="text" name="API_USERNAME" value="' . esc_attr($value) . '" class="regular-text">';
            },'general');
            add_settings_field('API_PASSWORD', 'Api Url',function(){
                $value=get_option('API_PASSWORD','');
                echo '<input type="text" name="API_PASSWORD" value="' . esc_attr($value) . '" class="regular-text">';
            },'general');
        });
    }

    public static function register_routes() {
        register_rest_route( 'repose/v1', '/results/json-import', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_import' ),
            'permission_callback' => array( __CLASS__, 'authenticate' ),
        ) );
    }

    public static function authenticate( WP_REST_Request $request ): bool {
        $token = $request->get_header( 'X-Repose-Token' );
        return hash_equals( (string) get_option( 'repose_api_token', '' ), (string) $token );
    }
    /**
     * Save HL7 lab data into all structured HL7 tables.
     *
     * Inserts rows into:
     *   - wp_repose_hl7_messages    (full raw JSON, audit)
     *   - wp_repose_hl7_patients    (PID – demographics)
     *   - wp_repose_hl7_visits      (PV1 – visit/location)
     *   - wp_repose_hl7_orders      (ORC – order control)
     *   - wp_repose_hl7_reports     (OBR – test report headers)
     *   - wp_repose_hl7_observations (OBX – individual analyte results)
     *   - wp_repose_hl7_notes       (NTE – lab notes/comments)
     *
     * @param  array     $data          Parsed JSON from the lab API
     * @param  int       $wc_order_id   WooCommerce order ID (0 if unknown)
     * @param  string    $reference_num Repose reference number
     * @param  int       $wc_result_id  Row ID from wp_repose_results (0 until inserted)
     * @return array {
     *     hl7_message_id  int,
     *     hl7_patient_id  int,
     *     hl7_visit_id    int,
     *     hl7_order_id    int,
     *     hl7_report_ids  int[],
     * }
     */
    public static function process_lab_data( array $data, int $wc_order_id = 0, string $reference_num = '', int $wc_result_id = 0 ): array {
        global $wpdb;

        $ids = array(
            'hl7_message_id' => 0,
            'hl7_patient_id' => 0,
            'hl7_visit_id'   => 0,
            'hl7_order_id'   => 0,
            'hl7_report_ids' => array(),
            'db_errors'      => array(),  // collects any DB errors for debug response
        );

        // ── 1. wp_repose_hl7_messages — full payload for audit/debug ────────
        $wpdb->insert(
            $wpdb->prefix . 'repose_hl7_messages',
            array(
                'wc_order_id'   => $wc_order_id   ?: null,
                'reference_num' => $reference_num  ?: null,
                'message'       => wp_json_encode( $data ),
                'created_at'    => current_time( 'mysql' ),
            )
        );
        $ids['hl7_message_id'] = (int) $wpdb->insert_id;
        if ( $wpdb->last_error ) { $ids['db_errors']['hl7_messages'] = $wpdb->last_error; }

        // ── 2. wp_repose_hl7_patients — PID segment ─────────────────────────
        $patient    = $data['patient'] ?? array();
        $name       = $patient['patientName'] ?? array();
        $raw_dob    = $patient['dateOfBirth'] ?? '';
        $dob_mysql  = '';
        if ( strlen( $raw_dob ) >= 8 ) {
            $dob_mysql = substr( $raw_dob, 0, 4 ) . '-'
                       . substr( $raw_dob, 4, 2 ) . '-'
                       . substr( $raw_dob, 6, 2 );
        }

        $wpdb->insert(
            $wpdb->prefix . 'repose_hl7_patients',
            array(
                'patient_id' => sanitize_text_field( $patient['patientId'] ?? '' ) ?: null,
                'first_name' => sanitize_text_field( $name['givenName']   ?? '' ),
                'last_name'  => sanitize_text_field( $name['familyName']  ?? '' ),
                'dob'        => $dob_mysql ?: null,
                'gender'     => sanitize_text_field( $patient['sex']             ?? '' ),
                'phone'      => sanitize_text_field( $patient['phoneNumberHome'] ?? '' ),
                'created_at' => current_time( 'mysql' ),
            )
        );
        $ids['hl7_patient_id'] = (int) $wpdb->insert_id;
        if ( $wpdb->last_error ) { $ids['db_errors']['hl7_patients'] = $wpdb->last_error; }

        // ── 3. wp_repose_hl7_visits — PV1 segment ───────────────────────────
        $visit          = $data['visit'] ?? array();
        $admit_raw      = $visit['admitDateTime'] ?? '';
        $admit_mysql    = self::hl7_datetime_to_mysql( $admit_raw );

        $wpdb->insert(
            $wpdb->prefix . 'repose_hl7_visits',
            array(
                'hl7_patient_id'   => $ids['hl7_patient_id'] ?: null,
                'visit_number'     => sanitize_text_field( $visit['visitNumber']     ?? '' ),
                'patient_class'    => sanitize_text_field( $visit['patientClass']    ?? '' ),
                'admit_datetime'   => $admit_mysql ?: null,
                'attending_doctor' => sanitize_text_field( $visit['attendingDoctor'] ?? '' ),
                'facility'         => sanitize_text_field( $visit['servicingFacility'] ?? '' ),
                'created_at'       => current_time( 'mysql' ),
            )
        );
        $ids['hl7_visit_id'] = (int) $wpdb->insert_id;
        if ( $wpdb->last_error ) { $ids['db_errors']['hl7_visits'] = $wpdb->last_error; }

        // ── 4. wp_repose_hl7_orders — ORC segment ───────────────────────────
        $order_seg      = $data['order'] ?? array();
        $order_dt_raw   = $order_seg['orderEffectiveDateTime'] ?? '';
        $order_dt_mysql = self::hl7_datetime_to_mysql( $order_dt_raw );

        $wpdb->insert(
            $wpdb->prefix . 'repose_hl7_orders',
            array(
                'hl7_visit_id'        => $ids['hl7_visit_id']  ?: null,
                'wc_order_id'         => $wc_order_id          ?: null,
                'placer_order_number' => sanitize_text_field( $order_seg['placerOrderNumber'] ?? '' ),
                'filler_order_number' => sanitize_text_field( $order_seg['fillerOrderNumber'] ?? '' ),
                'order_status'        => sanitize_text_field( $order_seg['orderStatus']       ?? '' ),
                'order_datetime'      => $order_dt_mysql ?: null,
                'ordering_provider'   => sanitize_text_field( $order_seg['orderingProvider']  ?? '' ),
                'created_at'          => current_time( 'mysql' ),
            )
        );
        $ids['hl7_order_id'] = (int) $wpdb->insert_id;
        if ( $wpdb->last_error ) { $ids['db_errors']['hl7_orders'] = $wpdb->last_error; }

        // ── 5. wp_repose_hl7_reports / _observations / _notes — OBR+OBX+NTE ─
        $reports = $data['reports'] ?? array();
        foreach ( $reports as $report ) {
            $svc_id   = $report['universalServiceId'] ?? array();
            $obs_dt   = self::hl7_datetime_to_mysql( $report['observationDateTime'] ?? '' );

            $wpdb->insert(
                $wpdb->prefix . 'repose_hl7_reports',
                array(
                    'hl7_order_id'         => $ids['hl7_order_id'] ?: null,
                    'wc_result_id'         => $wc_result_id        ?: null,
                    'test_code'            => sanitize_text_field( $svc_id['identifier']        ?? '' ),
                    'test_name'            => sanitize_text_field( $svc_id['text']              ?? '' ),
                    'coding_system'        => sanitize_text_field( $svc_id['nameOfCodingSystem'] ?? '' ),
                    'observation_datetime' => $obs_dt ?: null,
                    'result_status'        => sanitize_text_field( $report['resultStatus'] ?? '' ),
                    'created_at'           => current_time( 'mysql' ),
                )
            );
            $hl7_report_id           = (int) $wpdb->insert_id;
            $ids['hl7_report_ids'][] = $hl7_report_id;

            // OBX — individual observations
            foreach ( $report['observations'] ?? array() as $obs ) {
                $obs_id_seg  = $obs['observationIdentifier'] ?? array();
                $obs_dt_row  = self::hl7_datetime_to_mysql( $obs['dateTime'] ?? '' );

                $wpdb->insert(
                    $wpdb->prefix . 'repose_hl7_observations',
                    array(
                        'hl7_report_id'        => $hl7_report_id,
                        'observation_code'     => sanitize_text_field( $obs_id_seg['identifier']    ?? '' ),
                        'observation_name'     => sanitize_text_field(
                            $obs_id_seg['text']             ??
                            $obs_id_seg['observation_name'] ?? ''
                        ),
                        'value'                => sanitize_textarea_field( $obs['observationValue']        ?? '' ),
                        'units'                => sanitize_text_field( $obs['units']                       ?? '' ),
                        'reference_range'      => sanitize_text_field( $obs['referenceRange']              ?? '' ),
                        'abnormal_flag'        => sanitize_text_field( $obs['abnormalFlag']                ?? '' ),
                        'result_status'        => sanitize_text_field( $obs['observationResultStatus']     ?? '' ),
                        'observation_datetime' => $obs_dt_row ?: null,
                        'created_at'           => current_time( 'mysql' ),
                    )
                );
            }

            // NTE — lab notes
            // In the TDL JSON format the actual text lives in 'sourceOfComment';
            // 'comment' is always an empty string.  Check both, preferring sourceOfComment.
            foreach ( $report['notes'] ?? array() as $note ) {
                $comment = trim( (string) ( $note['sourceOfComment'] ?? $note['comment'] ?? '' ) );
                if ( $comment === '' ) continue;

                $wpdb->insert(
                    $wpdb->prefix . 'repose_hl7_notes',
                    array(
                        'hl7_report_id' => $hl7_report_id,
                        'comment'       => sanitize_textarea_field( $comment ),
                        'created_at'    => current_time( 'mysql' ),
                    )
                );
            }
        }

        return $ids;
    }

    /**
     * Convert an HL7 datetime string (YYYYMMDDHHmmss or YYYYMMDD) to MySQL format.
     */
    private static function hl7_datetime_to_mysql( string $raw ): string {
        $raw = preg_replace( '/[^0-9]/', '', $raw );
        if ( strlen( $raw ) >= 14 ) {
            return sprintf(
                '%s-%s-%s %s:%s:%s',
                substr( $raw, 0, 4 ), substr( $raw, 4, 2 ), substr( $raw, 6, 2 ),
                substr( $raw, 8, 2 ), substr( $raw, 10, 2 ), substr( $raw, 12, 2 )
            );
        }
        if ( strlen( $raw ) >= 8 ) {
            return substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 ) . ' 00:00:00';
        }
        return '';
    }

    public static function handle_import( WP_REST_Request $request ) {
        $debug = array();  // collects a trace of every step — always returned in response

        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_REST_Response( array( 'error' => 'Empty or invalid JSON body.' ), 400 );
        }

        // Support both raw data and the wrapped { data: {...} } format
        $data = isset( $body['data'] ) ? $body['data'] : $body;
        $debug['step_1_received_body_keys'] = array_keys( $data );

        // ── Step 2: Extract key + reference from inbound payload ─────────────
        // if ( empty( $data['key'] ) ) {
        //     return new WP_REST_Response( array(
        //         'error' => 'Missing "key" field in payload. This is required to call the lab API.',
        //         'debug' => $debug,
        //     ), 400 );
        // }

        $ref_parts    = self::extract_ref( $data );
        $order_ref_no = $ref_parts['order_ref_id'];
        $ref_key      = $ref_parts['key'];

        $debug['step_2_extracted_key']       = $ref_key;
        $debug['step_2_extracted_order_ref'] = $order_ref_no;

        // ── Step 3: Call lab API ──────────────────────────────────────────────
        $api_result = self::callAPi( $order_ref_no );
        $rawMsg     = $api_result['raw']          ?? null;
        $jsonResult = $api_result['json']         ?? array();

        $debug['step_3_api_raw_response']      = $rawMsg;
        $debug['step_3_api_json_response']      = $jsonResult;
        $debug['step_3_api_json_empty']        = empty( $jsonResult );
        $debug['step_3_api_json_keys']         = is_array( $jsonResult ) ? array_keys( $jsonResult ) : 'NOT_AN_ARRAY';
        $debug['step_3_api_auth_error']        = $api_result['auth_error']    ?? null;
        $debug['step_3_api_fetch_error']       = $api_result['fetch_error']   ?? null;
        $debug['step_3_api_http_status_auth']  = $api_result['auth_status']   ?? null;
        $debug['step_3_api_http_status_fetch'] = $api_result['fetch_status']  ?? null;

        // Hard stop if API returned nothing — no point continuing
        if ( empty( $jsonResult ) ) {
            return new WP_REST_Response( array(
                'error'  => 'Lab API returned empty or unparseable data. Check debug for raw response.',
                'debug'  => $debug,
            ), 502 );
        }

        // ── Step 4: Resolve reference number ─────────────────────────────────
        $ref_number = $order_ref_no;
        $debug['step_4_reference_number'] = $ref_number;

        if ( ! $ref_number ) {
            return new WP_REST_Response( array(
                'error' => 'Could not extract reference number from payload.',
                'debug' => $debug,
            ), 400 );
        }

        // ── Step 5: Find matching WooCommerce order ──────────────────────────
        $order    = null;
        $order_id = 0;
        $debug['step_5_order_lookup'] = array();

        // Try direct order_id field first (most reliable)
        $direct_order_id = (int) ( $jsonResult['order_id'] ?? 0 );
        $debug['step_5_order_lookup'][] = "direct_order_id field in HL7 json: {$direct_order_id}";
        if ( $direct_order_id > 0 ) {
            $try = wc_get_order( $direct_order_id );
            if ( $try ) {
                $order = $try; $order_id = $direct_order_id;
                $debug['step_5_order_lookup'][] = "FOUND via direct order_id: {$order_id}";
            }
        }
        if (!$order && str_starts_with($ref_number, 'RHP-')) {
            // get test from patient registry
            $tests = Repose_Patient_Registry::get_patient_tests_by_uid($ref_number);
            if(count($tests) > 0) {
                $test = $tests[0];
                $try = wc_get_order( $test->order_id );
                if ( $try ) {
                    $order = $try; $order_id = $test->order_id;
                    $debug['step_5_order_lookup'][] = "FOUND via patient registry test lookup: {$order_id}";
                }
            }else{
                //get the latest by order_id
                $latestOrderId = null;
                foreach ($tests as $test) {
                    if ($latestOrderId === null || $test->order_id > $latestOrderId) {
                        $latestOrderId = $test->order_id;
                    }
                }
                if($latestOrderId){
                    $try = wc_get_order( $latestOrderId );
                    if ( $try ) {
                        $order = $try; $order_id = $latestOrderId;
                        $debug['step_5_order_lookup'][] = "FOUND via patient registry latest order_id lookup: {$order_id}";
                    }
                }
            }

        }
        // Try extracting order ID from reference number format RYYMMDDXXXX
        if ( ! $order && $ref_number ) {
            //$orderId = Repose_Reference::getOrderId($ref_number);
            $orderIds = wc_get_orders([
                'limit'      => 1,
                'meta_key'   => '_repose_reference_number',
                'meta_value' => $ref_number,
                'return'     => 'ids',
            ]);

            $orderId = $orderIds[0] ?? 0;
            //$extracted_id = Repose_Reference::extract_order_id( $ref_number );
            $debug['step_5_order_lookup'][] = "order id from ref '{$ref_number}': {$orderId}";
            if ( $orderId > 0 ) {
                $try = wc_get_order( $orderId );
                if ( $try ) {
                    $order = $try; $order_id = $orderId;
                    $debug['step_5_order_lookup'][] = "FOUND via ref extraction: {$order_id}";
                }
            }
        }

        // Try order.placerOrderNumber as a WC order ID (numeric)
        if ( ! $order && ! empty( $jsonResult['order']['placerOrderNumber'] ) ) {
            $placer_id = (int) $jsonResult['order']['placerOrderNumber'];
            $debug['step_5_order_lookup'][] = "placerOrderNumber: {$placer_id}";
            if ( $placer_id > 0 ) {
                $try = wc_get_order( $placer_id );
                if ( $try ) {
                    $order = $try; $order_id = $placer_id;
                    $debug['step_5_order_lookup'][] = "FOUND via placerOrderNumber: {$order_id}";
                }
            }
        }

        

        // Last resort: meta lookup by stored reference number
        if ( ! $order && $ref_number ) {
            $debug['step_5_order_lookup'][] = "trying meta lookup for ref: {$ref_number}";
            $orders = wc_get_orders( array(
                'meta_key'   => '_repose_reference_number',
                'meta_value' => $ref_number,
                'limit'      => 1,
            ) );
            if ( ! empty( $orders ) ) {
                $order    = $orders[0];
                $order_id = $order->get_id();
                $debug['step_5_order_lookup'][] = "FOUND via meta lookup: {$order_id}";
            } else {
                $debug['step_5_order_lookup'][] = "NOT FOUND via meta lookup";
            }
        }

        // Try patient.patientIdentifierList  as reference number
        $ref_number_alt = $jsonResult['patient']['patientIdentifierList'] ?? '';


        if ( ! $order && $ref_number_alt ) {
            $debug['step_6_order_lookup'][] = "trying meta lookup for ref: {$ref_number_alt}";
            $orders = wc_get_orders( array(
                'meta_key'   => '_repose_reference_number',
                'meta_value' => $ref_number_alt,
                'limit'      => 1,
            ) );
            if ( ! empty( $orders ) ) {
                $order    = $orders[0];
                $order_id = $order->get_id();
                $debug['step_6_order_lookup'][] = "FOUND via meta lookup: {$order_id}";
            } else {
                $debug['step_6_order_lookup'][] = "NOT FOUND via meta lookup";
            }
        }

        

        if ( ! $order ) {
            return new WP_REST_Response( array(
                'error'  => 'No WooCommerce order found for this result.',
                'debug'  => $debug,
                'tip'    => 'Ensure the reference number matches an order with _repose_reference_number meta, or add "order_id" to the HL7 JSON.',
            ), 404 );
        }

        $debug['step_5_resolved_order_id'] = $order_id;

        // ── Step 6: Extract test name from HL7 JSON ─────────────────────────
        $patient   = $jsonResult['patient']  ?? array();
        $reports   = $jsonResult['reports']  ?? array();
        $test_name = ! empty( $reports[0]['universalServiceId']['text'] )
                     ? $reports[0]['universalServiceId']['text']
                     : 'Lab Result';

        $debug['step_6_test_name']         = $test_name;
        $debug['step_6_patient_keys']      = array_keys( $patient );
        $debug['step_6_report_count']      = count( $reports );
        $debug['step_6_observations_count']= isset( $reports[0]['observations'] ) ? count( $reports[0]['observations'] ) : 0;

        // ── Step 7: Generate PDF / HTML report file ───────────────────────────
        $upload_dir  = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        wp_mkdir_p( $results_dir );

        if ( ! file_exists( $results_dir . '.htaccess' ) ) {
            file_put_contents( $results_dir . '.htaccess', "deny from all
" );
        }

        $filename = sanitize_file_name( 'json-result-' . $order_id . '-' . time() . '.pdf' );
        $filepath = $results_dir . $filename;

        // $pdf_result = self::generate_pdf( $jsonResult, $order, $filepath );
        // if ( is_wp_error( $pdf_result ) ) {
            //$debug['step_7_pdf_error'] = $pdf_result->get_error_message();
            $filename = str_replace( '.pdf', '.html', $filename );
            $filepath = $results_dir . $filename;
            file_put_contents( $filepath, self::build_html_report( $jsonResult, $order ) );
            $debug['step_7_file_type'] = 'html_fallback';
        // } else {
        //     $debug['step_7_file_type'] = 'pdf';
        // }
        $debug['step_7_filename'] = $filename;

        // Store raw JSON alongside the report
        $json_filename = 'raw-' . $order_id . '-' . time() . '.json';
        file_put_contents( $results_dir . $json_filename, json_encode( array(
            'trigger'    => $data,
            'hl7_result' => $jsonResult,
            'debug'      => $debug,
        ), JSON_PRETTY_PRINT ) );
        $debug['step_7_raw_json_file'] = $json_filename;

        // ── Step 8: Insert wp_repose_results row ─────────────────────────────
        global $wpdb;
        $insert_ok = $wpdb->insert( $wpdb->prefix . 'repose_results', array(
            'order_id'      => $order_id,
            'reference_num' => $ref_number,
            'test_type'     => $test_name,
            'file_path'     => $filename,
            'status'        => 'pending_review',
            'uploaded_by'   => 0,
            'uploaded_at'   => current_time( 'mysql' ),
        ) );

        $result_id = (int) $wpdb->insert_id;
        $debug['step_8_repose_results_insert_ok'] = ( $insert_ok !== false );
        $debug['step_8_repose_results_id']        = $result_id;
        $debug['step_8_db_last_error']            = $wpdb->last_error ?: null;

        if ( ! $result_id ) {
            return new WP_REST_Response( array(
                'error' => 'Failed to insert into wp_repose_results.',
                'debug' => $debug,
            ), 500 );
        }

        // ── Step 9: Save all seven HL7 tables ────────────────────────────────
        $hl7_ids = self::process_lab_data( $jsonResult, $order_id, $ref_number, $result_id );

        $debug['step_9_hl7_message_id']  = $hl7_ids['hl7_message_id'];
        $debug['step_9_hl7_patient_id']  = $hl7_ids['hl7_patient_id'];
        $debug['step_9_hl7_visit_id']    = $hl7_ids['hl7_visit_id'];
        $debug['step_9_hl7_order_id']    = $hl7_ids['hl7_order_id'];
        $debug['step_9_hl7_report_ids']  = $hl7_ids['hl7_report_ids'];
        $debug['step_9_hl7_db_errors']   = $hl7_ids['db_errors'] ?? array();

        // Back-fill wc_result_id on report rows
        if ( ! empty( $hl7_ids['hl7_report_ids'] ) ) {
            foreach ( $hl7_ids['hl7_report_ids'] as $rpt_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'repose_hl7_reports',
                    array( 'wc_result_id' => $result_id ),
                    array( 'id' => $rpt_id )
                );
            }
        }

        // ── Step 10: Save order meta ──────────────────────────────────────────
        $order->update_meta_data( '_repose_last_json_result_id', $result_id );
        $order->update_meta_data( '_repose_last_json_filename',  $json_filename );
        $order->update_meta_data( '_repose_hl7_message_id',      $hl7_ids['hl7_message_id'] );
        $order->update_meta_data( '_repose_hl7_patient_id',      $hl7_ids['hl7_patient_id'] );
        $order->save();

        // ── Step 11: Match result to specific patient in registry ─────────────
        // Match by: test name + order_id → update patient_tests row to 'complete'
        // Also try matching by patient name/DOB from HL7 PID segment
        $matched_patient_uid = null;
        if ( class_exists( 'Repose_Patient_Registry' ) ) {
            $hl7_patient     = $jsonResult['patient']  ?? array();
            $hl7_first       = sanitize_text_field( $hl7_patient['firstName'] ?? '' );
            $hl7_last        = sanitize_text_field( $hl7_patient['lastName']  ?? '' );
            $hl7_dob         = sanitize_text_field( $hl7_patient['dateOfBirth'] ?? '' );

            // Get all patient-test rows for this order
            $order_pt_rows = Repose_Patient_Registry::get_order_patient_tests( $order_id );

            foreach ( $order_pt_rows as $pt_row ) {
                $name_match = $hl7_first && $hl7_last
                    && strtolower( $pt_row->forename ) === strtolower( $hl7_first )
                    && strtolower( $pt_row->surname  ) === strtolower( $hl7_last );

                $test_match = stripos( $pt_row->test_name, $test_name ) !== false
                           || stripos( $test_name, $pt_row->test_name ) !== false;

                if ( $name_match || $test_match ) {
                    // Update this patient_test to 'complete' and link result
                    $wpdb->update(
                        $wpdb->prefix . 'repose_patient_tests',
                        array( 'status' => 'complete', 'result_id' => $result_id ),
                        array( 'id' => $pt_row->id )
                    );
                    $matched_patient_uid = $pt_row->patient_uid;
                    $debug['step_11_patient_matched'] = $pt_row->patient_uid;
                    break;
                }
            }

            // If no match yet — fall back to patient 1 on this order
            if ( ! $matched_patient_uid && ! empty( $order_pt_rows ) ) {
                $pt_row = $order_pt_rows[0];
                $wpdb->update(
                    $wpdb->prefix . 'repose_patient_tests',
                    array( 'status' => 'complete', 'result_id' => $result_id ),
                    array( 'id' => $pt_row->id )
                );
                $matched_patient_uid = $pt_row->patient_uid;
                $debug['step_11_patient_fallback'] = $pt_row->patient_uid;
            }

            if ( $matched_patient_uid ) {
                $order->update_meta_data( '_repose_result_patient_uid', $matched_patient_uid );
                $order->save();
            }
        }
        $debug['step_11_matched_patient_uid'] = $matched_patient_uid;

        Repose_Audit_Log::record(
            $order_id, 0, 'json_result_imported',
            "Ref: {$ref_number}, Test: {$test_name}, Result ID: {$result_id}, HL7 msg: {$hl7_ids['hl7_message_id']}"
        );

        $order->add_order_note(
            "Repose: JSON result imported via API. Test: {$test_name}, Result ID: {$result_id}. Pending admin review."
        );

        return new WP_REST_Response( array(
            'success'              => true,
            'result_id'            => $result_id,
            'order_id'             => $order_id,
            'reference'            => $ref_number,
            'test'                 => $test_name,
            'status'               => 'pending_review',
            'matched_patient_uid'  => $matched_patient_uid,
            'hl7_message_id'       => $hl7_ids['hl7_message_id'],
            'hl7_patient_id'       => $hl7_ids['hl7_patient_id'],
            'hl7_visit_id'         => $hl7_ids['hl7_visit_id'],
            'hl7_order_id'         => $hl7_ids['hl7_order_id'],
            'hl7_report_count'     => count( $hl7_ids['hl7_report_ids'] ),
            'message'              => 'Result imported and all HL7 segments saved. Queued for admin review.',
            'debug'                => $debug,
        ), 201 );
    }

    /**
     * AJAX handler: generate (or serve cached) PDF from stored HL7 data.
     *
     * Called when admin clicks "View PDF" on an API-imported result
     * (i.e. a result row whose file was produced by this class, not a
     * manual upload).
     *
     * GET params: result_id, nonce
     *
     * Flow:
     *  1. Verify nonce + permissions.
     *  2. Load the raw JSON from the companion raw-*.json file.
     *  3. If a PDF already exists on disk, stream it immediately.
     *  4. Otherwise regenerate it from the HL7 JSON, update the DB row,
     *     then stream.
     */
    public static function ajax_generate_hl7_pdf() {
        $result_id = (int) ( $_GET['result_id'] ?? 0 );
        $token     = sanitize_text_field( $_GET['token'] ?? '' );
        $nonce     = sanitize_text_field( $_GET['nonce'] ?? '' );

        // ── Auth: signed token (patient email link) OR nonce (admin) ─────────
        $is_admin    = current_user_can( 'manage_woocommerce' );
        $nonce_valid = $nonce && wp_verify_nonce( $nonce, 'repose_download_' . $result_id );
        $token_valid = false;

        global $wpdb;

        $order_id_for_token = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
        ) );

        if ( $token && $order_id_for_token && class_exists( 'HN_Repose_Results_Manager' ) ) {
            $token_valid = HN_Repose_Results_Manager::verify_result_token( $result_id, $order_id_for_token, $token );
        }

        if ( ! $is_admin && ! $nonce_valid && ! $token_valid ) {
            wp_die( 'This link is invalid or has expired. Please check your email for the latest results link.' );
        }

        // Admins/nonces can preview pending; token holders only see reported results
        if ( $is_admin || $nonce_valid ) {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
            ) );
        } else {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d AND status = 'reported'", $result_id
            ) );
        }

        if ( ! $result ) {
            wp_die( ( $is_admin || $nonce_valid )
                ? "Result #{$result_id} not found."
                : 'Your result is not yet available. It will be released once approved by our clinical team.'
            );
        }

        $upload_dir  = wp_upload_dir();
        $results_dir = trailingslashit( $upload_dir['basedir'] ) . 'repose-results/';
        $file_path   = $results_dir . $result->file_path;

        // ── Serve existing file if present ───────────────────────────────────
        if ( file_exists( $file_path ) ) {
            self::stream_file( $file_path );
            return;
        }

        // Try HTML fallback
        $html_path = preg_replace( '/\.pdf$/i', '.html', $file_path );
        if ( file_exists( $html_path ) ) {
            self::stream_file( $html_path );
            return;
        }

        // ── Regenerate PDF from stored raw HL7 JSON ──────────────────────────
        // Find the companion raw JSON file via order meta
        $wc_order      = wc_get_order( $result->order_id );
        $json_filename = $wc_order ? $wc_order->get_meta( '_repose_last_json_filename' ) : '';
        $json_path     = $json_filename ? ( $results_dir . $json_filename ) : '';

        $lab_data = array();
        if ( $json_path && file_exists( $json_path ) ) {
            $stored   = json_decode( file_get_contents( $json_path ), true );
            $lab_data = $stored['hl7_result'] ?? $stored;
        }

        // If no stored JSON, try to re-fetch from the lab API using the HL7 message table
        if ( empty( $lab_data ) ) {
            $hl7_msg = $wpdb->get_var( $wpdb->prepare(
                "SELECT message FROM {$wpdb->prefix}repose_hl7_messages
                 WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1",
                $result->order_id
            ) );
            if ( $hl7_msg ) {
                $lab_data = json_decode( $hl7_msg, true );
            }
        }

        if ( empty( $lab_data ) || empty( $wc_order ) ) {
            wp_die( '<p><strong>Cannot regenerate PDF.</strong></p>
                     <p>The original HL7 JSON could not be located. Please re-import the result.</p>' );
        }

        // Regenerate
        $new_filename = sanitize_file_name( 'json-result-' . $result->order_id . '-' . time() . '.pdf' );
        $new_filepath = $results_dir . $new_filename;

        //$pdf_result = self::generate_pdf( $lab_data, $wc_order, $new_filepath );
        //if ( is_wp_error( $pdf_result ) ) {
            // Fall back to HTML
            $new_filename = str_replace( '.pdf', '.html', $new_filename );
            $new_filepath = $results_dir . $new_filename;
            file_put_contents( $new_filepath, self::build_html_report( $lab_data, $wc_order ) );
        //}

        // Update DB row with new filename
        $wpdb->update(
            $wpdb->prefix . 'repose_results',
            array( 'file_path' => $new_filename ),
            array( 'id' => $result_id )
        );

        Repose_Audit_Log::record(
            (int) $result->order_id, get_current_user_id(),
            'hl7_pdf_regenerated',
            "Result ID {$result_id}, new file: {$new_filename}"
        );

        self::stream_file( $new_filepath );
    }

    /**
     * Build an HTML report from stored HL7 data in the database.
     * Called by ajax_serve_file when the physical PDF/HTML file is missing.
     *
     * @param int $result_id
     * @return string|false  HTML string, or false if no data found.
     */
    public static function build_report_from_db( int $result_id ) {
        global $wpdb;

        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_results WHERE id = %d", $result_id
        ) );
        if ( ! $result ) return false;

        $wc_order = wc_get_order( $result->order_id );
        if ( ! $wc_order ) return false;

        $results_dir   = trailingslashit( wp_upload_dir()['basedir'] ) . 'repose-results/';
        $lab_data      = array();

        // 1. Try companion raw JSON file stored at import time
        $json_filename = $wc_order->get_meta( '_repose_last_json_filename' );
        if ( $json_filename ) {
            $json_path = $results_dir . $json_filename;
            if ( file_exists( $json_path ) ) {
                $stored   = json_decode( file_get_contents( $json_path ), true );
                $lab_data = $stored['hl7_result'] ?? $stored;
            }
        }

        // 2. Fall back to raw HL7 message stored in the DB
        if ( empty( $lab_data ) ) {
            $hl7_msg = $wpdb->get_var( $wpdb->prepare(
                "SELECT message FROM {$wpdb->prefix}repose_hl7_messages
                 WHERE wc_order_id = %d ORDER BY id DESC LIMIT 1",
                $result->order_id
            ) );
            if ( $hl7_msg ) {
                $lab_data = json_decode( $hl7_msg, true );
            }
        }

        if ( empty( $lab_data ) ) return false;

        return self::build_html_report( $lab_data, $wc_order );
    }

    /**
     * Stream a file to the browser (inline PDF or HTML).
     */
    private static function stream_file( string $file_path ) {
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $mime_type = ( $ext === 'html' ) ? 'text/html; charset=UTF-8' : 'application/pdf';

        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Cache-Control: no-store' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $file_path );
        exit;
    }

    /**
     * Authenticate with the lab API and fetch the full HL7 result for a given key.
     *
     * Returns array with keys:
     *   'json' => parsed HL7 data array
     *   'raw'  => raw response body string (for audit)
     *
     * On failure returns array( 'json' => array(), 'raw' => null ).
     */
    private static function callAPi( string $key ): array {
        $base = array(
            'json'         => array(),
            'raw'          => null,
            'auth_error'   => null,
            'fetch_error'  => null,
            'auth_status'  => null,
            'fetch_status' => null,
            'auth_body'    => null,
        );

        $api_url      = get_option( 'API_URL',      'http://35.178.62.52/api' );
        $api_username = get_option( 'API_USERNAME',  'admin@admin.com' );
        $api_password = get_option( 'API_PASSWORD',  'admin' );

        if ( ! $api_url || ! $api_username || ! $api_password ) {
            $base['auth_error'] = 'API credentials not configured in WordPress options.';
            error_log( 'Repose: ' . $base['auth_error'] );
            return $base;
        }

        // ── Step A: Authenticate ─────────────────────────────────────────────
        $auth_url      = rtrim( $api_url, '/' ) . '/auth/login';
        $auth_response = wp_remote_post( $auth_url, array(
            'body'    => array( 'email' => $api_username, 'password' => $api_password ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $auth_response ) ) {
            $base['auth_error'] = $auth_response->get_error_message();
            error_log( 'Repose callAPi auth WP_Error: ' . $base['auth_error'] );
            return $base;
        }

        $base['auth_status'] = wp_remote_retrieve_response_code( $auth_response );
        $auth_body_raw       = wp_remote_retrieve_body( $auth_response );
        $base['auth_body']   = $auth_body_raw;  // exposed in debug so you can see the full login response
        $auth_data           = json_decode( $auth_body_raw, true );
        $access_token        = $auth_data['data']['access_token'] ?? '';

        if ( ! $access_token ) {
            $base['auth_error'] = "Auth succeeded (HTTP {$base['auth_status']}) but no access_token found. "
                                . "auth_body: " . substr( $auth_body_raw, 0, 500 );
            error_log( 'Repose callAPi: ' . $base['auth_error'] );
            return $base;
        }

        // ── Step B: Fetch HL7 file by key ────────────────────────────────────
        $fetch_url       = rtrim( $api_url, '/' ) . '/hl7/file/' . rawurlencode( $key );
        $result_response = wp_remote_get( $fetch_url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $result_response ) ) {
            $base['fetch_error'] = $result_response->get_error_message();
            error_log( 'Repose callAPi fetch WP_Error: ' . $base['fetch_error'] );
            return $base;
        }

        $base['fetch_status'] = wp_remote_retrieve_response_code( $result_response );
        $raw_body             = wp_remote_retrieve_body( $result_response );
        $base['raw']          = $raw_body;
        $parsed               = json_decode( $raw_body, true );

        if ( $base['fetch_status'] !== 200 ) {
            $base['fetch_error'] = "HTTP {$base['fetch_status']} from {$fetch_url}. Body: " . substr( $raw_body, 0, 500 );
            error_log( 'Repose callAPi: ' . $base['fetch_error'] );
            return $base;
        }

        // Try every plausible path in the response envelope:
        // $parsed['data']['json'], $parsed['data'], $parsed itself
        if ( ! empty( $parsed['data']['json'] ) && is_array( $parsed['data']['json'] ) ) {
            $base['json'] = $parsed['data']['json'];
        } elseif ( ! empty( $parsed['data'] ) && is_array( $parsed['data'] ) ) {
            $base['json'] = $parsed['data'];
        } elseif ( is_array( $parsed ) && ! empty( $parsed ) ) {
            $base['json'] = $parsed;
        } else {
            $base['fetch_error'] = 'Response body could not be parsed as JSON or was empty. Raw: ' . substr( $raw_body, 0, 500 );
        }

        return $base;
    }

    // -----------------------------------------------------------------------
    // Extract reference number from various JSON structures
    // -----------------------------------------------------------------------

    /**
     * Extract the reference number and key from the inbound payload.
     *
     * Key format examples:
     *   IN/20210208104216620_TESTREF1.hl7   → order_ref_id = TESTREF1
     *   IN/20260317120000000_R25C290069.hl7 → order_ref_id = R25C290069
     *
     * Priority chain for order_ref_id:
     *   1. Explicit 'reference_number' field in payload  (caller override)
     *   2. Last underscore-segment of the key filename   (standard TDL format)
     *   3. Whole filename without extension              (fallback)
     *
     * @param  array $data  Inbound request body (must contain 'key')
     * @return array { key: string, order_ref_id: string }
     */
    private static function extract_ref( array $data ): array {
        $input = sanitize_text_field( $data['key'] ?? '' );

        // Priority 1: caller explicitly supplied a reference_number override
        if ( ! empty( $data['reference_number'] ) ) {
            return array(
                'key'          => $input,
                'order_ref_id' => sanitize_text_field( $data['reference_number'] ),
            );
        }

        // Priority 2: derive from the key filename
        // Strip directory prefix (e.g. "IN/"), drop extension, take the part after the last "_"
        // e.g. "IN/20210208104216620_TESTREF1.hl7" → basename "20210208104216620_TESTREF1.hl7"
        //      → no_ext "20210208104216620_TESTREF1" → ref "TESTREF1"
        $basename       = basename( $input );
        $no_ext         = preg_replace( '/\.[^.]+$/', '', $basename );
        $underscore_pos = strrpos( $no_ext, '_' );
        if ( $underscore_pos !== false ) {
            $ref = substr( $no_ext, $underscore_pos + 1 );
            if ( $ref !== '' ) {
                return array( 'key' => $input, 'order_ref_id' => $ref );
            }
        }

        // Priority 3: fall back to whole filename without extension
        if ( $no_ext !== '' ) {
            return array( 'key' => $input, 'order_ref_id' => $no_ext );
        }

        return array( 'key' => $input, 'order_ref_id' => '' );
    }

    // -----------------------------------------------------------------------
    // Generate PDF from JSON data using ReportLab via Python (if available)
    // or fall back to HTML
    // -----------------------------------------------------------------------

    private static function generate_pdf( array $data, WC_Order $order, string $filepath ) {
        $html = self::build_html_report( $data, $order );

        // Try wkhtmltopdf
        $wk = trim( (string) shell_exec( 'which wkhtmltopdf 2>/dev/null' ) );
        if ( $wk ) {
            $html_tmp = $filepath . '.tmp.html';
            file_put_contents( $html_tmp, $html );
            $cmd = escapeshellcmd( $wk ) . ' --quiet --enable-local-file-access '
                 . escapeshellarg( $html_tmp ) . ' ' . escapeshellarg( $filepath ) . ' 2>/dev/null';
            exec( $cmd, $out, $ret );
            @unlink( $html_tmp );
            if ( $ret === 0 && file_exists( $filepath ) ) return true;
        }

        // Try Python + reportlab
        $python = trim( (string) shell_exec( 'which python3 2>/dev/null' ) );
        if ( $python ) {
            $result = self::python_pdf( $data, $order, $filepath, $python );
            if ( ! is_wp_error( $result ) ) return true;
        }

        return new WP_Error( 'pdf_failed', 'PDF generation not available — stored as HTML.' );
    }

    private static function python_pdf( array $data, WC_Order $order, string $filepath, string $python ) {
        $patient  = $data['patient'] ?? array();
        $reports  = $data['reports'] ?? array();
        $order_meta = array(
            'forename' => $order->get_meta('_repose_patient_forename') ?: ($patient['patientName']['givenName']  ?? ''),
            'surname'  => $order->get_meta('_repose_patient_surname')  ?: ($patient['patientName']['familyName'] ?? ''),
            'dob'      => $order->get_meta('_repose_date_of_birth')    ?: self::format_dob( $patient['dateOfBirth'] ?? '' ),
            'sex'      => $order->get_meta('_repose_sex_at_birth')     ?: ($patient['sex'] ?? ''),
            'email'    => $order->get_billing_email(),
            'ref'      => $order->get_meta('_repose_reference_number'),
        );

        $company = array(
            'name'    => get_option('repose_company_name',    'Repose Healthcare Ltd'),
            'address' => get_option('repose_company_address', ''),
            'email'   => get_option('repose_company_email',   ''),
            'website' => get_option('repose_company_website', ''),
            'color'   => get_option('repose_brand_color',     '#1a6e8c'),
        );

        // Build observations table data
        $obs_rows = array();
        foreach ( $reports as $report ) {
            $test_name = $report['universalServiceId']['text'] ?? 'Test';
            foreach ( $report['observations'] ?? array() as $obs ) {
                if ( empty( $obs['observationValue'] ) || $obs['observationValue'] === '.' ) continue;
                $obs_rows[] = array(
                    'test'   => $obs['observationIdentifier']['text'] ?? $test_name,
                    'value'  => $obs['observationValue'] ?? '',
                    'units'  => $obs['units'] ?? '',
                    'range'  => $obs['referenceRange'] ?? '',
                    'flag'   => $obs['abnormalFlag'] ?? '',
                    'status' => $obs['observationResultStatus'] ?? '',
                );
            }
            // Notes
            $notes = array_map( function($n){ return $n['sourceOfComment'] ?? ''; }, $report['notes'] ?? array() );
            $obs_rows[] = array( 'is_notes' => true, 'notes' => array_filter($notes) );
        }

        $script = self::build_python_script( $order_meta, $company, $obs_rows, $filepath );
        $script_file = $filepath . '.py';
        file_put_contents( $script_file, $script );

        exec( escapeshellcmd( $python ) . ' ' . escapeshellarg( $script_file ) . ' 2>&1', $output, $ret );
        @unlink( $script_file );

        if ( $ret !== 0 || ! file_exists( $filepath ) ) {
            return new WP_Error( 'python_failed', implode( "\n", $output ) );
        }
        return true;
    }

    private static function build_python_script( array $meta, array $company, array $obs_rows, string $out ): string {
        $obs_json  = addslashes( json_encode( $obs_rows ) );
        $meta_json = addslashes( json_encode( $meta ) );
        $co_json   = addslashes( json_encode( $company ) );
        $out_esc   = addslashes( $out );

        return <<<PY
import json, sys
from datetime import date

try:
    from reportlab.lib.pagesizes import A4
    from reportlab.lib import colors
    from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable
    from reportlab.lib.units import mm
    from reportlab.lib.enums import TA_CENTER, TA_LEFT
except ImportError:
    sys.exit(1)

meta    = json.loads('{$meta_json}')
company = json.loads('{$co_json}')
obs     = json.loads('{$obs_json}')
out     = '{$out_esc}'

BLUE  = colors.HexColor(company.get('color','#1a6e8c'))
LIGHT = colors.HexColor('#e8f4f8')
GRAY  = colors.HexColor('#f5f5f5')
DARK  = colors.HexColor('#333333')
RED   = colors.HexColor('#c0392b')
GREEN = colors.HexColor('#2e7d4f')

styles = getSampleStyleSheet()
s_normal = ParagraphStyle('n', fontName='Helvetica', fontSize=9, textColor=DARK, leading=14)
s_bold   = ParagraphStyle('b', fontName='Helvetica-Bold', fontSize=9, textColor=DARK, leading=14)
s_small  = ParagraphStyle('sm', fontName='Helvetica', fontSize=8, textColor=colors.HexColor('#666666'), leading=12)
s_head   = ParagraphStyle('h', fontName='Helvetica-Bold', fontSize=11, textColor=BLUE, leading=16)

doc = SimpleDocTemplate(out, pagesize=A4, leftMargin=15*mm, rightMargin=15*mm, topMargin=12*mm, bottomMargin=18*mm)
story = []
W = A4[0] - 30*mm

# Header
hdr_data = [[
    Paragraph(company.get('name','Repose Healthcare'), ParagraphStyle('ht',fontName='Helvetica-Bold',fontSize=18,textColor=colors.white)),
    Paragraph(company.get('address','') + '<br/>' + company.get('email','') + '<br/>' + company.get('website',''),
              ParagraphStyle('hr',fontName='Helvetica',fontSize=9,textColor=colors.HexColor('#d0eaf3'),leading=14)),
]]
hdr = Table(hdr_data, colWidths=[W*0.6, W*0.4])
hdr.setStyle(TableStyle([
    ('BACKGROUND',(0,0),(-1,-1),BLUE),
    ('TOPPADDING',(0,0),(-1,-1),14),('BOTTOMPADDING',(0,0),(-1,-1),14),
    ('LEFTPADDING',(0,0),(0,-1),14),('RIGHTPADDING',(-1,0),(-1,-1),14),
    ('VALIGN',(0,0),(-1,-1),'MIDDLE'),
]))
story.append(hdr)

# Ref band
ref_data = [[
    Paragraph('Reference: <b>' + str(meta.get('ref','')) + '</b>', ParagraphStyle('rb',fontName='Helvetica',fontSize=10,textColor=colors.HexColor('#d0eaf3'))),
    Paragraph('Report Date: <b>' + date.today().strftime('%d %B %Y') + '</b>', ParagraphStyle('rd',fontName='Helvetica',fontSize=10,textColor=colors.HexColor('#d0eaf3'))),
]]
ref_tbl = Table(ref_data, colWidths=[W*0.6, W*0.4])
ref_tbl.setStyle(TableStyle([
    ('BACKGROUND',(0,0),(-1,-1),colors.HexColor('#155f7a')),
    ('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),6),
    ('LEFTPADDING',(0,0),(0,-1),14),('RIGHTPADDING',(-1,0),(-1,-1),14),
]))
story.append(ref_tbl)
story.append(Spacer(1,6*mm))

# Patient info
story.append(Paragraph('Patient Information', s_head))
story.append(HRFlowable(width=W, thickness=0.5, color=colors.HexColor('#ccd7df'), spaceAfter=4))
pt_data = [
    [Paragraph('Forename', s_bold), Paragraph(meta.get('forename',''), s_normal),
     Paragraph('Surname',  s_bold), Paragraph(meta.get('surname',''),  s_normal)],
    [Paragraph('Date of Birth', s_bold), Paragraph(meta.get('dob',''), s_normal),
     Paragraph('Sex at Birth',  s_bold), Paragraph(str(meta.get('sex','')).capitalize(), s_normal)],
    [Paragraph('Email', s_bold), Paragraph(meta.get('email',''), s_normal),
     Paragraph('Reference', s_bold), Paragraph(meta.get('ref',''), s_normal)],
]
pt = Table(pt_data, colWidths=[W*0.18,W*0.32,W*0.18,W*0.32])
pt.setStyle(TableStyle([
    ('ROWBACKGROUNDS',(0,0),(-1,-1),[colors.white,GRAY]),
    ('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),5),
    ('LEFTPADDING',(0,0),(-1,-1),8),
    ('GRID',(0,0),(-1,-1),0.25,colors.HexColor('#ccd7df')),
]))
story.append(pt)
story.append(Spacer(1,6*mm))

# Results
story.append(Paragraph('Test Results', s_head))
story.append(HRFlowable(width=W, thickness=0.5, color=colors.HexColor('#ccd7df'), spaceAfter=4))

results_rows = [[
    Paragraph('Test / Analyte', s_bold),
    Paragraph('Result', s_bold),
    Paragraph('Units', s_bold),
    Paragraph('Ref Range', s_bold),
    Paragraph('Flag', s_bold),
]]
notes_text = []
for row in obs:
    if row.get('is_notes'):
        notes_text.extend(row.get('notes',[]))
        continue
    flag = row.get('flag','')
    val  = str(row.get('value',''))
    fp   = Paragraph(flag, ParagraphStyle('flag',fontName='Helvetica-Bold',fontSize=9,
           textColor=RED if flag in ('H','A') else (colors.HexColor('#856404') if flag=='L' else GREEN)))
    vp   = Paragraph(val,  ParagraphStyle('val', fontName='Helvetica-Bold',fontSize=9,textColor=DARK))
    results_rows.append([
        Paragraph(row.get('test',''), s_normal),
        vp,
        Paragraph(row.get('units',''),  s_small),
        Paragraph(row.get('range',''),  s_small),
        fp,
    ])

if len(results_rows) > 1:
    rt = Table(results_rows, colWidths=[W*0.38,W*0.18,W*0.12,W*0.18,W*0.14])
    row_styles = [('BACKGROUND',(0,0),(-1,0),BLUE),('TEXTCOLOR',(0,0),(-1,0),colors.white),
                  ('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),5),
                  ('LEFTPADDING',(0,0),(-1,-1),7),('GRID',(0,0),(-1,-1),0.25,colors.HexColor('#ccd7df'))]
    for i in range(1,len(results_rows)):
        row_styles.append(('BACKGROUND',(0,i),(-1,i),colors.white if i%2==0 else GRAY))
    rt.setStyle(TableStyle(row_styles))
    story.append(rt)

if notes_text:
    story.append(Spacer(1,4*mm))
    story.append(Paragraph('Laboratory Notes', s_head))
    story.append(HRFlowable(width=W, thickness=0.5, color=colors.HexColor('#ccd7df'), spaceAfter=4))
    for n in notes_text:
        if n: story.append(Paragraph(n, s_small))

story.append(Spacer(1,6*mm))
disc = Table([[Paragraph(
    'This report is confidential and intended solely for the named patient. '
    'Results should be interpreted in conjunction with clinical findings. '
    'If you have concerns, please consult a healthcare professional.',
    ParagraphStyle('disc',fontName='Helvetica',fontSize=8,textColor=colors.HexColor('#555555'),leading=12)
)]], colWidths=[W])
disc.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,-1),LIGHT),
    ('LEFTPADDING',(0,0),(-1,-1),10),('RIGHTPADDING',(0,0),(-1,-1),10),
    ('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8),
    ('BOX',(0,0),(-1,-1),0.5,BLUE)]))
story.append(disc)

doc.build(story)
print('OK')
PY;
    }

    // -----------------------------------------------------------------------
    // HTML report fallback (used when PDF generation unavailable)
    // -----------------------------------------------------------------------

    private static function build_html_report( array $data, WC_Order $order ): string {
        $patient  = $data['patient'] ?? array();
        $reports  = $data['reports'] ?? array();
        $ref      = $order->get_meta('_repose_reference_number');
        $forename = $order->get_meta('_repose_patient_forename') ?: ($patient['patientName']['givenName']  ?? '');
        $surname  = $order->get_meta('_repose_patient_surname')  ?: ($patient['patientName']['familyName'] ?? '');
        $dob      = $order->get_meta('_repose_date_of_birth')    ?: self::format_dob( $patient['dateOfBirth'] ?? '' );
        $sex      = $order->get_meta('_repose_sex_at_birth')     ?: ($patient['sex'] ?? '');
        $email    = $order->get_billing_email();
        $company  = get_option('repose_company_name',    'Repose Healthcare Ltd');
        $address  = get_option('repose_company_address', '');
        $co_email = get_option('repose_company_email',   '');
        $website  = get_option('repose_company_website', '');
        $color    = get_option('repose_brand_color',     '#1a6e8c');
        $today    = date_i18n( get_option('date_format') );

        $obs_html = '';
        $notes_html = '';
        foreach ( $reports as $report ) {
            $test_label = esc_html( $report['universalServiceId']['text'] ?? 'Test' );
            $obs_html .= "<tr style='background:#e8f4f8'><td colspan='5' style='padding:8px 12px;font-weight:600;color:{$color};'>{$test_label}</td></tr>";
            foreach ( $report['observations'] ?? array() as $obs ) {
                if ( empty( $obs['observationValue'] ) || $obs['observationValue'] === '.' ) continue;
                $flag     = esc_html( $obs['abnormalFlag'] ?? '' );
                $flag_col = in_array( $flag, array('H','A') ) ? '#c0392b' : ( $flag === 'L' ? '#856404' : '#2e7d4f' );
                $obs_html .= '<tr style="border-bottom:1px solid #eee;">'
                    . '<td style="padding:7px 12px;">'  . esc_html( $obs['observationIdentifier']['text'] ?? '' ) . '</td>'
                    . '<td style="padding:7px 12px;font-weight:600;">' . esc_html( $obs['observationValue'] ) . '</td>'
                    . '<td style="padding:7px 12px;color:#888;">' . esc_html( $obs['units'] ?? '' ) . '</td>'
                    . '<td style="padding:7px 12px;color:#888;">' . esc_html( $obs['referenceRange'] ?? '' ) . '</td>'
                    . '<td style="padding:7px 12px;font-weight:600;color:' . $flag_col . ';">' . $flag . '</td>'
                    . '</tr>';
            }
            $notes = array_filter( array_map( function($n){ return $n['sourceOfComment'] ?? ''; }, $report['notes'] ?? array() ) );
            if ( $notes ) {
                $notes_html .= '<div style="margin-top:16px;padding:12px 16px;background:#fff8e1;border-left:3px solid #f0ad00;font-size:12px;color:#555;">';
                $notes_html .= '<strong>Laboratory Notes:</strong><br>' . implode( '<br>', array_map('esc_html', $notes) );
                $notes_html .= '</div>';
            }
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;color:#333;margin:0;padding:0}
table{width:100%;border-collapse:collapse}th{text-align:left}
</style></head><body>
<div style="background:' . esc_attr($color) . ';padding:20px 28px;display:flex;justify-content:space-between;align-items:center;">
  <div style="font-size:22px;font-weight:700;color:#fff;">' . esc_html($company) . '</div>
  <div style="font-size:12px;color:rgba(255,255,255,.8);text-align:right;">' . esc_html($address) . '<br>' . esc_html($co_email) . '<br>' . esc_html($website) . '</div>
</div>
<div style="background:#155f7a;padding:8px 28px;display:flex;justify-content:space-between;">
  <span style="color:#d0eaf3;font-size:13px;">Reference: <strong>' . esc_html($ref) . '</strong></span>
  <span style="color:#d0eaf3;font-size:13px;">Report Date: <strong>' . esc_html($today) . '</strong></span>
</div>
<div style="padding:20px 28px;">
  <h3 style="color:' . esc_attr($color) . ';border-bottom:2px solid ' . esc_attr($color) . ';padding-bottom:6px;">Patient Information</h3>
  <table style="border:1px solid #ddd;margin-bottom:20px;">
    <tr style="background:#f5f5f5;"><td style="padding:7px 12px;font-weight:600;width:150px;">Forename</td><td style="padding:7px 12px;">' . esc_html($forename) . '</td><td style="padding:7px 12px;font-weight:600;width:150px;">Surname</td><td style="padding:7px 12px;">' . esc_html($surname) . '</td></tr>
    <tr><td style="padding:7px 12px;font-weight:600;">Date of Birth</td><td style="padding:7px 12px;">' . esc_html($dob) . '</td><td style="padding:7px 12px;font-weight:600;">Sex at Birth</td><td style="padding:7px 12px;">' . esc_html(ucfirst($sex)) . '</td></tr>
    <tr style="background:#f5f5f5;"><td style="padding:7px 12px;font-weight:600;">Email</td><td style="padding:7px 12px;">' . esc_html($email) . '</td><td style="padding:7px 12px;font-weight:600;">Reference</td><td style="padding:7px 12px;">' . esc_html($ref) . '</td></tr>
  </table>
  <h3 style="color:' . esc_attr($color) . ';border-bottom:2px solid ' . esc_attr($color) . ';padding-bottom:6px;">Test Results</h3>
  <table style="border:1px solid #ddd;margin-bottom:16px;">
    <thead><tr style="background:' . esc_attr($color) . ';color:#fff;">
      <th style="padding:8px 12px;">Test / Analyte</th>
      <th style="padding:8px 12px;">Result</th>
      <th style="padding:8px 12px;">Units</th>
      <th style="padding:8px 12px;">Ref Range</th>
      <th style="padding:8px 12px;">Flag</th>
    </tr></thead>
    <tbody>' . $obs_html . '</tbody>
  </table>
  ' . $notes_html . '
  <div style="margin-top:20px;padding:12px 16px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:4px;font-size:12px;color:#555;">
    This report is confidential and intended solely for the named patient. Results should be interpreted in conjunction with clinical findings.
  </div>
</div></body></html>';
    }

    private static function format_dob( string $raw ): string {
        if ( strlen($raw) >= 8 ) {
            $y = substr($raw,0,4); $m = substr($raw,4,2); $d = substr($raw,6,2);
            return $d . '/' . $m . '/' . $y;
        }
        return $raw;
    }
}
