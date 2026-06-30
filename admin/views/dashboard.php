<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap repose-admin">
    <h1>&#129657; Repose Healthcare — Dashboard</h1>

    <?php
    global $wpdb;

    $pending_auth    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}repose_order_queue WHERE status = 'pending' AND queue_type = 'manual_auth'" );
    $pending_results = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}repose_results WHERE status = 'pending_review'" );
    $incomplete      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}repose_order_queue WHERE status = 'pending' AND queue_type = 'incomplete'" );
    $total_patients  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}repose_patients" );
    $awaiting_orr    = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT l.order_id) FROM {$wpdb->prefix}repose_audit_log l
         WHERE l.action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
         AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.ID = l.order_id AND pm.meta_key = '_repose_sample_received' AND pm.meta_value = '1'
         )"
    );
    $awaiting_tracking = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT l.order_id) FROM {$wpdb->prefix}repose_audit_log l
         WHERE l.action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
         AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->postmeta} pm
             WHERE pm.post_id = l.order_id AND pm.meta_key = '_repose_tracking_number' AND pm.meta_value != ''
         )"
    );

    // Recent activity (last 7 days)
    $recent_tx = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}repose_audit_log
         WHERE action IN ('auto_transmitted','manual_approved','manual_transmitted','manual_created')
         AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $recent_results = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}repose_results
         WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    ?>

    <!-- ── Stats row 1: action required ───────────────────────────────────── -->
    <h2 style="margin:0 0 10px;font-size:14px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;">Action Required</h2>
    <div class="repose-stats" style="margin-bottom:28px;">
        <div class="repose-stat <?php echo $pending_auth > 0 ? 'repose-stat--alert' : ''; ?>">
            <span class="repose-stat__number"><?php echo $pending_auth; ?></span>
            <span class="repose-stat__label">Orders Awaiting Auth</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-auth-queue' ); ?>" class="repose-stat__link">View Queue &rarr;</a>
        </div>
        <div class="repose-stat <?php echo $pending_results > 0 ? 'repose-stat--alert' : ''; ?>">
            <span class="repose-stat__number"><?php echo $pending_results; ?></span>
            <span class="repose-stat__label">Results Pending Review</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-results-queue' ); ?>" class="repose-stat__link">View Queue &rarr;</a>
        </div>
        <div class="repose-stat <?php echo $incomplete > 0 ? 'repose-stat--warning' : ''; ?>">
            <span class="repose-stat__number"><?php echo $incomplete; ?></span>
            <span class="repose-stat__label">Incomplete Orders</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-auth-queue' ); ?>" class="repose-stat__link">View &rarr;</a>
        </div>
        <div class="repose-stat <?php echo $awaiting_tracking > 0 ? 'repose-stat--warning' : ''; ?>">
            <span class="repose-stat__number"><?php echo $awaiting_tracking; ?></span>
            <span class="repose-stat__label">Awaiting Tracking No.</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-transmission-log&view_mode=queue' ); ?>" class="repose-stat__link">Add Tracking &rarr;</a>
        </div>
        <div class="repose-stat <?php echo $awaiting_orr > 0 ? 'repose-stat--warning' : ''; ?>">
            <span class="repose-stat__number"><?php echo $awaiting_orr; ?></span>
            <span class="repose-stat__label">Awaiting ORR Confirmation</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-order-retrieval&filter_orr=1' ); ?>" class="repose-stat__link">View Orders &rarr;</a>
        </div>
    </div>

    <!-- ── Stats row 2: overview ──────────────────────────────────────────── -->
    <h2 style="margin:0 0 10px;font-size:14px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;">Overview</h2>
    <div class="repose-stats" style="margin-bottom:28px;">
        <div class="repose-stat">
            <span class="repose-stat__number"><?php echo $total_patients; ?></span>
            <span class="repose-stat__label">Patients in Registry</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-patient-registry' ); ?>" class="repose-stat__link">View Registry &rarr;</a>
        </div>
        <div class="repose-stat">
            <span class="repose-stat__number"><?php echo $recent_tx; ?></span>
            <span class="repose-stat__label">Orders Transmitted (7d)</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-transmission-log' ); ?>" class="repose-stat__link">View Log &rarr;</a>
        </div>
        <div class="repose-stat">
            <span class="repose-stat__number"><?php echo $recent_results; ?></span>
            <span class="repose-stat__label">Results Received (7d)</span>
            <a href="<?php echo admin_url( 'admin.php?page=repose-results-queue' ); ?>" class="repose-stat__link">View Results &rarr;</a>
        </div>
    </div>

    <!-- ── Quick Actions ──────────────────────────────────────────────────── -->
    <h2 style="margin:0 0 10px;font-size:14px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;">Quick Actions</h2>
    <div class="repose-actions">
        <a href="<?php echo admin_url( 'admin.php?page=repose-create-order' ); ?>" class="button button-primary">+ Create New Order</a>
        <a href="<?php echo admin_url( 'admin.php?page=repose-order-retrieval' ); ?>" class="button">&#128269; Find &amp; Retransmit Order</a>
        <a href="<?php echo admin_url( 'admin.php?page=repose-patient-registry' ); ?>" class="button">&#128101; Patient Registry</a>
        <a href="<?php echo admin_url( 'admin.php?page=repose-upload-result' ); ?>" class="button">Upload Result PDF</a>
        <a href="<?php echo admin_url( 'admin.php?page=repose-settings' ); ?>" class="button">Settings</a>
    </div>

    <!-- ── Recent activity feed ───────────────────────────────────────────── -->
    <?php
    $recent_log = $wpdb->get_results(
        "SELECT l.*, u.display_name FROM {$wpdb->prefix}repose_audit_log l
         LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
         ORDER BY l.created_at DESC LIMIT 10"
    );
    if ( $recent_log ) :
    ?>
    <h2 style="margin:24px 0 10px;font-size:14px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.05em;">Recent Activity</h2>
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;">
        <?php foreach ( $recent_log as $i => $entry ) :
            $action_labels = array(
                'auto_transmitted'    => array( '&#9654; Auto Transmitted',   '#2e7d4f' ),
                'manual_approved'     => array( '&#10003; Manual Approved',   '#2e7d4f' ),
                'manual_rejected'     => array( '&#10007; Rejected',          '#c0392b' ),
                'manual_transmitted'  => array( '&#9654; Transmitted',        '#2e7d4f' ),
                'manual_created'      => array( '+ Order Created',            '#1a6e8c' ),
                'result_uploaded'     => array( '&#8679; Result Uploaded',    '#1a6e8c' ),
                'result_approved'     => array( '&#10003; Result Approved',   '#2e7d4f' ),
                'json_result_imported'=> array( '&#8679; HL7 Result In',      '#1a6e8c' ),
                'orr_received'        => array( '&#10003; ORR Received',      '#2e7d4f' ),
                'tracking_added'      => array( '&#128230; Tracking Added',   '#1a6e8c' ),
                'fields_edited'       => array( '&#9998; Fields Edited',      '#856404' ),
                'test_assigned'       => array( '&#43; Test Assigned',        '#1a6e8c' ),
            );
            $al = $action_labels[ $entry->action ] ?? array( ucwords( str_replace('_',' ',$entry->action) ), '#555' );
            $staff = $entry->display_name ?: ( $entry->user_id == 0 ? 'System/API' : 'User #'.$entry->user_id );
            $bg = $i % 2 === 0 ? '#fff' : '#f9f9f9';
        ?>
        <div style="display:flex;align-items:flex-start;gap:14px;padding:10px 16px;border-bottom:1px solid #f0f0f0;background:<?php echo $bg;?>;font-size:12px;">
            <span style="min-width:155px;color:#888;"><?php echo esc_html($entry->created_at); ?></span>
            <span style="min-width:170px;font-weight:600;color:<?php echo $al[1];?>;"><?php echo $al[0]; ?></span>
            <?php if ($entry->order_id) : ?>
            <span style="min-width:70px;"><a href="<?php echo get_edit_post_link($entry->order_id);?>" style="color:#1a6e8c;">#<?php echo esc_html($entry->order_id);?></a></span>
            <?php else : ?><span style="min-width:70px;color:#ccc;">—</span><?php endif; ?>
            <span style="flex:1;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($entry->detail); ?></span>
            <span style="min-width:110px;color:#888;text-align:right;"><?php echo esc_html($staff); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
