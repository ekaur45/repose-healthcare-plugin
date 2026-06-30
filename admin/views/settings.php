<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap repose-admin">
    <h1>Repose Healthcare — Settings</h1>
    <div id="settings-feedback" style="display:none;" class="notice notice-success is-dismissible"><p>Settings saved.</p></div>

    <!-- Tab nav -->
    <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
        <a href="#tab-lab"     class="nav-tab nav-tab-active" data-tab="tab-lab">Laboratory</a>
        <a href="#tab-brand"   class="nav-tab" data-tab="tab-brand">Branding</a>
        <a href="#tab-emails"  class="nav-tab" data-tab="tab-emails">Email Templates</a>
        <a href="#tab-portal"  class="nav-tab" data-tab="tab-portal">Lab Portal</a>
        <a href="#tab-api"     class="nav-tab" data-tab="tab-api">API / Security</a>
    </nav>

    <form id="repose-settings-form">

        <!-- ── Lab transmission ── -->
        <div id="tab-lab" class="repose-tab-panel">
            <h2>Laboratory Transmission</h2>
            <table class="form-table">
                <tr>
                    <th><label for="auto_transmit">Auto-Transmit to Lab</label></th>
                    <td>
                        <label class="repose-toggle">
                            <input type="checkbox" name="auto_transmit" id="auto_transmit" value="1" <?php checked( get_option( 'repose_auto_transmit', '0' ), '1' ); ?>>
                            <span class="repose-toggle-slider"></span>
                        </label>
                        <p class="description">When enabled, new orders are transmitted to the lab automatically on payment — no manual authorisation required. When disabled, all orders are queued for manual review in the Auth Queue.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="transmission_method">Transmission Method</label></th>
                    <td>
                        <select name="transmission_method" id="transmission_method">
                            <option value="email" <?php selected(get_option('repose_transmission_method','email'),'email'); ?>>Email Attachment</option>
                            <option value="azure" <?php selected(get_option('repose_transmission_method','email'),'azure'); ?>>Azure Blob Storage</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="lab_email">Laboratory Email</label></th>
                    <td><input type="email" name="lab_email" id="lab_email" class="regular-text" value="<?php echo esc_attr(get_option('repose_lab_email')); ?>"></td>
                </tr>
            </table>
            <h2>Azure Blob Storage — TDL Integration</h2>
            <p class="description">Outbound ordering CSVs are uploaded to the <strong>Requests Container</strong>. Ensure the SAS token has write access to that container.</p>
            <table class="form-table">
                <tr><th><label for="azure_account">Storage Account Name</label></th><td>
                    <input type="text" name="azure_account" id="azure_account" class="regular-text" value="<?php echo esc_attr(get_option('repose_azure_account')); ?>">
                    <p class="description">e.g. <code>mystorageaccount</code> — the URL becomes <code>https://mystorageaccount.blob.core.windows.net</code></p>
                </td></tr>
                <tr><th><label for="azure_requests_container">Requests Container</label></th><td>
                    <input type="text" name="azure_requests_container" id="azure_requests_container" class="regular-text" value="<?php echo esc_attr(get_option('repose_azure_requests_container','requests')); ?>">
                    <p class="description">Container where outbound order CSVs are uploaded. Default: <code>requests</code></p>
                </td></tr>
                <tr><th><label for="azure_container">Results Container</label></th><td>
                    <input type="text" name="azure_container" id="azure_container" class="regular-text" value="<?php echo esc_attr(get_option('repose_azure_container','results')); ?>">
                    <p class="description">Container where TDL uploads result files (if applicable). Default: <code>results</code></p>
                </td></tr>
                <tr><th><label for="azure_sas_token">SAS Token</label></th><td>
                    <input type="password" name="azure_sas_token" id="azure_sas_token" class="large-text" value="<?php echo esc_attr(get_option('repose_azure_sas_token')); ?>">
                    <p class="description">Shared Access Signature token with <strong>write</strong> permission on the Requests container. Generate in Azure Portal → Storage Account → Shared Access Signature.</p>
                </td></tr>
            </table>

            <h2>TDL Test Code Mapping</h2>
            <p class="description">Map each WooCommerce product to its TDL test code. These codes are sent in the <code>TDLTestCode</code> column of the ordering CSV. If no mapping is set, the product name is used as a fallback.</p>
            <?php
            $products_for_map = wc_get_products( array( 'status' => 'publish', 'limit' => 200, 'return' => 'objects' ) );
            $tdl_map = json_decode( get_option( 'repose_tdl_test_codes', '{}' ), true ) ?: array();
            ?>
            <table class="form-table" id="tdl-test-code-table">
                <thead><tr>
                    <th style="width:40%">WooCommerce Product</th>
                    <th>TDL Test Code <span style="font-weight:400;color:#888;">(max 20 chars, A-Z 0-9)</span></th>
                    <th>Is Self Collect</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $products_for_map as $prod ) : ?>
                <tr>
                    <td style="font-size:13px;"><?php echo esc_html($prod->get_name()); ?> <span style="color:#888;font-size:11px;">(ID: <?php echo $prod->get_id(); ?>)</span></td>
                    <td><input type="text" name="tdl_code_<?php echo $prod->get_id(); ?>"
                               class="regular-text tdl-code-input"
                               data-product-id="<?php echo $prod->get_id(); ?>"
                               value="<?php echo esc_attr($tdl_map[$prod->get_id()] ?? ''); ?>"
                               placeholder="e.g. MRSA1, CTNG2, HBA1C"
                               maxlength="20" style="width:200px;font-family:sans-serif;text-transform:uppercase;"></td>
                    <td><input type="checkbox" name="is_self_collect_<?php echo $prod->get_id(); ?>" value="1" <?php checked(!empty($tdl_map['is_self_collect_' . $prod->get_id()])); ?>></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px;">Batch CSV Management</h2>
            <p class="description">Generate and send a batch CSV covering all transmitted orders not yet included in a batch. This is useful for sending all pending orders at once rather than one-by-one.</p>
            <div style="display:flex;gap:10px;align-items:center;">
                <button type="button" class="button button-primary" id="btn-send-batch">Generate &amp; Send Batch CSV Now</button>
                <span id="batch-feedback" style="font-size:13px;color:#2e7d4f;display:none;"></span>
            </div>

        </div>

        <!-- ── Branding ── -->
        <div id="tab-brand" class="repose-tab-panel" style="display:none;">
            <h2>Company Branding</h2>
            <p class="description">These details appear on branded PDF reports sent to patients.</p>
            <table class="form-table">
                <tr><th><label for="company_name">Company Name</label></th><td><input type="text" name="company_name" id="company_name" class="regular-text" value="<?php echo esc_attr(get_option('repose_company_name','Repose Healthcare Ltd')); ?>"></td></tr>
                <tr><th><label for="company_address">Company Address</label></th><td><textarea name="company_address" id="company_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('repose_company_address','')); ?></textarea></td></tr>
                <tr><th><label for="company_phone">Phone</label></th><td><input type="text" name="company_phone" id="company_phone" class="regular-text" value="<?php echo esc_attr(get_option('repose_company_phone','')); ?>"></td></tr>
                <tr><th><label for="company_email">Contact Email</label></th><td><input type="email" name="company_email" id="company_email" class="regular-text" value="<?php echo esc_attr(get_option('repose_company_email','hello@reposehealthcare.com')); ?>"></td></tr>
                <tr><th><label for="company_website">Website</label></th><td><input type="text" name="company_website" id="company_website" class="regular-text" value="<?php echo esc_attr(get_option('repose_company_website','www.reposehealthcare.com')); ?>"></td></tr>
                <tr>
                    <th><label for="logo_url">Logo URL</label></th>
                    <td>
                        <input type="text" name="logo_url" id="logo_url" class="large-text" value="<?php echo esc_attr(get_option('repose_logo_url','')); ?>">
                        <button type="button" class="button" id="btn-select-logo">Select Logo</button>
                        <p class="description">Upload your logo via Media Library or enter a URL. Recommended: PNG, max 200×60px.</p>
                        <?php if ( get_option('repose_logo_url') ) : ?>
                            <br><img src="<?php echo esc_url(get_option('repose_logo_url')); ?>" style="max-height:50px;margin-top:8px;border:1px solid #ddd;padding:4px;border-radius:4px;">
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Brand Colour</th>
                    <td>
                        <input type="color" name="brand_color" value="<?php echo esc_attr(get_option('repose_brand_color','#1a6e8c')); ?>" style="width:60px;height:36px;padding:2px;border-radius:4px;">
                        <p class="description">Used in PDF report headers and email templates.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Email Templates ── -->
        <div id="tab-emails" class="repose-tab-panel" style="display:none;">
            <h2>Email Templates</h2>
            <p class="description">Use <code>{name}</code>, <code>{reference}</code>, <code>{site_name}</code> as placeholders. The <em>Dispatch</em> template also supports <code>{tracking_number}</code>. The <em>Sample Receipt</em> template supports <code>{support_email}</code> and <code>{support_phone}</code>. The preview updates live.</p>

            <?php
            $brand_color = get_option('repose_brand_color','#1a6e8c');
            $company_name = get_option('repose_company_name','Repose Healthcare Ltd');

            $email_templates = array(
                'result_ready' => array(
                    'label'   => 'Results Ready Notification',
                    'subject' => get_option('repose_result_email_subject', 'Your Repose Healthcare Test Results Are Ready'),
                    'body'    => get_option('repose_result_email_body',    "Dear {name},\n\nYour test results are now available. Please log in to your account to view and download your report.\n\nKind regards,\n{site_name}"),
                ),
                'review' => array(
                    'label'   => 'Follow-Up Review Email (sent 24h after results released)',
                    'subject' => get_option('repose_review_email_subject', 'How was your experience with Repose Healthcare?'),
                    'body'    => get_option('repose_review_email_body',    "Dear {name},\n\nI hope this email finds you well. Thank you once again for choosing Repose Healthcare — we truly appreciate your support.\n\nWe're always looking to improve and ensure we're delivering the best possible experience. If you have a moment, we would be grateful if you could leave us a quick review and share your thoughts about your experience with us.\n\nYour feedback not only helps us grow but also helps others make confident decisions.\n\nThank you again for your time and support. If there's anything we can do better, please don't hesitate to let us know.\n\nWarm regards,\nRepose Healthcare"),
                ),
                'sample_received' => array(
                    'label'   => 'Sample Receipt Confirmation (ORR-HL7)',
                    'subject' => get_option('repose_sample_received_email_subject', 'Your Sample Has Been Received'),
                    'body'    => get_option('repose_sample_received_email_body',    "Dear {name},\n\nWe're writing to confirm that your sample has been safely received by our laboratory.\n\nOur team has now begun processing your test. For most tests, results are available within 3 working days. However, some specialist tests may take slightly longer. If you have not received your results after 72 hours, please feel free to contact us and we will be happy to provide an update on your test status.\n\nAs soon as your results are ready, you will be notified by email and will be able to access them securely via your online account.\n\nIf you have any questions in the meantime, please don't hesitate to contact our support team at {support_email} or {support_phone}.\n\nThank you for choosing {site_name}.\n\nKind regards,\nThe {site_name} Team"),
                ),
                'dispatch' => array(
                    'label'   => 'Order Dispatched (Tracking Number)',
                    'subject' => get_option('repose_dispatch_email_subject', 'Your Order Has Been Dispatched'),
                    'body'    => get_option('repose_dispatch_email_body',    "Dear {name},\n\nWe're pleased to let you know that your order has been dispatched today and is on its way to you.\n\nYour Royal Mail tracking number: {tracking_number}\n\nDelivery is usually completed the next working day; however, please allow up to three working days for delivery within the UK.\n\nOnce your kit arrives, please follow the instructions included to collect and return your sample.\n\nThank you for choosing {site_name}.\n\nKind regards,\n{site_name}"),
                ),
            );

            foreach ( $email_templates as $key => $tpl ) :
            ?>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:24px;">
                <h3 style="margin:0 0 16px;color:#1a6e8c;font-size:14px;"><?php echo esc_html($tpl['label']); ?></h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px;">Subject</label>
                        <input type="text" name="<?php echo esc_attr($key); ?>_email_subject" class="large-text email-subject-<?php echo esc_attr($key); ?>"
                               value="<?php echo esc_attr($tpl['subject']); ?>"
                               style="width:100%;margin-bottom:12px;" oninput="updatePreview('<?php echo esc_attr($key); ?>')">

                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px;">Body</label>
                        <textarea name="<?php echo esc_attr($key); ?>_email_body" rows="8" class="large-text email-body-<?php echo esc_attr($key); ?>"
                                  style="width:100%;font-family:sans-serif;font-size:13px;"
                                  oninput="updatePreview('<?php echo esc_attr($key); ?>')"><?php echo esc_textarea($tpl['body']); ?></textarea>

                        <div style="margin-top:8px;font-size:12px;color:#888;">
                            Placeholders: <code>{name}</code> <code>{reference}</code> <code>{site_name}</code> <code>{download_link}</code>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px;">Live Preview</label>
                        <div class="email-preview-<?php echo esc_attr($key); ?>" style="border:1px solid #ddd;border-radius:6px;overflow:hidden;font-size:13px;"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Review email settings -->
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:24px;">
                <h3 style="margin:0 0 14px;color:#1a6e8c;font-size:14px;">Review Email Settings</h3>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th><label for="review_url">Google Review URL</label></th>
                        <td>
                            <input type="url" name="review_url" id="review_url" class="large-text"
                                   value="<?php echo esc_attr(get_option('repose_review_url','https://g.page/r/CbeI7sxByQWwEAE/review')); ?>">
                            <p class="description">The link in the review email that takes customers to leave a Google review. This is already set to your Repose Healthcare Google review page.</p>
                        </td>
                    </tr>
                </table>

                <div style="margin-top:20px;padding:16px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:6px;">
                    <h4 style="margin:0 0 10px;color:#1a6e8c;font-size:13px;">&#9432; How the Review Email Works &amp; How to Test It</h4>
                    <p style="font-size:13px;color:#374151;margin:0 0 10px;line-height:1.6;">
                        The review email is sent <strong>automatically 24 hours after a result is approved and released to a patient</strong>. You do not need to do anything manually — it is scheduled by the system at the moment you click "Approve &amp; Notify" on a result in the Results Queue.
                    </p>
                    <p style="font-size:13px;color:#374151;margin:0 0 10px;line-height:1.6;">
                        <strong>To trigger it:</strong> In the Results Queue, tick the <strong>"24h Review"</strong> checkbox next to a result before clicking "Approve &amp; Notify". When that checkbox is ticked, the review email is scheduled to send 24 hours later via WordPress Cron.
                    </p>
                    <p style="font-size:13px;color:#374151;margin:0 0 10px;line-height:1.6;">
                        <strong>To test it immediately</strong> without waiting 24 hours: On your WordPress server, run <code>wp cron event run repose_send_review_email</code> using WP-CLI, or install the free "WP Crontrol" plugin which lets you manually trigger any scheduled cron job from the WordPress admin (Tools → Cron Events → find "repose_send_review_email" → Run Now).
                    </p>
                    <p style="font-size:13px;color:#374151;margin:0;">
                        <strong>Important:</strong> WordPress Cron only runs when someone visits your site. If your site is low-traffic, ask your hosting provider to set up a real server cron job calling <code>wp-cron.php</code> every 5 minutes so scheduled emails are never delayed.
                    </p>
                </div>
            </div>
        </div>
        <div id="tab-portal" class="repose-tab-panel" style="display:none;">
            <h2>Lab Upload Portal</h2>
            <p>The lab portal allows laboratories to upload result PDFs via a secure browser form — no API integration needed.</p>
            <table class="form-table">
                <tr>
                    <th><label for="lab_portal_token">Portal Access Token</label></th>
                    <td>
                        <input type="text" name="lab_portal_token" id="lab_portal_token" class="regular-text"
                               value="<?php echo esc_attr(get_option('repose_lab_portal_token','')); ?>">
                        <button type="button" class="button" id="btn-gen-token">Generate Token</button>
                        <p class="description">Share this URL with your laboratory:</p>
                        <?php
                        $token = get_option('repose_lab_portal_token','');
                        if ( $token ) :
                            $portal_url = home_url('/lab-portal/?token=' . $token);
                        ?>
                            <code style="display:block;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;margin-top:6px;word-break:break-all;">
                                <?php echo esc_html($portal_url); ?>
                            </code>
                            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($portal_url); ?>').then(function(){this.textContent='Copied!';}.bind(this))" style="margin-top:6px;">Copy URL</button>
                        <?php else : ?>
                            <em style="color:#888;">Generate a token first, then save settings to get the portal URL.</em>
                        <?php endif; ?>
                        <p class="description" style="margin-top:8px;">To regenerate the token (which invalidates the old link), click "Generate Token" and save.</p>
                    </td>
                </tr>
            </table>

            <h2>Flush Rewrite Rules</h2>
            <p>If the lab portal URL returns a 404, click this button to refresh WordPress permalinks:</p>
            <button type="button" class="button" id="btn-flush-rewrite">Flush Permalink Rules</button>
            <span id="flush-feedback" style="margin-left:10px;color:green;font-size:13px;"></span>
        </div>

        <!-- ── API / Security ── -->
        <div id="tab-api" class="repose-tab-panel" style="display:none;">
            <h2>Inbound API Token</h2>
            <p class="description">Token required by labs posting results <em>to</em> this plugin.</p>
            <table class="form-table">
                <tr>
                    <th><label for="api_token">Inbound Token (X-Repose-Token)</label></th>
                    <td>
                        <input type="text" name="api_token" id="api_token" class="regular-text" value="<?php echo esc_attr(get_option('repose_api_token','')); ?>">
                        <p class="description">Labs must send this in the <code>X-Repose-Token</code> header.</p>
                        <p class="description">JSON import endpoint: <code><?php echo esc_url(rest_url('repose/v1/results/json-import')); ?></code></p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:24px;">HL7 Inbound Endpoints</h2>
            <p class="description">The following REST endpoints accept inbound HL7 v2 messages from your laboratory system. All require the <strong>Inbound Token</strong> above sent as the <code>X-Repose-Token</code> HTTP header.</p>
            <table class="form-table">
                <tr>
                    <th>Main HL7 Endpoint<br><span style="font-weight:400;font-size:12px;color:#555;">ORR &amp; ORU messages</span></th>
                    <td>
                        <code style="display:block;padding:8px 10px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;word-break:break-all;">
                            POST &nbsp;<?php echo esc_url(rest_url('repose/v1/hl7')); ?>
                        </code>
                        <p class="description" style="margin-top:6px;">
                            <strong>Content-Type:</strong> <code>text/plain</code> (raw pipe-delimited HL7) or <code>application/json</code> with body <code>{"hl7": "&lt;raw message&gt;"}</code><br>
                            <strong>Supported types:</strong> <code>ORR</code> (sample receipt confirmation) &nbsp;|&nbsp; <code>ORU</code> (results ready)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>MRSA Landing Page HL7 Status</th>
                    <td>
                        <?php
                        $token_set    = ! empty( get_option( 'repose_api_token', '' ) );
                        $hl7_endpoint = rest_url( 'repose/v1/hl7' );
                        ?>
                        <?php if ( $token_set ) : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:6px;background:#d4edda;color:#2e7d4f;font-weight:600;font-size:13px;">
                            &#10003; HL7 integration active — inbound token is configured
                        </span>
                        <?php else : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:6px;background:#f8d7da;color:#842029;font-weight:600;font-size:13px;">
                            &#9888; No inbound token set — HL7 messages will be rejected (401)
                        </span>
                        <?php endif; ?>
                        <p class="description" style="margin-top:8px;">
                            Configure your lab system to POST HL7 messages to the endpoint above. For mrsatest.co.uk, share the endpoint URL and your inbound token with the laboratory.<br>
                            <strong>Quick share block for lab IT:</strong>
                        </p>
                        <textarea readonly rows="5" onclick="this.select()"
                                  style="width:100%;font-family:sans-serif;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;background:#f9f9f9;box-sizing:border-box;"
                        >HL7 Endpoint : <?php echo esc_url($hl7_endpoint); ?>

Auth Header  : X-Repose-Token: <?php echo esc_html(get_option('repose_api_token','<SET TOKEN ABOVE>')); ?>

Supported    : ORR^O02 (sample receipt)  |  ORU^R01 (results)
Content-Type : text/plain  OR  application/json {"hl7":"<message>"}</textarea>
                        <p class="description">Click the box to select all, then copy and paste into your email to the lab.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:24px;">ORR-only Endpoint</h2>
            <p class="description">Use this endpoint to confirm sample receipt without full HL7 parsing. Requires the <strong>Inbound Token</strong> above.</p>
            <table class="form-table">
                <tr>
                    <th>ORR-only Endpoint</th>
                    <td>
                        <code style="display:block;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;word-break:break-all;">
                            <?php echo esc_url(rest_url('repose/v1/orr-confirm')); ?>
                        </code>
                        <p class="description">POST JSON <code>{"order_id": 123}</code> or <code>{"reference": "R25C290069"}</code> to confirm sample receipt without full HL7 parsing.</p>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:24px;">&#9654; Test ORR HL7 Message</h2>
            <p class="description">Send a test ORR confirmation to verify your integration is working. The order's sample-received flag will be set and the customer will receive a notification email.</p>
            <table class="form-table">
                <tr>
                    <th><label for="rh-orr-test-order">Order ID to Test</label></th>
                    <td>
                        <input type="text" id="rh-orr-test-order" placeholder="e.g. 12345"
                               style="width:180px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;">
                        <button type="button" class="button" onclick="rhSendTestOrr()" style="margin-left:8px;">Send Test ORR</button>
                        <span id="rh-orr-test-fb" style="display:none;margin-left:10px;font-size:13px;"></span>
                        <p class="description" style="margin-top:8px;">
                            Or use <code>curl</code> from your lab system:<br>
                            <code style="display:block;margin-top:6px;padding:10px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;word-break:break-all;white-space:pre-wrap;">curl -s -X POST \
  "<?php echo esc_url(rest_url('repose/v1/orr-confirm')); ?>" \
  -H "Content-Type: application/json" \
  -H "X-Repose-Token: YOUR_INBOUND_TOKEN" \
  -d '{"order_id": 123}'</code>
                        </p>
                        <p class="description" style="margin-top:8px;">
                            To send a raw HL7 v2 ORR message, POST to the main HL7 endpoint:<br>
                            <code style="display:block;margin-top:6px;padding:10px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;word-break:break-all;white-space:pre-wrap;">curl -s -X POST \
  "<?php echo esc_url(rest_url('repose/v1/hl7')); ?>" \
  -H "Content-Type: text/plain" \
  -H "X-Repose-Token: YOUR_INBOUND_TOKEN" \
  --data-binary $'MSH|^~\\&|LAB|FACILITY|RIS|HOSPITAL|<?php echo date('YmdHis'); ?>||ORR^O02|MSG001|P|2.3\rMSA|AA|MSG001\rPID|1||PATIENT_UID|||DOE^JOHN\rORC|OK|ORDER_REF|||CM'</code>
                        </p>
                    </td>
                </tr>
            </table>
            <script>
            function rhSendTestOrr() {
                var orderId = document.getElementById('rh-orr-test-order').value.trim();
                if (!orderId) { alert('Please enter an order ID.'); return; }
                var fb = document.getElementById('rh-orr-test-fb');
                fb.textContent = 'Sending…'; fb.style.color = '#555'; fb.style.display = 'inline';
                var body = 'action=repose_receive_orr&nonce=<?php echo wp_create_nonce('repose_admin_nonce'); ?>&order_id=' + encodeURIComponent(orderId);
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method:'POST', credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body
                }).then(function(r){return r.json();}).then(function(r){
                    if (r && r.success) {
                        fb.textContent = '✓ ' + (r.data.message || 'ORR confirmation sent successfully.');
                        fb.style.color = '#2e7d4f';
                    } else {
                        fb.textContent = '✗ ' + (r && r.data ? r.data : 'Error — check order ID and try again.');
                        fb.style.color = '#c0392b';
                    }
                }).catch(function(){ fb.textContent = '✗ Network error.'; fb.style.color = '#c0392b'; });
            }
            </script>

            <h2 style="margin-top:24px;">Outbound Lab API Credentials</h2>
            <p class="description">Credentials used to fetch HL7 results <em>from</em> the external lab API (e.g. <code>http://35.178.62.52/api</code>).</p>
            <table class="form-table">
                <tr>
                    <th><label for="API_URL">Lab API URL</label></th>
                    <td>
                        <input type="text" name="API_URL" id="API_URL" class="large-text"
                               value="<?php echo esc_attr(get_option('API_URL','http://35.178.62.52/api')); ?>">
                        <p class="description">Base URL of the lab API, e.g. <code>http://35.178.62.52/api</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="API_USERNAME">API Username / Email</label></th>
                    <td>
                        <input type="text" name="API_USERNAME" id="API_USERNAME" class="regular-text"
                               value="<?php echo esc_attr(get_option('API_USERNAME','')); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="API_PASSWORD">API Password</label></th>
                    <td>
                        <input type="password" name="API_PASSWORD" id="API_PASSWORD" class="regular-text"
                               value="<?php echo esc_attr(get_option('API_PASSWORD','')); ?>">
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:24px;">How to trigger a result import</h2>
            <p>POST to the JSON import endpoint with the file key and your WooCommerce order ID:</p>
            <pre style="background:#f5f5f5;padding:12px;border-radius:4px;font-size:12px;overflow-x:auto;">curl -X POST <?php echo esc_url(rest_url('repose/v1/results/json-import')); ?>   -H "X-Repose-Token: YOUR_INBOUND_TOKEN"   -H "Content-Type: application/json"   -d '{ "data": { "key": "IN/202102081042" }, "order_id": 69 }'</pre>
            <p class="description">The plugin will authenticate with the lab API, fetch the HL7 file, save all data to the HL7 tables, and queue the result for admin review.</p>
        </div>

        <p class="submit" style="margin-top:20px;">
            <input type="submit" id="btn-save-settings" class="button button-primary" value="Save All Settings">
        </p>

    </form>
</div>

<script>
var brandColor   = '<?php echo esc_js(get_option('repose_brand_color','#1a6e8c')); ?>';
var companyName  = '<?php echo esc_js(get_option('repose_company_name','Repose Healthcare Ltd')); ?>';

function updatePreview(key) {
    var subject = document.querySelector('.email-subject-' + key).value;
    var body    = document.querySelector('.email-body-' + key).value;
    var preview = document.querySelector('.email-preview-' + key);

    var processedBody = body
        .replace(/\{name\}/g, 'John Smith')
        .replace(/\{reference\}/g, 'R26C170001')
        .replace(/\{site_name\}/g, companyName)
        .replace(/\{download_link\}/g, '<a href="#">Download Report</a>');

    var lines = processedBody.split('\n').map(function(l){ return '<p style="margin:0 0 10px">' + l + '</p>'; }).join('');

    preview.innerHTML =
        '<div style="background:' + brandColor + ';padding:16px 20px;color:#fff;font-weight:600;">' + companyName + '</div>' +
        '<div style="padding:16px 20px;background:#f9f9f9;border-bottom:1px solid #eee;font-size:12px;color:#666;"><strong>Subject:</strong> ' + subject + '</div>' +
        '<div style="padding:16px 20px;background:#fff;line-height:1.7;color:#333;">' + lines + '</div>' +
        '<div style="padding:12px 20px;background:#f5f5f5;font-size:11px;color:#999;border-top:1px solid #eee;">Sent by ' + companyName + '</div>';
}

// Init previews on load
document.addEventListener('DOMContentLoaded', function() {
    ['result_ready','review'].forEach(function(key){ updatePreview(key); });
});

// Tab switching
document.querySelectorAll('.nav-tab').forEach(function(tab) {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
        document.querySelectorAll('.repose-tab-panel').forEach(function(p){ p.style.display='none'; });
        this.classList.add('nav-tab-active');
        document.getElementById(this.dataset.tab).style.display = 'block';
    });
});

// Generate token
document.getElementById('btn-gen-token').addEventListener('click', function() {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var token = Array.from({length:40}, function(){ return chars[Math.floor(Math.random()*chars.length)]; }).join('');
    document.getElementById('lab_portal_token').value = token;
});

// Logo media picker
document.getElementById('btn-select-logo') && document.getElementById('btn-select-logo').addEventListener('click', function() {
    if (typeof wp === 'undefined' || !wp.media) { alert('Please use the Media Library URL field directly.'); return; }
    var frame = wp.media({ title: 'Select Logo', button: { text: 'Use this logo' }, multiple: false });
    frame.on('select', function() {
        var att = frame.state().get('selection').first().toJSON();
        document.getElementById('logo_url').value = att.url;
    });
    frame.open();
});

// Flush rewrite
document.getElementById('btn-flush-rewrite').addEventListener('click', function() {
    jQuery.post(reposeAdmin.ajaxUrl, { action:'repose_flush_rewrite', nonce:reposeAdmin.nonce }, function(r) {
        document.getElementById('flush-feedback').textContent = r.success ? 'Done! Permalinks refreshed.' : 'Failed.';
    });
});

// Save settings
jQuery(function($) {
    $('#repose-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#btn-save-settings');
        var originalText = $btn.val();
        var originalBg   = $btn.css('background-color');

        // Saving state
        $btn.val('Saving\u2026').prop('disabled', true).css({
            'background': '#888',
            'border-color': '#666',
            'cursor': 'wait'
        });

        var data = $(this).serializeArray();
        data.push({name:'action',value:'repose_save_settings'},{name:'nonce',value:reposeAdmin.nonce});

        $.post(reposeAdmin.ajaxUrl, data, function(r) {
            if (r.success) {
                // Success state — green with tick
                $btn.val('Saved \u2713').css({
                    'background': '#2e7d4f',
                    'border-color': '#1f5c39',
                    'cursor': 'default'
                });
                $('#settings-feedback').show().delay(3000).fadeOut();
                // Restore after 2.5 s
                setTimeout(function() {
                    $btn.val(originalText).prop('disabled', false).css({
                        'background': '',
                        'border-color': '',
                        'cursor': ''
                    });
                }, 2500);
            } else {
                // Error state — red
                $btn.val('Save Failed \u2717').css({
                    'background': '#c0392b',
                    'border-color': '#922b21',
                    'cursor': 'default'
                });
                setTimeout(function() {
                    $btn.val(originalText).prop('disabled', false).css({
                        'background': '',
                        'border-color': '',
                        'cursor': ''
                    });
                }, 3000);
            }
        }).fail(function() {
            $btn.val('Error \u2717').css({'background':'#c0392b','border-color':'#922b21'});
            setTimeout(function() {
                $btn.val(originalText).prop('disabled', false).css({'background':'','border-color':'','cursor':''});
            }, 3000);
        });
    });
});
</script>
