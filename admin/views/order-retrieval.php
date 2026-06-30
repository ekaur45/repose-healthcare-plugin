<?php
/**
 * Order Retrieval & Re-transmission
 * Search transmitted orders, view details, amend patient fields, retransmit.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Search parameters
$s_email      = sanitize_email( $_GET['s_email'] ?? '' );
$s_ref        = sanitize_text_field( $_GET['s_ref'] ?? '' );
$s_name       = sanitize_text_field( $_GET['s_name'] ?? '' );
$s_order      = (int) ( $_GET['s_order'] ?? 0 );
$s_uid        = sanitize_text_field( $_GET['s_uid'] ?? '' );
$filter_orr   = ! empty( $_GET['filter_orr'] );   // NEW: show orders awaiting ORR confirmation
$page_num     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page     = 20;
$offset       = ( $page_num - 1 ) * $per_page;
$searching    = ( $s_email || $s_ref || $s_name || $s_order || $s_uid || $filter_orr );

global $wpdb;
$orders = array();
$total  = 0;

if ( $searching ) {

    // ── ORR filter: orders transmitted but not yet confirmed received ────
    if ( $filter_orr ) {
        $orr_ids = $wpdb->get_col(
            "SELECT DISTINCT l.order_id
             FROM {$wpdb->prefix}repose_audit_log l
             WHERE l.action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
               AND l.order_id IS NOT NULL AND l.order_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->posts} p
                   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                   WHERE p.ID = l.order_id
                     AND pm.meta_key = '_repose_sample_received'
                     AND pm.meta_value = '1'
               )"
        );
        $orr_ids = array_map( 'intval', $orr_ids );
        $total   = count( $orr_ids );
        $page_ids = array_slice( $orr_ids, $offset, $per_page );
        if ( ! empty( $page_ids ) ) {
            foreach ( $page_ids as $oid ) {
                $o = wc_get_order( $oid );
                if ( $o ) $orders[] = $o;
            }
        }
        // Skip the normal search pipeline below
        goto render_orders;
    }

    // ── Step 1: get all order IDs that have a transmission audit log entry ──
    $transmitted_ids = $wpdb->get_col(
        "SELECT DISTINCT order_id FROM {$wpdb->prefix}repose_audit_log
         WHERE action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
         AND order_id IS NOT NULL AND order_id > 0"
    );

    if ( ! empty( $transmitted_ids ) ) {
        $candidate_ids = array_map( 'intval', $transmitted_ids );

        // ── Filter by Order ID (simple numeric match) ──────────────────────
        if ( $s_order ) {
            $candidate_ids = in_array( $s_order, $candidate_ids, true ) ? array( $s_order ) : array();
        }

        // ── Filter by Reference Number — use repose_audit_log detail field ──
        // Also search wc_order_meta / postmeta depending on WC storage
        if ( $s_ref && ! empty( $candidate_ids ) ) {
            $ids_in  = implode( ',', $candidate_ids );
            $like    = '%' . $wpdb->esc_like( $s_ref ) . '%';

            // Try repose_audit_log detail (reference always logged there)
            $from_log = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}repose_audit_log
                 WHERE order_id IN ({$ids_in}) AND detail LIKE %s",
                $like
            ) );

            // Also try wc_order_meta (HPOS)
            // $from_hpos = $wpdb->get_col( $wpdb->prepare(
            //     "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_meta
            //      WHERE meta_key = '_repose_reference_number' AND meta_value LIKE %s
            //      AND order_id IN ({$ids_in})",
            //     $like
            // ) );

            // Also try postmeta (legacy)
            $from_pm = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_repose_reference_number' AND meta_value LIKE %s
                 AND post_id IN ({$ids_in})",
                $like
            ) );

            // $from_hpos,
            $matched = array_unique( array_merge( $from_log,  $from_pm ) );
            $candidate_ids = array_filter( array_intersect( $candidate_ids, array_map('intval', $matched) ) );
        }

        // ── Filter by Patient UID — search both wc_order_meta and postmeta ──
        if ( $s_uid && ! empty( $candidate_ids ) ) {
            $ids_in = implode( ',', $candidate_ids );
            $like   = '%' . $wpdb->esc_like( $s_uid ) . '%';

            // Patient registry → patient_tests
            $from_reg = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pt.order_id FROM {$wpdb->prefix}repose_patient_tests pt
                 JOIN {$wpdb->prefix}repose_patients p ON p.id = pt.patient_id
                 WHERE p.patient_uid LIKE %s AND pt.order_id IN ({$ids_in})",
                $like
            ) );

            // HPOS meta
            // $from_hpos = $wpdb->get_col( $wpdb->prepare(
            //     "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_meta
            //      WHERE meta_key LIKE '_repose_patient_%_uid' AND meta_value LIKE %s
            //      AND order_id IN ({$ids_in})",
            //     $like
            // ) );

            // Legacy postmeta
            $from_pm = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE '_repose_patient_%_uid' AND meta_value LIKE %s
                 AND post_id IN ({$ids_in})",
                $like
            ) );

            // $from_hpos,
            $matched = array_unique( array_merge( $from_reg,  $from_pm ) );
            $candidate_ids = array_filter( array_intersect( $candidate_ids, array_map('intval', $matched) ) );
        }

        // ── Filter by patient name — patient registry is the reliable source ──
        if ( $s_name && ! empty( $candidate_ids ) ) {
            $ids_in = implode( ',', $candidate_ids );
            $like   = '%' . $wpdb->esc_like( $s_name ) . '%';

            // Search patient registry (most reliable)
            $from_reg = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pt.order_id FROM {$wpdb->prefix}repose_patient_tests pt
                 JOIN {$wpdb->prefix}repose_patients p ON p.id = pt.patient_id
                 WHERE (CONCAT(p.forename,' ',p.surname) LIKE %s OR p.forename LIKE %s OR p.surname LIKE %s)
                 AND pt.order_id IN ({$ids_in})",
                $like, $like, $like
            ) );

            // Also search HPOS order meta for patient forename/surname stored on order
            // $from_hpos = $wpdb->get_col( $wpdb->prepare(
            //     "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_meta
            //      WHERE meta_key IN ('_repose_patient_forename','_repose_patient_surname')
            //      AND meta_value LIKE %s AND order_id IN ({$ids_in})",
            //     $like
            // ) );

            // Use wc_get_orders for billing name (WC API handles HPOS automatically)
            $wc_orders_by_name = wc_get_orders( array(
                'limit'        => 200,
                'return'       => 'ids',
                'billing_first_name' => $s_name,
            ) );
            $wc_orders_by_last = wc_get_orders( array(
                'limit'        => 200,
                'return'       => 'ids',
                'billing_last_name'  => $s_name,
            ) );
            $from_wc = array_intersect(
                array_unique( array_merge( $wc_orders_by_name, $wc_orders_by_last ) ),
                $candidate_ids
            );

            $matched = array_unique( array_merge( $from_reg, array_map('intval', $from_wc) ) );
            $candidate_ids = array_filter( array_intersect( $candidate_ids, array_map('intval', $matched) ) );
        }

        // ── Filter by email — use wc_get_orders (HPOS-safe) + patient registry ──
        if ( $s_email && ! empty( $candidate_ids ) ) {
            // wc_get_orders is HPOS-compatible
            $wc_by_email = wc_get_orders( array(
                'limit'         => 200,
                'return'        => 'ids',
                'billing_email' => $s_email,
            ) );
            $from_wc = array_intersect( array_map('intval', $wc_by_email), $candidate_ids );

            // Patient registry
            $ids_in    = implode( ',', $candidate_ids );
            $from_reg  = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pt.order_id FROM {$wpdb->prefix}repose_patient_tests pt
                 JOIN {$wpdb->prefix}repose_patients p ON p.id = pt.patient_id
                 WHERE p.email = %s AND pt.order_id IN ({$ids_in})",
                $s_email
            ) );

            $matched = array_unique( array_merge( array_map('intval', $from_wc), $from_reg ) );
            $candidate_ids = array_filter( array_intersect( $candidate_ids, $matched ) );
        }

        // ── Paginate and load ──────────────────────────────────────────────
        $candidate_ids = array_values( $candidate_ids );
        $total    = count( $candidate_ids );
        $page_ids = array_slice( $candidate_ids, $offset, $per_page );
        foreach ( $page_ids as $oid ) {
            $o = wc_get_order( (int) $oid );
            if ( $o ) $orders[] = $o;
        }
    }
}

$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

render_orders:
?>
<style>
.rh-order-card { background:#fff;border:1px solid #ddd;border-radius:8px;margin-bottom:14px;overflow:hidden; }
.rh-order-card-header { display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f4f8fb;border-bottom:1px solid #ddd;cursor:pointer; }
.rh-order-card-header:hover { background:#e8f4f8; }
.rh-order-body { display:none;padding:16px; }
.rh-order-body.open { display:block; }
.rh-detail-grid { display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px; }
.rh-detail-item label { display:block;font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:2px; }
.rh-detail-item span { font-size:13px;color:#111827; }
.rh-patient-section { background:#f7fbfd;border:1px solid #b3d4e0;border-radius:6px;padding:12px 14px;margin-bottom:10px; }
.rh-patient-section h4 { margin:0 0 10px;font-size:13px;color:#1a6e8c; }
.rh-fi { width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:12px;box-sizing:border-box; }
.rh-amend-row { display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;margin-bottom:8px; }
</style>

<div class="wrap repose-admin">
    <h1>Order Retrieval &amp; Re-transmission</h1>
    <?php if ( $filter_orr ) : ?>
    <div class="notice notice-warning inline" style="margin:0 0 16px;padding:10px 14px;border-left-color:#856404;">
        <strong>Showing orders awaiting ORR (sample receipt) confirmation.</strong>
        &nbsp;<a href="<?php echo esc_url( admin_url( 'admin.php?page=repose-order-retrieval' ) ); ?>">Clear filter &rarr;</a>
    </div>
    <?php endif; ?>
    <p style="color:#666;font-size:13px;margin-top:-12px;margin-bottom:20px;">Search previously transmitted orders by email, patient name, reference number, order ID or patient UID.</p>

    <div id="rh-or-notice" style="display:none;padding:10px 14px;margin:0 0 16px;border-radius:4px;font-size:13px;"></div>

    <!-- Search Form -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 20px;margin-bottom:20px;">
        <form method="GET" id="rh-order-search-form">
            <input type="hidden" name="page" value="repose-order-retrieval">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:flex-end;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Customer Email</label>
                    <input type="email" name="s_email" value="<?php echo esc_attr($s_email); ?>" class="rh-fi" placeholder="customer@email.com">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Patient Name</label>
                    <input type="text" name="s_name" value="<?php echo esc_attr($s_name); ?>" class="rh-fi" placeholder="First or last name">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Reference Number</label>
                    <input type="text" name="s_ref" value="<?php echo esc_attr($s_ref); ?>" class="rh-fi" placeholder="e.g. R26D141257">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Patient UID</label>
                    <input type="text" name="s_uid" value="<?php echo esc_attr($s_uid); ?>" class="rh-fi" placeholder="RHP-00001">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Order ID</label>
                    <input type="number" name="s_order" value="<?php echo esc_attr($s_order ?: ''); ?>" class="rh-fi" placeholder="e.g. 1042">
                </div>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="button button-primary">Search</button>
                    <a href="<?php echo admin_url('admin.php?page=repose-order-retrieval'); ?>" class="button">Clear</a>
                </div>
            </div>
        </form>
    </div>

    <?php if ( ! $searching ) : ?>
    <div style="padding:24px;background:#f0f7fb;border:1px dashed #b3d4e0;border-radius:6px;text-align:center;color:#555;">
        Use the search fields above. You can search by any combination of email, patient name, reference number, patient UID, or order ID.
    </div>

    <?php elseif ( empty( $orders ) ) : ?>
    <div style="padding:24px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;color:#856404;">
        <strong>No transmitted orders found</strong> matching your search. Make sure the order has been approved and transmitted (not just placed). Try searching by order ID directly.
    </div>

    <?php else : ?>
    <p style="color:#666;font-size:13px;margin:0 0 12px;"><?php echo $total; ?> order(s) found</p>

    <?php foreach ( $orders as $order ) :
        $oid        = $order->get_id();
        $ref        = $order->get_meta('_repose_reference_number') ?: '—';
        $forename   = $order->get_meta('_repose_patient_forename') ?: $order->get_billing_first_name();
        $surname    = $order->get_meta('_repose_patient_surname')  ?: $order->get_billing_last_name();
        $dob        = $order->get_meta('_repose_date_of_birth');
        $sex        = $order->get_meta('_repose_sex_at_birth');
        $otype      = $order->get_meta('_repose_order_type') ?: 'self_collect';
        $p1_notes   = $order->get_meta('_repose_additional_notes');
        $p1_uid     = $order->get_meta('_repose_patient_1_uid') ?: '—';
        $tracking   = $order->get_meta('_repose_tracking_number') ?: '';
        $sample_rx  = $order->get_meta('_repose_sample_received') === '1';
        $add_patients = (array) $order->get_meta('_repose_additional_patients');
        $tests      = Repose_Order_Validator::get_tests( $order );
        $last_tx    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_audit_log
             WHERE order_id=%d AND action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
             ORDER BY created_at DESC LIMIT 1", $oid ) );
        $patient_tests  = Repose_Patient_Registry::get_order_patient_tests( $oid );
        $pt_by_patient  = array();
        foreach ( $patient_tests as $pt ) { $pt_by_patient[ $pt->patient_uid ][] = $pt; }
        $result_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}repose_results WHERE order_id=%d ORDER BY uploaded_at DESC LIMIT 1", $oid ) );
        $status_color = array(
            'on-hold'    => array('#856404','#fff3cd'),
            'processing' => array('#2e7d4f','#d4edda'),
            'completed'  => array('#2e7d4f','#d4edda'),
            'cancelled'  => array('#842029','#f8d7da'),
        )[ $order->get_status() ] ?? array('#555','#eee');
    ?>
    <div class="rh-order-card">
        <div class="rh-order-card-header" onclick="rhToggleOrderCard(<?php echo $oid;?>)">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <strong style="color:#1a6e8c;">#<?php echo $oid; ?></strong>
                <span style="font-size:13px;font-weight:600;"><?php echo esc_html(trim("$forename $surname")); ?></span>
                <span style="font-size:12px;color:#666;"><?php echo esc_html($order->get_billing_email()); ?></span>
                <span style="font-size:12px;background:#e8f4f8;color:#1a6e8c;padding:2px 8px;border-radius:10px;font-weight:600;">Ref: <?php echo esc_html($ref); ?></span>
                <?php if($p1_uid!=='—') echo '<span style="font-size:12px;background:#f0f7fb;color:#1a6e8c;padding:2px 8px;border-radius:10px;">UID: '.esc_html($p1_uid).'</span>'; ?>
                <?php if($sample_rx) echo '<span style="font-size:11px;background:#d4edda;color:#2e7d4f;padding:2px 8px;border-radius:10px;font-weight:600;">&#10003; Sample Received</span>'; ?>
                <?php if($tracking) echo '<span style="font-size:11px;background:#e8f4f8;color:#1a6e8c;padding:2px 8px;border-radius:10px;">&#128230; '.esc_html($tracking).'</span>'; ?>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:12px;color:#888;"><?php echo $order->get_date_created() ? $order->get_date_created()->date('d M Y') : ''; ?></span>
                <span style="padding:3px 10px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo $status_color[1];?>;color:<?php echo $status_color[0];?>"><?php echo ucfirst($order->get_status()); ?></span>
                <span id="rh-arrow-<?php echo $oid;?>" style="color:#1a6e8c;">&#9660;</span>
            </div>
        </div>

        <div class="rh-order-body" id="rh-order-body-<?php echo $oid;?>">

            <div class="rh-detail-grid">
                <div class="rh-detail-item"><label>Order ID</label><span>#<?php echo $oid; ?></span></div>
                <div class="rh-detail-item"><label>Reference</label><span><?php echo esc_html($ref); ?></span></div>
                <div class="rh-detail-item"><label>Order Type</label><span><?php echo esc_html(ucwords(str_replace('_',' ',$otype))); ?></span></div>
                <div class="rh-detail-item"><label>Email</label><span><?php echo esc_html($order->get_billing_email()); ?></span></div>
                <div class="rh-detail-item"><label>Last Transmitted</label><span><?php echo $last_tx ? esc_html($last_tx->created_at) : '—'; ?></span></div>
                <div class="rh-detail-item"><label>Tests on Order</label><span><?php echo esc_html(implode(', ', $tests) ?: '—'); ?></span></div>
                <div class="rh-detail-item"><label>Tracking Number</label>
                    <span><?php if($tracking): ?><a href="https://www.royalmail.com/track-your-item#/tracking-results/<?php echo rawurlencode($tracking);?>" target="_blank" style="color:#1a6e8c;"><?php echo esc_html($tracking); ?> &#8599;</a><?php else: ?><button type="button" class="button button-small" onclick="rhOrOpenTracking(<?php echo $oid;?>)">+ Add Tracking</button><?php endif; ?></span>
                </div>
                <div class="rh-detail-item"><label>Sample Received (ORR)</label>
                    <span><?php if($sample_rx): ?><span style="color:#2e7d4f;font-weight:600;">&#10003; <?php echo esc_html($order->get_meta('_repose_sample_received_at')); ?></span><?php else: ?><button type="button" class="button button-small" onclick="rhOrConfirmSample(<?php echo $oid;?>)">Mark Received &amp; Notify</button><?php endif; ?></span>
                </div>
                <div class="rh-detail-item"><label>Lab Result</label>
                    <span><?php echo $result_row ? '<span style="color:#2e7d4f;font-weight:600;">&#10003; '.esc_html(ucfirst($result_row->status)).'</span>' : '<span style="color:#856404;">Awaiting</span>'; ?></span>
                </div>
            </div>

            <!-- Patients -->
            <div style="margin-bottom:14px;">
                <div style="font-size:13px;font-weight:700;color:#1a6e8c;margin-bottom:10px;border-bottom:1px solid #d0e8f2;padding-bottom:5px;">Patients &amp; Test Assignments</div>
                <p style="font-size:12px;color:#856404;background:#fffbea;border:1px solid #f0a500;border-radius:4px;padding:7px 10px;margin:0 0 10px;">
                    <strong>Selective retransmit:</strong> Tick the patients to include. Untick patients whose results have already been received so only the required patient(s) are re-sent.
                </p>

                <!-- Patient 1 -->
                <?php
                $p1_meta_tests = (array) $order->get_meta('_repose_patient_1_tests');
                $p1_meta_test_names = implode(', ', array_filter(array_map(function($t){ return $t['name'] ?? ''; }, $p1_meta_tests)));
                ?>
                <div class="rh-patient-section">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">
                        <input type="checkbox" class="rh-patient-include" data-oid="<?php echo $oid;?>" data-pnum="1" checked style="width:16px;height:16px;">
                        <strong style="font-size:13px;color:#1a6e8c;">Patient 1 <?php if($p1_uid!=='—') echo '<span style="font-size:11px;background:#e8f4f8;padding:1px 7px;border-radius:8px;margin-left:4px;">'.esc_html($p1_uid).'</span>'; ?></strong>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;font-size:12px;margin-bottom:8px;">
                        <div><label style="color:#888;display:block;">Name</label><?php echo esc_html("$forename $surname"); ?></div>
                        <div><label style="color:#888;display:block;">DOB</label><?php echo esc_html($dob ?: '—'); ?></div>
                        <div><label style="color:#888;display:block;">Sex</label><?php echo esc_html(ucfirst($sex ?: '—')); ?></div>
                        <div><label style="color:#888;display:block;">Notes</label><?php echo esc_html($p1_notes ?: '—'); ?></div>
                    </div>
                    <?php if(!empty($pt_by_patient[$p1_uid])): ?>
                    <div style="font-size:12px;font-weight:600;margin-bottom:4px;">Tests:</div>
                    <?php foreach($pt_by_patient[$p1_uid] as $pt): ?>
                    <div style="display:flex;gap:8px;font-size:12px;padding:2px 0;">
                        <span style="flex:1;"><?php echo esc_html($pt->test_name); ?></span>
                        <span style="padding:1px 8px;border-radius:8px;font-size:11px;font-weight:600;background:<?php echo $pt->status==='complete'?'#d4edda':'#fff3cd';?>;color:<?php echo $pt->status==='complete'?'#2e7d4f':'#856404';?>"><?php echo esc_html(ucfirst($pt->status)); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php elseif($p1_meta_test_names): ?>
                    <div style="font-size:12px;font-weight:600;margin-bottom:4px;">Assigned Tests (from checkout):</div>
                    <div style="font-size:12px;color:#374151;padding:4px 8px;background:#f0f7fb;border-radius:4px;"><?php echo esc_html($p1_meta_test_names); ?></div>
                    <?php else: ?><div style="font-size:12px;color:#888;font-style:italic;">No individual tests assigned yet.</div><?php endif; ?>
                </div>

                <!-- Additional patients -->
                <?php foreach($add_patients as $i => $ap):
                    $pn    = $i + 2;
                    $p_uid = $order->get_meta("_repose_patient_{$pn}_uid") ?: '—';
                    // Fallback: read test assignments from order meta if registry has none
                    $ap_meta_tests = (array) $order->get_meta("_repose_patient_{$pn}_tests");
                    if ( empty($ap_meta_tests) && !empty($ap['tests']) ) $ap_meta_tests = (array)$ap['tests'];
                    $ap_meta_test_names = implode(', ', array_filter(array_map(function($t){ return $t['name'] ?? ''; }, $ap_meta_tests)));
                ?>
                <div class="rh-patient-section">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer;">
                        <input type="checkbox" class="rh-patient-include" data-oid="<?php echo $oid;?>" data-pnum="<?php echo $pn;?>" checked style="width:16px;height:16px;">
                        <strong style="font-size:13px;color:#1a6e8c;">Patient <?php echo $pn; ?> <?php if($p_uid!=='—') echo '<span style="font-size:11px;background:#e8f4f8;padding:1px 7px;border-radius:8px;margin-left:4px;">'.esc_html($p_uid).'</span>'; ?></strong>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;font-size:12px;margin-bottom:8px;">
                        <div><label style="color:#888;display:block;">Name</label><?php echo esc_html(trim(($ap['forename']??'').' '.($ap['surname']??''))); ?></div>
                        <div><label style="color:#888;display:block;">DOB</label><?php echo esc_html($ap['dob']??'—'); ?></div>
                        <div><label style="color:#888;display:block;">Sex</label><?php echo esc_html(ucfirst($ap['sex']??'—')); ?></div>
                        <div><label style="color:#888;display:block;">Notes</label><?php echo esc_html($ap['notes']??'—'); ?></div>
                    </div>
                    <?php if(!empty($pt_by_patient[$p_uid])): ?>
                    <div style="font-size:12px;font-weight:600;margin-bottom:4px;">Tests:</div>
                    <?php foreach($pt_by_patient[$p_uid] as $pt): ?>
                    <div style="display:flex;gap:8px;font-size:12px;padding:2px 0;">
                        <span style="flex:1;"><?php echo esc_html($pt->test_name); ?></span>
                        <span style="padding:1px 8px;border-radius:8px;font-size:11px;font-weight:600;background:<?php echo $pt->status==='complete'?'#d4edda':'#fff3cd';?>;color:<?php echo $pt->status==='complete'?'#2e7d4f':'#856404';?>"><?php echo esc_html(ucfirst($pt->status)); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php elseif($ap_meta_test_names): ?>
                    <div style="font-size:12px;font-weight:600;margin-bottom:4px;">Assigned Tests (from checkout):</div>
                    <div style="font-size:12px;color:#374151;padding:4px 8px;background:#f0f7fb;border-radius:4px;"><?php echo esc_html($ap_meta_test_names); ?></div>
                    <?php else: ?><div style="font-size:12px;color:#888;font-style:italic;">No individual tests assigned.</div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Amendment + Retransmit -->
            <div style="background:#fffbea;border:1px dashed #f0a500;border-radius:6px;padding:14px;margin-bottom:14px;">
                <div style="font-size:13px;font-weight:700;color:#856404;margin-bottom:10px;">Amend &amp; Re-transmit</div>
                <div class="rh-amend-row">
                    <div><label style="display:block;font-size:11px;font-weight:600;color:#888;margin-bottom:2px;">Patient 1 Forename</label>
                        <input type="text" id="rh-amend-<?php echo $oid;?>-forename" class="rh-fi" value="<?php echo esc_attr($forename); ?>"></div>
                    <div><label style="display:block;font-size:11px;font-weight:600;color:#888;margin-bottom:2px;">Patient 1 Surname</label>
                        <input type="text" id="rh-amend-<?php echo $oid;?>-surname" class="rh-fi" value="<?php echo esc_attr($surname); ?>"></div>
                    <div><label style="display:block;font-size:11px;font-weight:600;color:#888;margin-bottom:2px;">Date of Birth</label>
                        <input type="text" id="rh-amend-<?php echo $oid;?>-dob" class="rh-fi rh-flatpickr" value="<?php echo esc_attr($dob); ?>" placeholder="DD/MM/YYYY"></div>
                    <div><label style="display:block;font-size:11px;font-weight:600;color:#888;margin-bottom:2px;">Sex at Birth</label>
                        <select id="rh-amend-<?php echo $oid;?>-sex" class="rh-fi">
                            <option value="male" <?php selected($sex,'male');?>>Male</option>
                            <option value="female" <?php selected($sex,'female');?>>Female</option>
                        </select></div>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:#888;margin-bottom:2px;">Amendment Note (recorded in audit log)</label>
                    <textarea id="rh-amend-<?php echo $oid;?>-note" class="rh-fi" rows="2" placeholder="Describe what was changed and why..."></textarea>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" class="button" onclick="rhOrSaveAmend(<?php echo $oid;?>)">Save Amendments Only</button>
                    <button type="button" class="button button-primary" onclick="rhOrSaveAndRetransmit(<?php echo $oid;?>)"
                            style="background:#2e7d4f;border-color:#1f5c39;">&#9654; Save &amp; Re-transmit to Lab</button>
                    <span id="rh-amend-fb-<?php echo $oid;?>" style="display:none;font-size:13px;color:#2e7d4f;font-weight:600;"></span>
                </div>
            </div>

            <div style="display:flex;gap:8px;">
                <a href="<?php echo get_edit_post_link($oid); ?>" class="button button-small" target="_blank">View in WooCommerce &#8599;</a>
                <?php
                $reg_p1 = $p1_uid !== '—' ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}repose_patients WHERE patient_uid=%s",$p1_uid)) : 0;
                if($reg_p1): ?>
                <a href="<?php echo admin_url('admin.php?page=repose-patient-registry&patient_id='.(int)$reg_p1); ?>" class="button button-small">View Patient Profile</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if($total_pages>1): ?>
    <div style="margin-top:16px;">
        <?php
        $burl = admin_url('admin.php?page=repose-order-retrieval'
            .($s_email?'&s_email='.urlencode($s_email):'')
            .($s_name?'&s_name='.urlencode($s_name):'')
            .($s_ref?'&s_ref='.urlencode($s_ref):'')
            .($s_uid?'&s_uid='.urlencode($s_uid):'')
            .($s_order?"&s_order=$s_order":''));
        for($i=1;$i<=$total_pages;$i++){
            $a=$i===$page_num?'button-primary':'';
            echo "<a href='".esc_url($burl.'&paged='.$i)."' class='button $a' style='margin-right:4px'>$i</a>";
        } ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Tracking modal -->
<div id="rh-or-tracking-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:999999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:440px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 6px;color:#1a6e8c;">Add Royal Mail Tracking Number</h3>
        <p style="margin:0 0 14px;font-size:13px;color:#555;">Order #<span id="rh-or-track-label"></span></p>
        <input type="hidden" id="rh-or-track-oid" value="">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Tracking Number *</label>
        <input type="text" id="rh-or-track-input" style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;font-weight:600;box-sizing:border-box;margin-bottom:6px;" placeholder="e.g. FI963365846GB">
        <p style="margin:0 0 16px;font-size:12px;color:#888;">Customer will receive a dispatch email.</p>
        <button type="button" class="button button-primary" onclick="rhOrConfirmTracking()">Save &amp; Send Email</button>
        <button type="button" class="button" onclick="document.getElementById('rh-or-tracking-modal').style.display='none'" style="margin-left:8px;">Cancel</button>
        <span id="rh-or-track-fb" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
    </div>
</div>

<script>
var rhOrAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce')]); ?>;

function rhOrNotice(msg,err){
    var el=document.getElementById('rh-or-notice');
    el.textContent=msg; el.style.background=err?'#f8d7da':'#d4edda';
    el.style.color=err?'#721c24':'#155724'; el.style.border='1px solid '+(err?'#f5c6cb':'#c3e6cb');
    el.style.display='block'; setTimeout(function(){el.style.display='none';},5000);
}
function rhPost(data,cb){
    data.nonce=rhOrAjax.nonce;
    var body=Object.keys(data).map(function(k){return encodeURIComponent(k)+'='+encodeURIComponent(data[k]);}).join('&');
    fetch(rhOrAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();}).then(cb).catch(function(){rhOrNotice('Network error',true);});
}
function rhToggleOrderCard(oid){
    var body=document.getElementById('rh-order-body-'+oid);
    var arrow=document.getElementById('rh-arrow-'+oid);
    var open=body.classList.toggle('open');
    arrow.textContent=open?'▲':'▼';
}
function rhOrSaveAmend(oid){
    var btn=event.target; btn.disabled=true; btn.textContent='Saving...';
    rhPost({action:'repose_save_order_edit',order_id:oid,
        forename:document.getElementById('rh-amend-'+oid+'-forename').value,
        surname:document.getElementById('rh-amend-'+oid+'-surname').value,
        dob:document.getElementById('rh-amend-'+oid+'-dob').value,
        sex:document.getElementById('rh-amend-'+oid+'-sex').value,
        edit_note:document.getElementById('rh-amend-'+oid+'-note').value,
    },function(r){
        btn.disabled=false; btn.textContent='Save Amendments Only';
        var fb=document.getElementById('rh-amend-fb-'+oid);
        if(r&&r.success){fb.textContent='✓ Saved';fb.style.display='inline';rhOrNotice('Changes saved for order #'+oid);}
        else{rhOrNotice((r&&r.data)?r.data:'Save failed.',true);}
    });
}
function rhOrSaveAndRetransmit(oid){
    var checkboxes=document.querySelectorAll('.rh-patient-include[data-oid="'+oid+'"]');
    var included=[]; checkboxes.forEach(function(cb){if(cb.checked)included.push(cb.dataset.pnum);});
    if(!included.length){alert('Please tick at least one patient to retransmit.');return;}
    var msg='Retransmit order #'+oid+' to the laboratory';
    if(included.length<checkboxes.length) msg+=' for Patient '+included.join(' and Patient ')+' only';
    if(!confirm(msg+'?'))return;
    var btn=event.target; btn.disabled=true; btn.textContent='Retransmitting...';
    rhPost({action:'repose_retransmit_order',order_id:oid,
        forename:document.getElementById('rh-amend-'+oid+'-forename').value,
        surname:document.getElementById('rh-amend-'+oid+'-surname').value,
        dob:document.getElementById('rh-amend-'+oid+'-dob').value,
        sex:document.getElementById('rh-amend-'+oid+'-sex').value,
        edit_note:document.getElementById('rh-amend-'+oid+'-note').value,
        include_patients:included.join(','),
    },function(r){
        btn.disabled=false; btn.textContent='▶ Save & Re-transmit to Lab';
        if(r&&r.success){rhOrNotice(r.data.message||'Retransmitted.');setTimeout(function(){location.reload();},1500);}
        else{rhOrNotice((r&&r.data)?r.data:'Retransmit failed.',true);}
    });
}
function rhOrOpenTracking(oid){
    document.getElementById('rh-or-track-oid').value=oid;
    document.getElementById('rh-or-track-label').textContent=oid;
    document.getElementById('rh-or-track-input').value='';
    document.getElementById('rh-or-track-fb').style.display='none';
    document.getElementById('rh-or-tracking-modal').style.display='block';
}
function rhOrConfirmTracking(){
    var oid=document.getElementById('rh-or-track-oid').value;
    var t=document.getElementById('rh-or-track-input').value.trim();
    if(!t){alert('Enter a tracking number.');return;}
    var btn=event.target; btn.disabled=true; btn.textContent='Saving...';
    rhPost({action:'repose_add_tracking',order_id:oid,tracking_number:t},function(r){
        btn.disabled=false; btn.textContent='Save & Send Email';
        if(r&&r.success){document.getElementById('rh-or-track-fb').textContent='✓ Saved';document.getElementById('rh-or-track-fb').style.display='inline';setTimeout(function(){location.reload();},1500);}
        else{alert((r&&r.data)?r.data:'Failed.');}
    });
}
function rhOrConfirmSample(oid){
    if(!confirm('Mark sample as received for order #'+oid+' and notify customer?'))return;
    rhPost({action:'repose_receive_orr',order_id:oid},function(r){
        if(r&&r.success){rhOrNotice('Sample receipt confirmed and customer notified.');setTimeout(function(){location.reload();},1500);}
        else{rhOrNotice((r&&r.data)?r.data:'Failed.',true);}
    });
}
document.getElementById('rh-or-tracking-modal').addEventListener('click',function(e){if(e.target===this)this.style.display='none';});
</script>
