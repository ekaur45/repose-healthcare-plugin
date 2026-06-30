<?php
/**
 * Customer-facing My Test Results page — mobile-responsive layout.
 * Notes are shown only here (not in email) for data protection.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wc_add_notice( __( 'Please log in to view your test results.', 'repose-healthcare' ), 'notice' );
    wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
    exit;
}

$brand_color = get_option( 'repose_brand_color', '#1a6e8c' );
?>
<style>
/* ── Mobile-first results dashboard ───────────────────────────── */
.rh-results { font-family: inherit; }
.rh-results h2 {
    color: <?php echo esc_attr($brand_color); ?>;
    font-size: clamp(18px, 4vw, 22px);
    margin-bottom: 6px;
}
.rh-results p.rh-sub {
    color: #6b7280;
    font-size: 14px;
    margin-top: 0;
    margin-bottom: 20px;
}
.rh-empty {
    padding: 22px 18px;
    background: #f0f7fb;
    border: 1px dashed #b3d4e0;
    border-radius: 8px;
    font-size: 14px;
    color: #555;
}

/* ── Card layout — works on all screen sizes ───────────────────── */
.rh-result-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.rh-result-card-header {
    background: <?php echo esc_attr($brand_color); ?>;
    color: #fff;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
}
.rh-result-card-header .rh-card-title {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
}
.rh-result-card-header .rh-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(255,255,255,0.25);
    color: #fff;
    white-space: nowrap;
}
.rh-result-card-body {
    padding: 16px;
}
.rh-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.rh-detail-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.rh-detail-item .rh-detail-label {
    font-size: 11px;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.rh-detail-item .rh-detail-value {
    font-size: 14px;
    color: #111827;
    font-weight: 500;
}
.rh-notes-box {
    background: #f0f7fb;
    border-left: 4px solid <?php echo esc_attr($brand_color); ?>;
    border-radius: 0 6px 6px 0;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.rh-notes-box .rh-notes-heading {
    font-weight: 700;
    color: <?php echo esc_attr($brand_color); ?>;
    font-size: 13px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.rh-notes-box ul {
    margin: 0;
    padding-left: 18px;
}
.rh-notes-box ul li {
    font-size: 13px;
    color: #374151;
    margin-bottom: 4px;
    line-height: 1.5;
}
.rh-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: <?php echo esc_attr($brand_color); ?>;
    color: #fff !important;
    text-decoration: none !important;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: opacity .15s;
}
.rh-btn:hover { opacity: .87; }
.rh-btn-outline {
    background: transparent;
    color: <?php echo esc_attr($brand_color); ?> !important;
    border: 2px solid <?php echo esc_attr($brand_color); ?>;
}
.rh-card-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
    margin-top: 4px;
}

@media (max-width: 480px) {
    .rh-result-card-header { padding: 10px 12px; }
    .rh-result-card-body   { padding: 12px; }
    .rh-btn                { width: 100%; justify-content: center; }
    .rh-card-actions       { flex-direction: column; }
    .rh-detail-grid        { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="rh-results">
    <h2><?php esc_html_e( 'My Test Results', 'repose-healthcare' ); ?></h2>
    <p class="rh-sub"><?php esc_html_e( 'Your approved results are listed below. Clinical notes from your provider are shown here securely.', 'repose-healthcare' ); ?></p>

    <?php if ( empty( $results ) ) : ?>
        <div class="rh-empty">
            <strong><?php esc_html_e( 'No results available yet.', 'repose-healthcare' ); ?></strong><br>
            <?php esc_html_e( 'You will receive an email when your results have been reviewed and are ready to view.', 'repose-healthcare' ); ?>
        </div>
    <?php else : ?>

        <?php foreach ( $results as $result ) :
            $notes  = HN_Repose_Results_Manager::get_notes( (int) $result->id, false );
            $dl_url = add_query_arg( array(
                'action'    => 'repose_view_result',
                'result_id' => $result->id,
                'nonce'     => wp_create_nonce( 'repose_download_' . $result->id ),
            ), admin_url( 'admin-ajax.php' ) );
            $date_released = $result->approved_at
                ? date_i18n( get_option( 'date_format' ), strtotime( $result->approved_at ) )
                : '—';
        ?>
        <div class="rh-result-card">
            <div class="rh-result-card-header">
                <p class="rh-card-title">
                    <?php echo esc_html( $result->test_type ?: __( 'Test Result', 'repose-healthcare' ) ); ?>
                </p>
                <span class="rh-badge">&#10003; <?php esc_html_e( 'Available', 'repose-healthcare' ); ?></span>
            </div>

            <div class="rh-result-card-body">
                <div class="rh-detail-grid">
                    <div class="rh-detail-item">
                        <span class="rh-detail-label"><?php esc_html_e( 'Order', 'repose-healthcare' ); ?></span>
                        <span class="rh-detail-value">#<?php echo esc_html( $result->order_id ); ?></span>
                    </div>
                    <div class="rh-detail-item">
                        <span class="rh-detail-label"><?php esc_html_e( 'Reference', 'repose-healthcare' ); ?></span>
                        <span class="rh-detail-value"><?php echo esc_html( $result->reference_num ?: '—' ); ?></span>
                    </div>
                    <div class="rh-detail-item">
                        <span class="rh-detail-label"><?php esc_html_e( 'Date Released', 'repose-healthcare' ); ?></span>
                        <span class="rh-detail-value"><?php echo esc_html( $date_released ); ?></span>
                    </div>
                </div>

                <?php if ( ! empty( $notes ) ) : ?>
                <div class="rh-notes-box">
                    <div class="rh-notes-heading">
                        &#128203; <?php esc_html_e( 'Clinical Notes from Your Provider', 'repose-healthcare' ); ?>
                    </div>
                    <ul>
                        <?php foreach ( $notes as $note ) : ?>
                            <li><?php echo wp_kses_post( $note->note ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="rh-card-actions">
                    <a href="<?php echo esc_url( $dl_url ); ?>" class="rh-btn" target="_blank">
                        &#8681; <?php esc_html_e( 'Download PDF Report', 'repose-healthcare' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
