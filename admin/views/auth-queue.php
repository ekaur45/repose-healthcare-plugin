<?php
/**
 * Manual Authorisation Queue view.
 *
 * Fixes applied (v1.4.4):
 *  1. Patient info moved to top-left of edit panel (first section shown).
 *  2. Test checkboxes use proper <label> wrapping — reliably clickable/tappable.
 *  3. Patient 1 assigned tests shown directly below Patient 1 details (not below Patient 2).
 *  4. Tracking "Add Tracking" button only appears on actual transmission rows,
 *     not on manual_approved (authorisation) rows — handled in transmission-log.php.
 *  (Duplicate-test warning is handled client-side in checkout-fields.js.)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$auto_transmit_on = get_option( 'repose_auto_transmit', '0' ) === '1';
?>
<style>
#rh-queue-table th.col-tests,
#rh-queue-table td.col-tests {
    min-width: 160px;
    white-space: normal;
    word-break: break-word;
}
.rh-field-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 3px;
    color: #374151;
}
.rh-field-input {
    width: 100%;
    padding: 7px 9px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
    box-sizing: border-box;
}
.rh-field-readonly {
    background: #f3f4f6;
    color: #6b7280;
    cursor: not-allowed;
}
.rh-section-title {
    font-size: 13px;
    font-weight: 700;
    color: #1a6e8c;
    margin: 16px 0 10px;
    padding-bottom: 5px;
    border-bottom: 1px solid #d0e8f2;
}
.rh-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
.rh-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 12px; }
.rh-extra-patient { margin-bottom: 12px; }
/* Patient test assignment rows in admin edit panel */
.rh-ap-test-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 11px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    margin-bottom: 7px;
    cursor: pointer;
    user-select: none;
    transition: background .15s, border-color .15s;
}
.rh-ap-test-row:hover { background: #f0f7fb; }
.rh-ap-test-row.checked { background: #eff8ff; border-color: #1a6e8c; }
.rh-ap-test-row input[type=checkbox] {
    width: 16px; height: 16px;
    accent-color: #1a6e8c;
    cursor: pointer;
    flex-shrink: 0;
}
</style>

<div class="wrap repose-admin">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px;">
        <h1 style="margin:0;">Manual Authorisation Queue</h1>

        <!-- Auto-Transmit Toggle -->
        <div style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #ddd;
                    border-radius:8px;padding:10px 16px;box-shadow:0 1px 3px rgba(0,0,0,.07);">
            <div>
                <div style="font-size:13px;font-weight:600;color:#333;">Auto-Transmit New Orders</div>
                <div id="rh-toggle-desc" style="font-size:11px;color:#888;margin-top:2px;">
                    <?php echo $auto_transmit_on
                        ? 'Clean single-test orders skip this queue and go straight to the lab.'
                        : 'Every new order lands here for manual review before being sent to the lab.'; ?>
                </div>
            </div>
            <button id="rh-auto-toggle"
                    data-state="<?php echo $auto_transmit_on ? '1' : '0'; ?>"
                    onclick="rhToggleAutoTransmit()"
                    style="position:relative;width:52px;height:28px;border-radius:14px;border:none;cursor:pointer;
                           transition:background .25s;outline:none;flex-shrink:0;
                           background:<?php echo $auto_transmit_on ? '#2e7d4f' : '#ccc'; ?>;">
                <span id="rh-toggle-knob"
                      style="position:absolute;top:3px;width:22px;height:22px;border-radius:50%;
                             background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.3);transition:left .25s;
                             left:<?php echo $auto_transmit_on ? '27px' : '3px'; ?>;"></span>
            </button>
            <span id="rh-auto-label"
                  style="font-size:13px;font-weight:700;min-width:28px;
                         color:<?php echo $auto_transmit_on ? '#2e7d4f' : '#999'; ?>;">
                <?php echo $auto_transmit_on ? 'ON' : 'OFF'; ?>
            </span>
            <span id="rh-toggle-spinner" style="display:none;font-size:12px;color:#888;">Saving&hellip;</span>
        </div>
    </div>

    <div id="rh-notice" style="display:none;padding:10px 14px;margin:10px 0;border-radius:4px;font-size:13px;"></div>

    <?php if ( get_option( 'repose_auto_transmit', '0' ) === '1' ) : ?>
    <div style="margin-bottom:12px;">
        <button id="rh-transmit-all" onclick="rhTransmitAll()" class="button button-primary"
                style="background:#2e7d4f;border-color:#1f5c39;">
            &#9654; Transmit All Pending Now
        </button>
        <span style="font-size:12px;color:#666;margin-left:10px;">
            Immediately transmits all orders currently in Pending status to the lab.
        </span>
    </div>
    <?php endif; ?>

    <?php
    global $wpdb;

    $tab = sanitize_text_field( $_GET['rh_tab'] ?? 'pending' );
    $allowed_tabs = array( 'pending', 'approved', 'rejected', 'all' );
    if ( ! in_array( $tab, $allowed_tabs, true ) ) $tab = 'pending';

    $where_map = array(
        'pending'  => "q.status = 'pending'",
        'approved' => "q.status = 'approved'",
        'rejected' => "q.status = 'rejected'",
        'all'      => '1=1',
    );
    $where = $where_map[ $tab ];

    $items = $wpdb->get_results(
        "SELECT q.* FROM {$wpdb->prefix}repose_order_queue q
         WHERE {$where}
         ORDER BY q.created_at DESC"
    );

    $counts = $wpdb->get_results(
        "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}repose_order_queue GROUP BY status",
        OBJECT_K
    );
    $cnt   = function( $s ) use ( $counts ) { return isset( $counts[$s] ) ? (int) $counts[$s]->cnt : 0; };
    $total = array_sum( array_column( (array) $counts, 'cnt' ) );

    $base_url = admin_url( 'admin.php?page=repose-auth-queue' );
    $tabs = array(
        'pending'  => array( 'Pending',  $cnt('pending'),  '#856404', '#fff3cd' ),
        'approved' => array( 'Approved', $cnt('approved'), '#2e7d4f', '#d4edda' ),
        'rejected' => array( 'Rejected', $cnt('rejected'), '#842029', '#f8d7da' ),
        'all'      => array( 'All',      $total,           '#1a6e8c', '#e8f4f8' ),
    );
    ?>

    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #ddd;padding-bottom:0;">
        <?php foreach ( $tabs as $key => list( $label, $count, $fc, $bg ) ) :
            $active = $tab === $key; ?>
        <a href="<?php echo esc_url( $base_url . '&rh_tab=' . $key ); ?>"
           style="text-decoration:none;padding:8px 14px;font-size:13px;font-weight:600;
                  border-radius:6px 6px 0 0;
                  background:<?php echo $active ? '#fff' : '#f4f4f4'; ?>;
                  color:<?php echo $active ? '#1a6e8c' : '#555'; ?>;
                  border:1px solid <?php echo $active ? '#ddd' : 'transparent'; ?>;
                  border-bottom:<?php echo $active ? '2px solid #fff' : '1px solid transparent'; ?>;
                  margin-bottom:-2px;">
            <?php echo esc_html( $label ); ?>
            <span style="margin-left:4px;background:<?php echo $bg ?>;color:<?php echo $fc ?>;
                         border-radius:10px;padding:1px 7px;font-size:11px;">
                <?php echo $count; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( empty( $items ) ) : ?>
        <p style="padding:16px;background:#f0f7fb;border:1px dashed #b3d4e0;border-radius:6px;">
            <?php echo $tab === 'pending'
                ? '&#10003; No orders currently require manual authorisation.'
                : 'No records found in this view.'; ?>
        </p>
    <?php else : ?>

    <p style="color:#888;font-size:12px;margin:0 0 8px;">Showing newest first &mdash; <?php echo count($items); ?> record(s)</p>

    <table class="wp-list-table widefat fixed striped" id="rh-queue-table">
        <thead>
            <tr>
                <th style="width:70px">Order</th>
                <th style="width:150px">Patient</th>
                <th class="col-tests" style="width:180px;">Tests</th>
                <th style="width:200px">Flag Reason</th>
                <th style="width:90px">Type</th>
                <th style="width:100px">Status</th>
                <th style="width:130px">Queued &#9660;</th>
                <?php if ( $tab === 'pending' ) : ?>
                <th style="width:270px">Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $items as $item ) :
            $order = wc_get_order( $item->order_id );
            if ( ! $order ) continue;

            $forename   = $order->get_meta('_repose_patient_forename') ?: $order->get_billing_first_name();
            $surname    = $order->get_meta('_repose_patient_surname')  ?: $order->get_billing_last_name();
            $dob        = $order->get_meta('_repose_date_of_birth');
            $sex        = $order->get_meta('_repose_sex_at_birth');
            $p1_notes   = $order->get_meta('_repose_additional_notes');
            $otype      = $order->get_meta('_repose_order_type') ?: 'self_collect';
            $ref_number = $order->get_meta('_repose_reference_number') ?: '—';
            $tests      = Repose_Order_Validator::get_tests( $order );
            $oid        = (int) $item->order_id;

            // Shipping address fields
            $ship_addr1  = $order->get_shipping_address_1();
            $ship_addr2  = $order->get_shipping_address_2();
            $ship_city   = $order->get_shipping_city();
            $ship_county = $order->get_shipping_state();
            $ship_post   = $order->get_shipping_postcode();
            if ( ! $ship_addr1 ) {
                $ship_addr1  = $order->get_billing_address_1();
                $ship_addr2  = $order->get_billing_address_2();
                $ship_city   = $order->get_billing_city();
                $ship_county = $order->get_billing_state();
                $ship_post   = $order->get_billing_postcode();
            }

            // Patient 1 test names
            $p1_tests_meta  = (array) $order->get_meta( '_repose_patient_1_tests' );
            $p1_test_names  = implode( ', ', array_filter( array_map( function($t){ return $t['name'] ?? ''; }, $p1_tests_meta ) ) );
            if ( ! $p1_test_names ) {
                $p1_test_names = implode( ', ', $tests ); // fallback to all order tests
            }

            // Additional patients
            $additional_patients = (array) $order->get_meta( '_repose_additional_patients' );

            // All product IDs (for test assignment checkboxes in edit panel)
            $all_order_items = array();
            foreach ( $order->get_items() as $item_obj ) {
                $all_order_items[] = array(
                    'product_id' => $item_obj->get_product_id(),
                    'name'       => $item_obj->get_name(),
                );
            }

            $status_styles = array(
                'pending'  => array( '#856404', '#fff3cd' ),
                'approved' => array( '#2e7d4f', '#d4edda' ),
                'rejected' => array( '#842029', '#f8d7da' ),
            );
            list( $sfc, $sbg ) = $status_styles[ $item->status ] ?? array( '#333', '#eee' );
        ?>
            <tr id="rh-row-<?php echo $oid; ?>">
                <td><a href="<?php echo esc_url( get_edit_post_link( $oid ) ); ?>">#<?php echo $oid; ?></a></td>
                <td id="rh-name-<?php echo $oid; ?>"><?php echo esc_html( trim("$forename $surname") ); ?></td>
                <td class="col-tests"><?php echo esc_html( implode( ', ', $tests ) ); ?></td>
                <td style="font-size:12px;color:#666;"><?php echo esc_html( $item->flag_reason ); ?></td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;
                        background:<?php echo $item->queue_type==='incomplete'?'#f8d7da':'#fff3cd'; ?>;
                        color:<?php echo $item->queue_type==='incomplete'?'#842029':'#856404'; ?>;">
                        <?php echo esc_html( strtoupper( str_replace('_',' ',$item->queue_type) ) ); ?>
                    </span>
                </td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;
                                 background:<?php echo esc_attr($sbg); ?>;color:<?php echo esc_attr($sfc); ?>;">
                        <?php echo esc_html( ucfirst( $item->status ) ); ?>
                    </span>
                </td>
                <td style="font-size:12px;"><?php echo esc_html( $item->created_at ); ?></td>
                <?php if ( $tab === 'pending' ) : ?>
                <td>
                    <button type="button" class="button button-small"
                            onclick="rhToggleEdit(<?php echo $oid; ?>)">Edit Fields</button>
                    <button type="button" class="button button-small button-primary"
                            onclick="rhApprove(<?php echo $oid; ?>)">Approve &amp; Transmit</button>
                    <button type="button" class="button button-small"
                            onclick="rhOpenReject(<?php echo $oid; ?>)">Reject</button>
                </td>
                <?php endif; ?>
            </tr>

            <?php if ( $tab === 'pending' ) : ?>
            <tr id="rh-edit-<?php echo $oid; ?>" style="display:none;">
                <td colspan="8" style="padding:20px 24px;background:#f4f8fb;border-top:2px solid #1a6e8c;">

                    <strong style="color:#1a6e8c;font-size:14px;">Edit Patient Fields &mdash; Order #<?php echo $oid; ?></strong>

                    <!-- ── FIX 1: Patient Details FIRST (top-left) ── -->
                    <div class="rh-section-title">Patient 1 Details</div>
                    <div class="rh-grid-3">
                        <div>
                            <label class="rh-field-label">Patient Forename <span style="color:#c0392b">*</span></label>
                            <input type="text" id="rh-forename-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($forename); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Patient Surname <span style="color:#c0392b">*</span></label>
                            <input type="text" id="rh-surname-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($surname); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Date of Birth <span style="color:#c0392b">*</span></label>
                            <input type="text" id="rh-dob-<?php echo $oid; ?>"
                                   class="rh-field-input rh-flatpickr"
                                   placeholder="DD/MM/YYYY"
                                   value="<?php echo esc_attr($dob); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Sex at Birth <span style="color:#c0392b">*</span></label>
                            <select id="rh-sex-<?php echo $oid; ?>" class="rh-field-input">
                                <option value="" <?php selected($sex,''); ?>>Please select</option>
                                <option value="male"   <?php selected($sex,'male'); ?>>Male</option>
                                <option value="female" <?php selected($sex,'female'); ?>>Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="rh-field-label">Order Type</label>
                            <select id="rh-type-<?php echo $oid; ?>" class="rh-field-input">
                                <option value="self_collect" <?php selected($otype,'self_collect'); ?>>Self-Collect</option>
                                <option value="venous"       <?php selected($otype,'venous'); ?>>Venous</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label class="rh-field-label">Additional Notes (Patient 1)</label>
                        <textarea id="rh-p1notes-<?php echo $oid; ?>" rows="2"
                                  class="rh-field-input"
                                  placeholder="Optional — symptoms or relevant clinical information"><?php echo esc_textarea( $p1_notes ); ?></textarea>
                    </div>

                    <!-- ── FIX 4: Patient 1 tests shown right below Patient 1 ── -->
                    <?php if ( $p1_test_names ) : ?>
                    <div style="margin-bottom:16px;padding:8px 12px;background:#f0f7fb;border:1px solid #c5dce8;border-radius:5px;font-size:12px;color:#374151;">
                        <strong>Patient 1 assigned tests:</strong> <?php echo esc_html($p1_test_names); ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── Order Reference (secondary) ── -->
                    <div class="rh-section-title">Order Reference</div>
                    <div class="rh-grid-3" style="margin-bottom:16px;">
                        <div>
                            <label class="rh-field-label">Reference Number</label>
                            <input type="text" class="rh-field-input rh-field-readonly"
                                   value="<?php echo esc_attr( $ref_number ); ?>" readonly>
                        </div>
                        <div>
                            <label class="rh-field-label">Order ID</label>
                            <input type="text" class="rh-field-input rh-field-readonly"
                                   value="#<?php echo esc_attr( $oid ); ?>" readonly>
                        </div>
                        <div>
                            <label class="rh-field-label">Order Email</label>
                            <input type="email" id="rh-email-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr( $order->get_billing_email() ); ?>">
                        </div>
                    </div>

                    <!-- ── Shipping / Collection Address ── -->
                    <div class="rh-section-title">Shipping / Collection Address</div>
                    <div class="rh-grid-3">
                        <div style="grid-column:span 2;">
                            <label class="rh-field-label">Address Line 1</label>
                            <input type="text" id="rh-ship-addr1-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($ship_addr1); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Address Line 2</label>
                            <input type="text" id="rh-ship-addr2-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($ship_addr2); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Town / City</label>
                            <input type="text" id="rh-ship-city-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($ship_city); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">County / State</label>
                            <input type="text" id="rh-ship-county-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($ship_county); ?>">
                        </div>
                        <div>
                            <label class="rh-field-label">Postcode</label>
                            <input type="text" id="rh-ship-post-<?php echo $oid; ?>"
                                   class="rh-field-input"
                                   value="<?php echo esc_attr($ship_post); ?>">
                        </div>
                    </div>

                    <!-- ── Additional Patients on This Order ── -->
                    <div class="rh-section-title">Additional Patients on This Order</div>
                    <div id="rh-extra-patients-<?php echo $oid; ?>">
                    <?php
                    foreach ( $additional_patients as $ap_idx => $ap ) :
                        if ( ! is_array( $ap ) ) continue;
                        $ap_num        = $ap_idx + 2;
                        $ap_tests_meta = (array) $order->get_meta( "_repose_patient_{$ap_num}_tests" );
                        if ( empty( $ap_tests_meta ) && ! empty( $ap['tests'] ) && is_array( $ap['tests'] ) ) {
                            $ap_tests_meta = $ap['tests'];
                        }
                        // Build checked product_id array for this patient
                        $ap_checked_ids = array_filter( array_map( function($t){ return (int)($t['product_id']??0); }, $ap_tests_meta ) );
                    ?>
                    <div class="rh-extra-patient" id="rh-extra-<?php echo $oid; ?>-existing-<?php echo $ap_idx; ?>"
                         style="background:#fff;border:1px solid #d0e8f2;border-radius:6px;padding:14px;margin-bottom:12px;"
                         data-existing="1" data-apidx="<?php echo $ap_idx; ?>">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                            <strong style="font-size:13px;color:#1a6e8c;">
                                Patient <?php echo $ap_num; ?> — <?php echo esc_html( trim( ($ap['forename']??'') . ' ' . ($ap['surname']??'') ) ); ?>
                            </strong>
                            <button type="button" class="button button-small"
                                    onclick="document.getElementById('rh-extra-<?php echo $oid; ?>-existing-<?php echo $ap_idx; ?>').remove()"
                                    style="color:#c0392b;">Remove</button>
                        </div>
                        <div class="rh-grid-3">
                            <div>
                                <label class="rh-field-label">Forename <span style="color:#c0392b">*</span></label>
                                <input type="text" name="existing_patient[<?php echo $ap_idx; ?>][forename]"
                                       class="rh-field-input" value="<?php echo esc_attr($ap['forename']??''); ?>">
                            </div>
                            <div>
                                <label class="rh-field-label">Surname <span style="color:#c0392b">*</span></label>
                                <input type="text" name="existing_patient[<?php echo $ap_idx; ?>][surname]"
                                       class="rh-field-input" value="<?php echo esc_attr($ap['surname']??''); ?>">
                            </div>
                            <div>
                                <label class="rh-field-label">Date of Birth</label>
                                <input type="text" name="existing_patient[<?php echo $ap_idx; ?>][dob]"
                                       class="rh-field-input rh-flatpickr"
                                       placeholder="DD/MM/YYYY"
                                       value="<?php echo esc_attr($ap['dob']??''); ?>">
                            </div>
                            <div>
                                <label class="rh-field-label">Sex at Birth</label>
                                <select name="existing_patient[<?php echo $ap_idx; ?>][sex]" class="rh-field-input">
                                    <option value="">Please select</option>
                                    <option value="male"   <?php selected($ap['sex']??'','male'); ?>>Male</option>
                                    <option value="female" <?php selected($ap['sex']??'','female'); ?>>Female</option>
                                </select>
                            </div>
                        </div>

                        <!-- ── FIX 2: Proper label-wrapped checkboxes for test assignment ── -->
                        <?php if ( ! empty( $all_order_items ) ) : ?>
                        <div style="margin-bottom:10px;">
                            <label class="rh-field-label">Assigned Tests</label>
                            <?php foreach ( $all_order_items as $oi ) :
                                $cb_id      = 'rh-ap-test-' . $oid . '-' . $ap_idx . '-' . $oi['product_id'];
                                $is_checked = in_array( $oi['product_id'], $ap_checked_ids, true );
                            ?>
                            <label for="<?php echo esc_attr($cb_id); ?>"
                                   class="rh-ap-test-row <?php echo $is_checked ? 'checked' : ''; ?>"
                                   onclick="rhToggleApTest(this)">
                                <input type="checkbox"
                                       id="<?php echo esc_attr($cb_id); ?>"
                                       name="existing_patient[<?php echo $ap_idx; ?>][test_ids][]"
                                       value="<?php echo esc_attr($oi['product_id']); ?>"
                                       <?php checked($is_checked); ?>
                                       onclick="event.stopPropagation();rhToggleApTest(this.closest('label'));return false;">
                                <span style="font-size:13px;color:#334155;"><?php echo esc_html($oi['name']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div>
                            <label class="rh-field-label">Additional Notes</label>
                            <textarea name="existing_patient[<?php echo $ap_idx; ?>][notes]"
                                      class="rh-field-input" rows="2"
                                      placeholder="Optional"><?php echo esc_textarea($ap['notes']??''); ?></textarea>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>

                    <button type="button" class="button"
                            onclick="rhAddPatient(<?php echo $oid; ?>)"
                            style="margin-bottom:16px;">
                        &#43; Add Another Patient
                    </button>

                    <!-- ── Staff Note ── -->
                    <div class="rh-section-title">Staff Note</div>
                    <textarea id="rh-note-<?php echo $oid; ?>" rows="2"
                              class="rh-field-input"
                              placeholder="Describe what was changed and why... (recorded in audit log)"></textarea>
                    <div style="margin-top:12px;">
                        <button type="button" class="button button-primary"
                                onclick="rhSaveEdit(<?php echo $oid; ?>)">Save Changes</button>
                        <button type="button" class="button"
                                onclick="document.getElementById('rh-edit-<?php echo $oid; ?>').style.display='none'"
                                style="margin-left:6px;">Cancel</button>
                        <span id="rh-edit-feedback-<?php echo $oid; ?>"
                              style="margin-left:10px;font-size:13px;color:#2e7d4f;display:none;"></span>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Reject modal -->
    <div id="rh-reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999999;">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:440px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,0.3);">
            <h3 style="margin:0 0 16px;color:#c0392b;">Reject Order</h3>
            <input type="hidden" id="rh-reject-id" value="">
            <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;">Reason for rejection <span style="color:#c0392b;">*</span></label>
            <textarea id="rh-reject-reason" rows="4" style="width:100%;padding:10px;margin-bottom:14px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:13px;" placeholder="Enter reason..."></textarea>
            <button type="button" class="button button-primary" onclick="rhConfirmReject()" id="rh-reject-confirm">Confirm Reject</button>
            <button type="button" class="button" onclick="document.getElementById('rh-reject-modal').style.display='none'" style="margin-left:8px;">Cancel</button>
        </div>
    </div>
</div>

<script>
var rhAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce')]); ?>;
var rhPatientCount = {};

function rhNotice(msg,isError){
    var el=document.getElementById('rh-notice');
    el.textContent=msg;
    el.style.background=isError?'#f8d7da':'#d4edda';
    el.style.color=isError?'#721c24':'#155724';
    el.style.border='1px solid '+(isError?'#f5c6cb':'#c3e6cb');
    el.style.display='block';
    setTimeout(function(){el.style.display='none';},5000);
}

function rhPost(data,cb){
    data.nonce=rhAjax.nonce;
    var body=Object.keys(data).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}).join('&');
    fetch(rhAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();}).then(cb).catch(function(){rhNotice('Network error',true);});
}

function rhRemoveRow(oid){
    ['rh-row-','rh-edit-'].forEach(function(p){var el=document.getElementById(p+oid);if(el)el.remove();});
}

function rhTransmitAll(){
    if(!confirm('Transmit ALL pending orders to the lab now?'))return;
    var btn=document.getElementById('rh-transmit-all');
    if(btn){btn.disabled=true;btn.textContent='Transmitting...';}
    rhPost({action:'repose_transmit_all_pending'},function(r){
        if(btn){btn.disabled=false;btn.textContent='\u25BA Transmit All Pending Now';}
        if(r&&r.success){rhNotice((r.data.flushed||0)+' order(s) transmitted successfully.');setTimeout(function(){location.reload();},1200);}
        else{rhNotice((r&&r.data)?r.data:'Failed.',true);}
    });
}

function rhToggleAutoTransmit(){
    var btn=document.getElementById('rh-auto-toggle'),
        knob=document.getElementById('rh-toggle-knob'),
        label=document.getElementById('rh-auto-label'),
        desc=document.getElementById('rh-toggle-desc'),
        spinner=document.getElementById('rh-toggle-spinner');
    var current=btn.dataset.state==='1', newState=current?'0':'1';
    btn.disabled=true; spinner.style.display='inline';
    rhPost({action:'repose_toggle_auto_transmit',enabled:newState},function(r){
        btn.disabled=false; spinner.style.display='none';
        if(r&&r.success){
            btn.dataset.state=newState;
            btn.style.background=newState==='1'?'#2e7d4f':'#ccc';
            knob.style.left=newState==='1'?'27px':'3px';
            label.textContent=newState==='1'?'ON':'OFF';
            label.style.color=newState==='1'?'#2e7d4f':'#999';
            desc.textContent=newState==='1'
                ?'Clean single-test orders skip this queue and go straight to the lab.'
                :'Every new order lands here for manual review before being sent to the lab.';
            var msg=newState==='1'
                ?'Auto-transmit ON — clean orders will now transmit automatically on payment.'
                :'Auto-transmit OFF — all new orders will appear here for manual authorisation.';
            if(newState==='1'&&r.data.flushed>0){msg+=' '+r.data.flushed+' pending order(s) transmitted now.';location.reload();}
            rhNotice(msg);
        }else{rhNotice('Failed to save setting.',true);}
    });
}

function rhToggleEdit(oid){
    document.querySelectorAll('tr[id^="rh-edit-"]').forEach(function(r){
        if(r.id!=='rh-edit-'+oid)r.style.display='none';
    });
    var row=document.getElementById('rh-edit-'+oid);
    if(!row)return;
    row.style.display=(row.style.display==='none'||row.style.display==='')?'table-row':'none';
}

/* ── FIX 2: Toggle test-row visual state (admin panel existing patients) ── */
function rhToggleApTest(row) {
    var cb = row.querySelector('input[type=checkbox]');
    if (!cb) return;
    cb.checked = !cb.checked;
    if (cb.checked) {
        row.classList.add('checked');
    } else {
        row.classList.remove('checked');
    }
}

/* ── Flatpickr DOB init ── */
function rhInitFlatpickr(scope) {
    var els = (scope || document).querySelectorAll('.rh-flatpickr');
    els.forEach(function(el) {
        if ( el._flatpickr ) return;
        flatpickr(el, { dateFormat:'d/m/Y', allowInput:true, disableMobile:true });
    });
}
document.addEventListener('DOMContentLoaded', function(){ rhInitFlatpickr(); });

/* ── Add Another Patient ── */
function rhAddPatient(oid){
    if(!rhPatientCount[oid]) rhPatientCount[oid]=0;
    rhPatientCount[oid]++;
    var idx=rhPatientCount[oid];
    var container=document.getElementById('rh-extra-patients-'+oid);
    var html='<div class="rh-extra-patient" id="rh-extra-'+oid+'-'+idx+'" style="background:#fff;border:1px solid #d0e8f2;border-radius:6px;padding:14px;margin-bottom:12px;">'
        +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'
        +'<strong style="font-size:13px;color:#1a6e8c;">New Patient #'+idx+'</strong>'
        +'<button type="button" class="button button-small" onclick="document.getElementById(\'rh-extra-'+oid+'-'+idx+'\').remove()" style="color:#c0392b;">Remove</button>'
        +'</div>'
        +'<div class="rh-grid-3">'
        +'<div><label class="rh-field-label">Forename <span style="color:#c0392b">*</span></label>'
        +'<input type="text" name="extra_patient['+idx+'][forename]" class="rh-field-input" placeholder="Forename" required></div>'
        +'<div><label class="rh-field-label">Surname <span style="color:#c0392b">*</span></label>'
        +'<input type="text" name="extra_patient['+idx+'][surname]" class="rh-field-input" placeholder="Surname" required></div>'
        +'<div><label class="rh-field-label">Date of Birth <span style="color:#c0392b">*</span></label>'
        +'<input type="text" name="extra_patient['+idx+'][dob]" class="rh-field-input rh-flatpickr" placeholder="DD/MM/YYYY"></div>'
        +'<div><label class="rh-field-label">Sex at Birth <span style="color:#c0392b">*</span></label>'
        +'<select name="extra_patient['+idx+'][sex]" class="rh-field-input" required>'
        +'<option value="">Please select</option>'
        +'<option value="male">Male</option>'
        +'<option value="female">Female</option>'
        +'</select></div>'
        +'<div style="grid-column:span 2;"><label class="rh-field-label">Additional Notes</label>'
        +'<textarea name="extra_patient['+idx+'][notes]" class="rh-field-input" rows="2" placeholder="Optional"></textarea></div>'
        +'</div>'
        +'</div>';
    container.insertAdjacentHTML('beforeend',html);
    rhInitFlatpickr(container.lastElementChild);
}

/* ── Save Edit ── */
function rhSaveEdit(oid){
    var btn=event.target;
    btn.disabled=true; btn.textContent='Saving...';

    // Collect existing additional patients
    var existingPatients=[];
    var existingBlocks=document.querySelectorAll('#rh-extra-patients-'+oid+' [data-existing="1"]');
    existingBlocks.forEach(function(block){
        var apIdx=block.dataset.apidx;
        var p={ _apidx: apIdx };
        block.querySelectorAll('input,select,textarea').forEach(function(inp){
            var nameParts=(inp.name||'').match(/existing_patient\[(\d+)\]\[(\w+)\]/);
            if(nameParts) {
                if(nameParts[2]==='test_ids') {
                    // collect checked test ids
                    if(!p.test_ids) p.test_ids=[];
                    if(inp.checked) p.test_ids.push(inp.value);
                } else {
                    p[nameParts[2]]=inp.value;
                }
            }
        });
        if(p.forename||p.surname) existingPatients.push(p);
    });

    // Collect NEW additional patients
    var extraPatients=[];
    var extraBlocks=document.querySelectorAll('#rh-extra-patients-'+oid+' .rh-extra-patient:not([data-existing])');
    extraBlocks.forEach(function(block){
        var p={};
        block.querySelectorAll('input,select,textarea').forEach(function(inp){
            var nameParts=(inp.name||'').match(/extra_patient\[\d+\]\[(\w+)\]/);
            if(nameParts) p[nameParts[1]]=inp.value;
        });
        if(p.forename&&p.surname) extraPatients.push(p);
    });

    rhPost({
        action           : 'repose_save_order_edit',
        order_id         : oid,
        forename         : document.getElementById('rh-forename-'+oid).value,
        surname          : document.getElementById('rh-surname-'+oid).value,
        sex              : document.getElementById('rh-sex-'+oid).value,
        dob              : document.getElementById('rh-dob-'+oid).value,
        order_type       : document.getElementById('rh-type-'+oid).value,
        email            : document.getElementById('rh-email-'+oid).value,
        p1_notes         : document.getElementById('rh-p1notes-'+oid) ? document.getElementById('rh-p1notes-'+oid).value : '',
        ship_addr1       : document.getElementById('rh-ship-addr1-'+oid).value,
        ship_addr2       : document.getElementById('rh-ship-addr2-'+oid).value,
        ship_city        : document.getElementById('rh-ship-city-'+oid).value,
        ship_county      : document.getElementById('rh-ship-county-'+oid).value,
        ship_post        : document.getElementById('rh-ship-post-'+oid).value,
        existing_patients: JSON.stringify(existingPatients),
        extra_patients   : JSON.stringify(extraPatients),
        edit_note        : document.getElementById('rh-note-'+oid).value,
    },function(r){
        btn.disabled=false; btn.textContent='Save Changes';
        if(r&&r.success){
            rhNotice(r.data.message||'Saved.');
            var f=document.getElementById('rh-forename-'+oid).value,
                s=document.getElementById('rh-surname-'+oid).value,
                nc=document.getElementById('rh-name-'+oid);
            if(nc) nc.textContent=(f+' '+s).trim();
            var fb=document.getElementById('rh-edit-feedback-'+oid);
            if(fb){fb.textContent='Saved \u2713';fb.style.display='inline';setTimeout(function(){fb.style.display='none';},3000);}
        }else{
            rhNotice((r&&r.data)?r.data:'Save failed.',true);
        }
    });
}

function rhApprove(oid){
    if(!confirm('Approve and transmit order #'+oid+' to the laboratory?'))return;
    var btns=document.querySelectorAll('#rh-row-'+oid+' button');
    btns.forEach(function(b){b.disabled=true;});
    rhPost({action:'repose_approve_order',order_id:oid},function(r){
        btns.forEach(function(b){b.disabled=false;});
        if(r&&r.success){rhNotice(r.data.message||'Approved.');rhRemoveRow(oid);}
        else{rhNotice((r&&r.data)?r.data:'Failed.',true);}
    });
}

function rhOpenReject(oid){
    document.getElementById('rh-reject-id').value=oid;
    document.getElementById('rh-reject-reason').value='';
    document.getElementById('rh-reject-modal').style.display='block';
}

function rhConfirmReject(){
    var oid=document.getElementById('rh-reject-id').value,
        reason=document.getElementById('rh-reject-reason').value.trim();
    if(!reason){alert('Please provide a reason.');return;}
    var btn=document.getElementById('rh-reject-confirm');
    btn.disabled=true; btn.textContent='Rejecting...';
    rhPost({action:'repose_reject_order',order_id:oid,reason:reason},function(r){
        btn.disabled=false; btn.textContent='Confirm Reject';
        document.getElementById('rh-reject-modal').style.display='none';
        if(r&&r.success){rhNotice(r.data.message||'Rejected.');rhRemoveRow(oid);}
        else{rhNotice((r&&r.data)?r.data:'Failed.',true);}
    });
}

document.getElementById('rh-reject-modal').addEventListener('click',function(e){
    if(e.target===this)this.style.display='none';
});
</script>
