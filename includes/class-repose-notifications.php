<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Repose_Notifications {

    public static function init() {
        // Nothing to hook for now — methods called directly
    }

    public static function notify_result_ready( int $order_id, int $result_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $to           = $order->get_billing_email();
        $name         = $order->get_billing_first_name();
        $company_name = get_option( 'repose_company_name', 'Repose Healthcare Ltd' );
        $brand_color  = get_option( 'repose_brand_color', '#1a6e8c' );
        $logo_url     = get_option( 'repose_logo_url', '' );
        $company_website = get_option( 'repose_company_website', '' );
        $company_email   = get_option( 'repose_company_email', '' );
        $company_phone   = get_option( 'repose_company_phone', '' );

        // Dashboard login URL — patient must log in to view their result
        $dashboard_url = wc_get_account_endpoint_url( 'test-results' );

        $subject = get_option( 'repose_result_email_subject', 'Your Test Results Are Ready' );
        $raw_body = get_option( 'repose_result_email_body',
            "Dear {name},\n\nYour test results are now available. Please log in to your patient dashboard to view and download your report securely.\n\nKind regards,\n{site_name}" );

        // Resolve placeholders (no direct download URL)
        $raw_body = str_replace(
            array( '{name}', '{site_name}', '{reference}', '{download_link}' ),
            array(
                esc_html( $name ),
                esc_html( $company_name ),
                esc_html( $order->get_meta( '_repose_reference_number' ) ),
                esc_url( $dashboard_url ),   // download_link → dashboard, not a raw file URL
            ),
            $raw_body
        );

        // Notes are intentionally NOT included in the email for data protection.
        // They are only visible to the patient in their secure online dashboard.
        $notes_html = '';

        // Branded HTML email
        $logo_html = $logo_url
            ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $company_name ) . '" style="max-height:50px;max-width:180px;">'
            : '<span style="font-size:20px;font-weight:700;color:#fff;">' . esc_html( $company_name ) . '</span>';

        $body_paragraphs = '';
        foreach ( explode( "\n", $raw_body ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $body_paragraphs .= '<p style="margin:0 0 14px;color:#374151;font-size:15px;line-height:1.6;">' . esc_html( $line ) . '</p>';
            }
        }

        $footer_parts = array_filter( array(
            $company_name,
            $company_email ? '<a href="mailto:' . esc_attr( $company_email ) . '" style="color:' . esc_attr( $brand_color ) . ';">' . esc_html( $company_email ) . '</a>' : '',
            $company_phone ? esc_html( $company_phone ) : '',
            $company_website ? '<a href="' . esc_url( 'https://' . ltrim( $company_website, 'https://' ) ) . '" style="color:' . esc_attr( $brand_color ) . ';">' . esc_html( $company_website ) . '</a>' : '',
        ) );

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">

      <!-- Header -->
      <tr>
        <td style="background:' . esc_attr( $brand_color ) . ';padding:28px 32px;text-align:center;">
          ' . $logo_html . '
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 32px 24px;">
          ' . $body_paragraphs . '
          ' . $notes_html . '
          <div style="text-align:center;margin:28px 0 8px;">
            <a href="' . esc_url( $dashboard_url ) . '"
               style="display:inline-block;padding:14px 32px;background:' . esc_attr( $brand_color ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;font-weight:700;letter-spacing:0.3px;">
              View My Results
            </a>
          </div>
          <p style="text-align:center;font-size:12px;color:#9ca3af;margin:16px 0 0;">
            You will need to log in to your patient account to access your results securely.
          </p>
        </td>
      </tr>

      <!-- Divider -->
      <tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:0;"></td></tr>

      <!-- Footer -->
      <tr>
        <td style="padding:20px 32px 28px;text-align:center;">
          <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.8;">
            Sent by ' . implode( ' &nbsp;|&nbsp; ', $footer_parts ) . '
          </p>
          <p style="margin:8px 0 0;font-size:11px;color:#d1d5db;">
            This email was sent to ' . esc_html( $to ) . ' because you have a test result ready for review.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body></html>';

        // Send as HTML
        add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
        wp_mail( $to, $subject, $html );
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );

        // Fix 4: Update order status from on-hold → completed when result is released
        $order->update_status( 'completed', __( 'Repose: Test result approved and released to patient.', 'repose-healthcare' ) );
    }

    // -----------------------------------------------------------------------
    // ORR-HL7: Laboratory receipt confirmation email to customer
    // -----------------------------------------------------------------------

    public static function notify_sample_received( int $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $to           = $order->get_billing_email();
        $name         = $order->get_billing_first_name();
        $company_name = get_option( 'repose_company_name', 'Repose Healthcare Ltd' );
        $brand_color  = get_option( 'repose_brand_color', '#1a6e8c' );
        $logo_url     = get_option( 'repose_logo_url', '' );
        $company_website = get_option( 'repose_company_website', '' );
        $company_email   = get_option( 'repose_company_email', 'info@reposehealthcare.co.uk' );
        $company_phone   = get_option( 'repose_company_phone', '0330 133 4245' );

        $subject = get_option( 'repose_sample_received_email_subject', 'Your Sample Has Been Received' );
        $raw_body = get_option( 'repose_sample_received_email_body',
            "Dear {name},\n\nWe're writing to confirm that your sample has been safely received by our laboratory.\n\nOur team has now begun processing your test. For most tests, results are available within 3 working days. However, some specialist tests may take slightly longer. If you have not received your results after 72 hours, please feel free to contact us and we will be happy to provide an update on your test status.\n\nAs soon as your results are ready, you will be notified by email and will be able to access them securely via your online account.\n\nIf you have any questions in the meantime, please don't hesitate to contact our support team at {support_email} or {support_phone}.\n\nThank you for choosing {site_name}.\n\nKind regards,\nThe {site_name} Team" );

        $raw_body = str_replace(
            array( '{name}', '{site_name}', '{reference}', '{support_email}', '{support_phone}' ),
            array(
                esc_html( $name ),
                esc_html( $company_name ),
                esc_html( $order->get_meta( '_repose_reference_number' ) ),
                esc_html( $company_email ),
                esc_html( $company_phone ),
            ),
            $raw_body
        );

        $logo_html = $logo_url
            ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $company_name ) . '" style="max-height:50px;max-width:180px;">'
            : '<span style="font-size:20px;font-weight:700;color:#fff;">' . esc_html( $company_name ) . '</span>';

        $body_paragraphs = '';
        foreach ( explode( "\n", $raw_body ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $body_paragraphs .= '<p style="margin:0 0 14px;color:#374151;font-size:15px;line-height:1.6;">' . esc_html( $line ) . '</p>';
            }
        }

        $footer_parts = array_filter( array(
            $company_name,
            $company_email ? '<a href="mailto:' . esc_attr( $company_email ) . '" style="color:' . esc_attr( $brand_color ) . ';">' . esc_html( $company_email ) . '</a>' : '',
            $company_phone ? esc_html( $company_phone ) : '',
            $company_website ? '<a href="' . esc_url( 'https://' . ltrim( $company_website, 'https://' ) ) . '" style="color:' . esc_attr( $brand_color ) . ';">' . esc_html( $company_website ) . '</a>' : '',
        ) );

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
      <tr>
        <td style="background:' . esc_attr( $brand_color ) . ';padding:28px 32px;text-align:center;">
          ' . $logo_html . '
        </td>
      </tr>
      <tr>
        <td style="padding:36px 32px 24px;">
          ' . $body_paragraphs . '
        </td>
      </tr>
      <tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:0;"></td></tr>
      <tr>
        <td style="padding:20px 32px 28px;text-align:center;">
          <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.8;">
            Sent by ' . implode( ' &nbsp;|&nbsp; ', $footer_parts ) . '
          </p>
          <p style="margin:8px 0 0;font-size:11px;color:#d1d5db;">
            This email was sent to ' . esc_html( $to ) . ' regarding your recent order.
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

        add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
        wp_mail( $to, $subject, $html );
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
    }

    // -----------------------------------------------------------------------
    // Tracking Number Assignment: dispatch / follow-up email to customer
    // -----------------------------------------------------------------------

    public static function notify_dispatch( int $order_id, string $tracking_number ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $to              = $order->get_billing_email();
        $name            = $order->get_billing_first_name();
        $company_name    = get_option( 'repose_company_name', 'Repose Healthcare Ltd' );
        $brand_color     = get_option( 'repose_brand_color', '#1a6e8c' );
        $logo_url        = get_option( 'repose_logo_url', '' );
        $company_website = get_option( 'repose_company_website', 'reposehealthcare.co.uk' );
        $company_email   = get_option( 'repose_company_email', 'info@reposehealthcare.co.uk' );
        $company_phone   = get_option( 'repose_company_phone', '0330 133 4245' );
        $company_address = get_option( 'repose_company_address', "1 Skylark Way, Sawston\nCambridgeshire, CB22 3GL\nUnited Kingdom" );
        $company_mobile  = get_option( 'repose_company_mobile', '07307 534441' );

        $royal_mail_track_url = 'https://www.royalmail.com/track-your-item#/tracking-results/' . rawurlencode( $tracking_number );

        $subject = get_option( 'repose_dispatch_email_subject', 'Your Order Has Been Dispatched' );
        $raw_body = get_option( 'repose_dispatch_email_body',
            "Dear {name},\n\nWe're pleased to let you know that your order has been dispatched today and is on its way to you.\n\nDelivery is usually completed the next working day; however, please allow up to three working days for delivery within the UK.\n\nOnce your kit arrives, please follow the steps below carefully when collecting and returning your sample(s):\n\n1. Label your sample clearly\nEach sample tube or container must be labelled with: Full name, Date of birth, Date and time of collection, Any other information requested. These details must match the laboratory request form included in your kit. Unlabelled or incorrectly labelled samples will be rejected, and a £15 fee will apply for a replacement kit or order cancellation.\n\n2. MRSA swabs — additional requirement\nIf your test includes MRSA swabs, please clearly write the collection site on each sample tube (e.g. Nose, Groin, or Axilla). This is a laboratory requirement. Failure to include this information will result in sample rejection and a £15 fee.\n\n3. Package your sample securely\nPlease ensure all samples are sealed and packaged according to the kit instructions.\n\n4. Include the laboratory request form\nPlease enclose the completed laboratory request form provided in your kit. Missing forms may cause testing delays or sample rejection. If the sample is rejected, a £15 fee will apply for a replacement kit or order cancellation.\n\n5. Return your sample\nSend everything back to the laboratory using the prepaid return envelope provided.\n\nIMPORTANT — Timing Your Return: If you are unable to return your sample by midday on Thursday, please wait until Monday of the following week before collecting your sample. This helps avoid weekend delays, which can affect sample stability and may lead to rejection.\n\nThank you for choosing {site_name}. If you have any questions or need support at any stage, please don't hesitate to contact us.\n\nKind regards,\n{site_name}" );

        $raw_body = str_replace(
            array( '{name}', '{site_name}', '{reference}', '{tracking_number}' ),
            array(
                esc_html( $name ),
                esc_html( $company_name ),
                esc_html( $order->get_meta( '_repose_reference_number' ) ),
                esc_html( $tracking_number ),
            ),
            $raw_body
        );

        $logo_html = $logo_url
            ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $company_name ) . '" style="max-height:50px;max-width:180px;">'
            : '<span style="font-size:20px;font-weight:700;color:#fff;">' . esc_html( $company_name ) . '</span>';

        $body_paragraphs = '';
        foreach ( explode( "\n", $raw_body ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $body_paragraphs .= '<p style="margin:0 0 14px;color:#374151;font-size:15px;line-height:1.6;">' . esc_html( $line ) . '</p>';
            }
        }

        // Tracking number highlight block
        $tracking_block = '
          <div style="margin:24px 0;padding:18px 20px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:8px;text-align:center;">
            <p style="margin:0 0 6px;font-size:13px;color:#374151;font-weight:600;">Your Royal Mail Tracking Number</p>
            <p style="margin:0 0 12px;font-size:22px;font-weight:700;color:' . esc_attr( $brand_color ) . ';letter-spacing:1px;">' . esc_html( $tracking_number ) . '</p>
            <a href="' . esc_url( $royal_mail_track_url ) . '"
               style="display:inline-block;padding:10px 24px;background:' . esc_attr( $brand_color ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:700;">
              Track Your Parcel
            </a>
          </div>';

        $footer_contact = array_filter( array(
            $company_phone  ? 'T: ' . esc_html( $company_phone )  : '',
            $company_mobile ? 'M: ' . esc_html( $company_mobile ) : '',
        ) );
        $footer_links = array_filter( array(
            $company_email   ? '<a href="mailto:' . esc_attr( $company_email ) . '" style="color:' . esc_attr( $brand_color ) . ';">' . esc_html( $company_email ) . '</a>' : '',
            '<a href="https://reposehealthcare.co.uk" style="color:' . esc_attr( $brand_color ) . ';">reposehealthcare.co.uk</a>',
            '<a href="https://mrsatest.co.uk" style="color:' . esc_attr( $brand_color ) . ';">mrsatest.co.uk</a>',
        ) );
        $footer_address_lines = array_map( 'trim', explode( "\n", $company_address ) );
        $footer_address_html  = implode( '<br>', array_map( 'esc_html', array_filter( $footer_address_lines ) ) );

        $html = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
      <tr>
        <td style="background:' . esc_attr( $brand_color ) . ';padding:28px 32px;text-align:center;">
          ' . $logo_html . '
        </td>
      </tr>
      <tr>
        <td style="padding:36px 32px 8px;">
          ' . $tracking_block . '
          ' . $body_paragraphs . '
        </td>
      </tr>
      <tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #e5e7eb;margin:0;"></td></tr>
      <tr>
        <td style="padding:20px 32px 28px;text-align:center;">
          <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#374151;">' . esc_html( $company_name ) . '</p>
          <p style="margin:0 0 6px;font-size:12px;color:#6b7280;line-height:1.7;">' . $footer_address_html . '</p>
          <p style="margin:0 0 6px;font-size:12px;color:#6b7280;">' . implode( ' &nbsp;|&nbsp; ', array_filter( $footer_contact ) ) . '</p>
          <p style="margin:0 0 8px;font-size:12px;">' . implode( ' &nbsp;|&nbsp; ', array_filter( $footer_links ) ) . '</p>
          <p style="margin:0;font-size:11px;color:#d1d5db;">Registered in England &amp; Wales No: 16179671</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';

        add_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
        wp_mail( $to, $subject, $html );
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'set_html_content_type' ) );
    }

    public static function set_html_content_type() {
        return 'text/html';
    }
}
