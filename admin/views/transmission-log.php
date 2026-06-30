<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap repose-admin">
    <h1>Transmission Log</h1>
    <p style="color:#555;margin-top:0;">Full record of every order transmitted to the laboratory and every staff action taken.</p>

    <?php
    global $wpdb;

    // ── Mode: queue (one row per order) vs log (every audit entry) ───────────
    $view_mode = sanitize_text_field( $_GET['view_mode'] ?? 'log' );
    if ( ! in_array( $view_mode, array( 'log', 'queue' ), true ) ) $view_mode = 'log';

    // ── Filters ──────────────────────────────────────────────────────────────
    $filter_action  = sanitize_text_field( $_GET['filter_action']  ?? '' );
    $filter_order   = (int) ( $_GET['filter_order']  ?? 0 );
    $filter_result  = sanitize_text_field( $_GET['filter_result']  ?? '' ); // awaiting | received
    $page_num       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per_page       = 20;
    $offset         = ( $page_num - 1 ) * $per_page;

    // Transmission actions (orders sent to lab) — used for "result status" filter
    $tx_actions = array( 'auto_transmitted', 'manual_approved', 'manual_transmitted' );
    // Only rows that physically sent to lab show 'Add Tracking' button
    $sent_to_lab_actions = array( 'auto_transmitted', 'manual_transmitted' );

    $where  = 'WHERE 1=1';
    $params = array();

    if ( $filter_action ) {
        $where   .= ' AND l.action = %s';
        $params[] = $filter_action;
    }
    if ( $filter_order ) {
        $where   .= ' AND l.order_id = %d';
        $params[] = $filter_order;
    }
    if ( $filter_result === 'awaiting' ) {
        // Transmitted but no result row exists yet
        $where .= " AND l.action IN ('auto_transmitted','manual_approved','manual_transmitted')
                    AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}repose_results r
                        WHERE r.order_id = l.order_id
                    )";
    } elseif ( $filter_result === 'received' ) {
        // Transmitted AND result row exists
        $where .= " AND l.action IN ('auto_transmitted','manual_approved','manual_transmitted')
                    AND EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}repose_results r
                        WHERE r.order_id = l.order_id
                    )";
    }

    // ── Queue mode: one row per order (most recent transmission action) ───────
    if ( $view_mode === 'queue' ) {
        // Restrict to transmission-type actions only (queue is order-centric)
        $where .= " AND l.action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')";

        // Subquery picks the highest log id per order_id
        $queue_sql = "FROM {$wpdb->prefix}repose_audit_log l
                      LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                      WHERE l.id IN (
                          SELECT MAX(id) FROM {$wpdb->prefix}repose_audit_log
                          WHERE action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
                            AND order_id IS NOT NULL AND order_id > 0
                          GROUP BY order_id
                      )
                      AND l.order_id IS NOT NULL AND l.order_id > 0";

        // Apply additional user filters on top of the queue subquery
        $extra_where = '';
        $extra_params = array();
        if ( $filter_order ) {
            $extra_where    .= ' AND l.order_id = %d';
            $extra_params[]  = $filter_order;
        }
        if ( $filter_result === 'awaiting' ) {
            $extra_where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}repose_results r WHERE r.order_id = l.order_id)";
        } elseif ( $filter_result === 'received' ) {
            $extra_where .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}repose_results r WHERE r.order_id = l.order_id)";
        }

        $full_queue_sql = $queue_sql . $extra_where;
        $total = (int) $wpdb->get_var( $extra_params
            ? $wpdb->prepare( "SELECT COUNT(*) {$full_queue_sql}", ...$extra_params )
            : "SELECT COUNT(*) {$full_queue_sql}" );

        $rows = $extra_params
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT l.*, u.display_name {$full_queue_sql} ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
                ...array_merge( $extra_params, array( $per_page, $offset ) ) ) )
            : $wpdb->get_results(
                "SELECT l.*, u.display_name {$full_queue_sql} ORDER BY l.created_at DESC LIMIT {$per_page} OFFSET {$offset}" );

    } else {
        // ── Log mode (original): every audit row ───────────────────────────────
        $base_sql = "FROM {$wpdb->prefix}repose_audit_log l
                     LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                     {$where}";

        $total = (int) $wpdb->get_var( $params
            ? $wpdb->prepare( "SELECT COUNT(*) {$base_sql}", ...$params )
            : "SELECT COUNT(*) {$base_sql}" );

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT l.*, u.display_name {$base_sql} ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
                ...array_merge( $params, array( $per_page, $offset ) ) ) )
            : $wpdb->get_results(
                "SELECT l.*, u.display_name {$base_sql} ORDER BY l.created_at DESC LIMIT {$per_page} OFFSET {$offset}" );
    }

    // Preload order_ids that have a result (for inline status badge)
    $transmitted_order_ids = array();
    foreach ( $rows as $row ) {
        if ( in_array( $row->action, $tx_actions, true ) && $row->order_id ) {
            $transmitted_order_ids[] = (int) $row->order_id;
        }
    }
    $orders_with_result = array();
    if ( $transmitted_order_ids ) {
        $ids_in = implode( ',', array_unique( $transmitted_order_ids ) );
        $orders_with_result = $wpdb->get_col(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}repose_results WHERE order_id IN ({$ids_in})"
        );
    }

    $total_pages = ceil( $total / $per_page );

    // ── Summary stats bar ─────────────────────────────────────────────────
    $stat_tx = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}repose_audit_log
         WHERE action IN ('auto_transmitted','manual_approved','manual_transmitted')"
    );
    $stat_received = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}repose_results"
    );
    $stat_awaiting = max( 0, $stat_tx - $stat_received );
    ?>

    <!-- View mode toggle (Queue vs Full Log) -->
    <div style="display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid #ddd;padding-bottom:0;">
        <?php
        $mode_tabs = array(
            'queue' => 'Tracking Queue <span style="font-size:11px;font-weight:400;opacity:.75;">(one row per order)</span>',
            'log'   => 'Full Audit Log <span style="font-size:11px;font-weight:400;opacity:.75;">(every event)</span>',
        );
        foreach ( $mode_tabs as $mkey => $mlabel ) :
            $mactive = $view_mode === $mkey;
            $mhref   = admin_url( 'admin.php?page=repose-transmission-log&view_mode=' . $mkey );
        ?>
        <a href="<?php echo esc_url( $mhref ); ?>"
           style="text-decoration:none;padding:8px 16px;font-size:13px;font-weight:600;
                  border-radius:6px 6px 0 0;
                  background:<?php echo $mactive ? '#fff' : '#f4f4f4'; ?>;
                  color:<?php echo $mactive ? '#1a6e8c' : '#555'; ?>;
                  border:1px solid <?php echo $mactive ? '#ddd' : 'transparent'; ?>;
                  border-bottom:<?php echo $mactive ? '2px solid #fff' : '1px solid transparent'; ?>;
                  margin-bottom:-2px;">
            <?php echo $mlabel; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Stats bar -->
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?php
        $stats = array(
            array( 'Orders Transmitted', $stat_tx,       '#1a6e8c', '#e8f4f8', '' ),
            array( 'Results Received',   $stat_received, '#2e7d4f', '#d4edda', 'received' ),
            array( 'Awaiting Result',    $stat_awaiting, '#856404', '#fff3cd', 'awaiting' ),
        );
        foreach ( $stats as list( $slabel, $sval, $sfc, $sbg, $sfilt ) ) :
            $href = $sfilt
                ? admin_url( 'admin.php?page=repose-transmission-log&filter_result=' . $sfilt . '&view_mode=' . $view_mode )
                : admin_url( 'admin.php?page=repose-transmission-log&view_mode=' . $view_mode );
        ?>
        <a href="<?php echo esc_url($href); ?>"
           style="text-decoration:none;flex:1;min-width:140px;padding:14px 18px;
                  border-radius:8px;border:1px solid <?php echo $sfc; ?>20;
                  background:<?php echo $sbg; ?>;
                  <?php echo ($filter_result===$sfilt && ($sfilt||!$filter_result)) ? 'box-shadow:0 0 0 2px '.$sfc : ''; ?>;">
            <div style="font-size:26px;font-weight:700;color:<?php echo $sfc; ?>;"><?php echo $sval; ?></div>
            <div style="font-size:12px;color:<?php echo $sfc; ?>;opacity:.85;"><?php echo esc_html($slabel); ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="page" value="repose-transmission-log">
        <input type="hidden" name="view_mode" value="<?php echo esc_attr( $view_mode ); ?>">
        <select name="filter_action" style="padding:6px 10px;">
            <option value="">— All Actions —</option>
            <?php
            $actions = array(
                'auto_transmitted'    => 'Auto Transmitted',
                'manual_approved'     => 'Manual Approved',
                'manual_rejected'     => 'Manual Rejected',
                'manual_transmitted'  => 'Manually Transmitted',
                'result_uploaded'     => 'Result Uploaded',
                'result_approved'     => 'Result Approved',
                'api_result_imported' => 'API Result Imported',
                'json_result_imported'=> 'HL7 Result Imported',
            );
            foreach ( $actions as $val => $label ) {
                $sel = selected( $filter_action, $val, false );
                echo "<option value='" . esc_attr($val) . "' {$sel}>" . esc_html($label) . "</option>";
            }
            ?>
        </select>
        <select name="filter_result" style="padding:6px 10px;">
            <option value="">— Result Status —</option>
            <option value="awaiting"  <?php selected($filter_result,'awaiting');  ?>>Awaiting Result</option>
            <option value="received"  <?php selected($filter_result,'received');  ?>>Result Received</option>
        </select>
        <input type="number" name="filter_order" placeholder="Order ID"
               value="<?php echo esc_attr( $filter_order ?: '' ); ?>"
               style="width:120px;padding:6px 10px;">
        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=repose-transmission-log&view_mode=' . $view_mode ) ); ?>" class="button">Reset</a>
        <span style="margin-left:auto;color:#666;font-size:13px;"><?php echo $total; ?> record(s)</span>
    </form>

    <?php if ( empty( $rows ) ) : ?>
        <p class="repose-empty">No log entries found.</p>
    <?php else : ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:140px">Date / Time &#9660;</th>
                    <th style="width:80px">Order</th>
                    <th style="width:160px">Action</th>
                    <th style="width:160px">Result Status</th>
                    <th style="width:120px">Tracking No.</th>
                    <th style="width:130px">Staff Member</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $action_labels = array(
                    'auto_transmitted'    => array( 'Auto transmitted',     'repose-badge--success' ),
                    'manual_approved'     => array( 'Manual approved',      'repose-badge--success' ),
                    'manual_rejected'     => array( 'Rejected',             'repose-badge--danger'  ),
                    'manual_transmitted'  => array( 'Manually transmitted', 'repose-badge--success' ),
                    'result_uploaded'     => array( 'Result uploaded',      'repose-badge--info'    ),
                    'result_approved'     => array( 'Result approved',      'repose-badge--success' ),
                    'api_result_imported' => array( 'API import',           'repose-badge--info'    ),
                    'json_result_imported'=> array( 'HL7 import',           'repose-badge--info'    ),
                );
                $label_data  = $action_labels[ $row->action ] ?? array( ucwords( str_replace('_',' ',$row->action) ), 'repose-badge--default' );
                $staff       = $row->display_name ?: ( $row->user_id == 0 ? 'System / API' : 'User #' . $row->user_id );
                $is_tx       = in_array( $row->action, $tx_actions, true );
                $is_sent     = in_array( $row->action, $sent_to_lab_actions, true ); // FIX 5
                $has_result  = $row->order_id && in_array( (string) $row->order_id, $orders_with_result, true );
            ?>
                <tr>
                    <td style="font-size:12px"><?php echo esc_html( $row->created_at ); ?></td>
                    <td>
                        <?php if ( $row->order_id ) : ?>
                            <a href="<?php echo get_edit_post_link( $row->order_id ); ?>">#<?php echo esc_html( $row->order_id ); ?></a>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                    <td><span class="repose-badge <?php echo esc_attr( $label_data[1] ); ?>"><?php echo esc_html( $label_data[0] ); ?></span></td>
                    <td>
                        <?php if ( $is_tx ) : ?>
                            <?php if ( $has_result ) : ?>
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
                                             border-radius:10px;font-size:11px;font-weight:600;
                                             background:#d4edda;color:#2e7d4f;">
                                    &#10003; Result Received
                                </span>
                            <?php else : ?>
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;
                                             border-radius:10px;font-size:11px;font-weight:600;
                                             background:#fff3cd;color:#856404;">
                                    &#8987; Awaiting Result
                                </span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color:#aaa;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php
                        $tracking = $row->order_id ? get_post_meta( $row->order_id, '_repose_tracking_number', true ) : '';
                        if ( $tracking ) :
                        ?>
                            <span style="display:block;font-weight:600;color:#1a6e8c;font-size:12px;"><?php echo esc_html($tracking); ?></span>
                            <a href="https://www.royalmail.com/track-your-item#/tracking-results/<?php echo rawurlencode($tracking); ?>"
                               target="_blank" rel="noopener"
                               style="font-size:11px;color:#1a6e8c;">Track &rarr;</a>
                        <?php elseif ( $is_sent && $row->order_id ) : ?>
                            <button type="button" class="button button-small"
                                    onclick="rhOpenTracking(<?php echo (int)$row->order_id; ?>)"
                                    style="font-size:11px;padding:2px 8px;">
                                + Add Tracking
                            </button>
                        <?php else : ?>
                            <span style="color:#aaa;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $staff ); ?></td>
                    <td style="font-size:12px;color:#555"><?php echo esc_html( $row->detail ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="margin-top:16px;">
            <?php
            $base_url = admin_url( 'admin.php?page=repose-transmission-log'
                . ( $filter_action ? '&filter_action=' . urlencode($filter_action) : '' )
                . ( $filter_order  ? '&filter_order='  . $filter_order : '' )
                . ( $filter_result ? '&filter_result='  . urlencode($filter_result) : '' )
                . '&view_mode=' . urlencode( $view_mode ) );
            for ( $i = 1; $i <= $total_pages; $i++ ) :
                $active = $i === $page_num ? 'button-primary' : '';
                echo "<a href='" . esc_url( $base_url . '&paged=' . $i ) . "' class='button {$active}' style='margin-right:4px'>{$i}</a>";
            endfor;
            ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ── Tracking Number Modal ──────────────────────────────────────────────── -->
<div id="rh-tracking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:999999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:460px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 6px;color:#1a6e8c;">Add Royal Mail Tracking Number</h3>
        <p style="margin:0 0 16px;font-size:13px;color:#555;">Order #<span id="rh-track-order-id-label"></span></p>
        <input type="hidden" id="rh-track-order-id" value="">
        <label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;">
            Tracking Number <span style="color:#c0392b;">*</span>
        </label>
        <input type="text" id="rh-tracking-input"
               style="width:100%;padding:10px 12px;margin-bottom:6px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:14px;font-weight:600;letter-spacing:0.5px;"
               placeholder="e.g. FI963365846GB">
        <p style="margin:0 0 18px;font-size:12px;color:#888;">The customer will receive a dispatch email with this tracking number and instructions for returning their sample.</p>
        <button type="button" class="button button-primary" id="rh-track-confirm"
                onclick="rhConfirmTracking()">Save &amp; Send Email</button>
        <button type="button" class="button"
                onclick="document.getElementById('rh-tracking-modal').style.display='none'"
                style="margin-left:8px;">Cancel</button>
        <span id="rh-track-feedback" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
    </div>
</div>

<script>
var rhTxAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce')]); ?>;

function rhOpenTracking(orderId) {
    document.getElementById('rh-track-order-id').value = orderId;
    document.getElementById('rh-track-order-id-label').textContent = orderId;
    document.getElementById('rh-tracking-input').value = '';
    document.getElementById('rh-track-feedback').style.display = 'none';
    document.getElementById('rh-tracking-modal').style.display = 'block';
    setTimeout(function(){ document.getElementById('rh-tracking-input').focus(); }, 100);
}

function rhConfirmTracking() {
    var orderId  = document.getElementById('rh-track-order-id').value;
    var tracking = document.getElementById('rh-tracking-input').value.trim();
    if (!tracking) { alert('Please enter a tracking number.'); return; }

    var btn = document.getElementById('rh-track-confirm');
    btn.disabled = true; btn.textContent = 'Saving...';

    var body = 'action=repose_add_tracking'
        + '&nonce='           + encodeURIComponent(rhTxAjax.nonce)
        + '&order_id='        + encodeURIComponent(orderId)
        + '&tracking_number=' + encodeURIComponent(tracking);

    fetch(rhTxAjax.url, {
        method      : 'POST',
        credentials : 'same-origin',
        headers     : {'Content-Type':'application/x-www-form-urlencoded'},
        body        : body,
    })
    .then(function(r){ return r.json(); })
    .then(function(r) {
        btn.disabled = false; btn.textContent = 'Save & Send Email';
        if (r && r.success) {
            document.getElementById('rh-track-feedback').textContent = '\u2713 ' + (r.data.message || 'Saved.');
            document.getElementById('rh-track-feedback').style.display = 'inline';
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            alert((r && r.data) ? r.data : 'Failed to save tracking number.');
        }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Save & Send Email'; alert('Network error.'); });
}

document.getElementById('rh-tracking-modal').addEventListener('click', function(e){
    if (e.target === this) this.style.display = 'none';
});
</script>
