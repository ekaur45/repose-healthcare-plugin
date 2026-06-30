# Repose Healthcare WooCommerce Plugin

**Version:** 1.1.0  
**Developed by:** [iGen Solution](https://igensolution.com)  
**Plugin URI:** https://reposehealthcare.com

---

## Overview

The **Repose Healthcare WooCommerce Plugin** automates end-to-end order processing, laboratory integration, and diagnostic result reporting for Repose Healthcare home testing kits. It bridges the gap between a WooCommerce storefront and clinical laboratory workflows — handling everything from order validation and lab transmission to secure result delivery and patient notification.

---

## Requirements

| Requirement       | Minimum Version |
|-------------------|-----------------|
| WordPress         | 6.0+            |
| WooCommerce       | 7.0+            |
| PHP               | 8.0+            |
| MySQL / MariaDB   | 5.7+ / 10.3+    |

---

## Installation

1. Download the plugin ZIP file.
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**.
4. Click **Activate Plugin**.
5. On activation, the plugin automatically creates all required database tables and flushes rewrite rules.
6. Navigate to **Repose Healthcare → Settings** to configure lab transmission, branding, email templates, and API credentials.

---

## Key Features

### 1. Order Validation & Auto-Processing
- Validates all required patient and testing fields at checkout.
- On payment completion, orders are either **auto-transmitted** to the lab or placed in a **manual authorisation queue** (configurable).

### 2. Lab Transmission
- Generates a structured CSV payload for each order.
- Supports two transmission methods:
  - **Email Attachment** — CSV sent directly to the configured lab email address.
  - **Azure Blob Storage** — CSV uploaded to a configured Azure container via SAS token.

### 3. Manual Authorisation Queue (Auth Queue)
- Orders flagged for anomalies, duplicate samples, or when auto-transmit is disabled are held in the Auth Queue.
- Admin staff can review, approve, or reject orders before lab transmission.

### 4. HL7 ORU JSON Import (API)
- Labs can POST structured HL7 results in JSON format to the REST endpoint:
  ```
  POST /wp-json/repose/v1/results/json-import
  Header: X-Repose-Token: <your_api_token>
  ```
- Incoming data is parsed into structured DB tables covering patients, visits, orders, reports, observations, and notes.
- A branded PDF is auto-generated from the HL7 data and queued for admin review.

### 5. Lab Upload Portal
- A secure, token-protected URL (`/lab-portal/?token=XXXX`) allows labs without API access to upload result PDFs via a browser form.
- The portal token is managed in **Settings → Lab Portal**.

### 6. Results Management
- Admin staff can manually upload PDF results per order.
- Results have a review workflow: **Pending Review → Approved → Released to Patient**.
- PDFs are stored outside the web root with `.htaccess` protection.
- Patients access results via their **WooCommerce account → My Test Results**.

### 7. PDF Branding
- Approved result PDFs are stamped with the company logo, name, address, brand colour, and contact details before delivery to the patient.
- All branding settings are configurable in **Settings → Branding**.

### 8. Notifications
- Patients receive an automated email with a secure download link when their results are approved and released.
- Email subject and body templates are configurable in **Settings → Email Templates**.

### 9. Comment Library
- Pre-built comment templates (Normal Range, Borderline, Positive, Internal flags) can be attached to results.
- New templates can be created, edited, and scoped to patient-visible or internal-only visibility.

### 10. Audit Log
- Every significant action (transmission, upload, approval, rejection, download) is recorded with user, timestamp, and detail.
- Viewable in **Repose Healthcare → Transmission Log**.

### 11. Reference Number System
- Each order receives a unique, date-stamped reference number (e.g. `20240612-0001`) generated at transmission time.

---

## Admin Menu Sections

| Section            | Description                                         |
|--------------------|-----------------------------------------------------|
| Dashboard          | Overview of pending queues and recent activity      |
| Auth Queue         | Orders awaiting manual authorisation                |
| Results Queue      | Results awaiting upload or admin review             |
| Upload Result      | Manually upload a PDF result for an order           |
| Comment Library    | Manage reusable result comment templates            |
| Transmission Log   | Full audit trail of all plugin actions              |
| Settings           | All configuration options (lab, branding, API, etc) |

---

## Settings Reference

### Laboratory Tab
| Setting              | Description                                              |
|----------------------|----------------------------------------------------------|
| Auto-Transmit        | Send orders to lab automatically on payment              |
| Transmission Method  | Email or Azure Blob Storage                              |
| Lab Email            | Recipient email for CSV transmissions                    |
| Azure Account/Container/SAS | Azure Blob Storage credentials                  |

### Branding Tab
| Setting         | Description                            |
|-----------------|----------------------------------------|
| Company Name    | Appears on branded PDFs               |
| Company Address | Appears on branded PDFs               |
| Phone / Email / Website | Contact details on PDFs       |
| Logo URL        | Logo image for PDF reports            |
| Brand Colour    | Hex colour used in PDF headers        |

### API / Security Tab
| Setting         | Description                                          |
|-----------------|------------------------------------------------------|
| API Token       | Bearer token for the HL7 JSON import REST endpoint   |
| Lab Portal Token| Secret token for the browser-based lab upload portal |

---

## REST API Endpoints

| Method | Endpoint                              | Auth Header          | Description              |
|--------|---------------------------------------|----------------------|--------------------------|
| POST   | `/wp-json/repose/v1/results/json-import` | `X-Repose-Token`  | Import HL7 ORU JSON data |

---

## Database Tables Created

| Table                          | Purpose                                  |
|--------------------------------|------------------------------------------|
| `repose_reference_counters`    | Daily order reference number counters    |
| `repose_order_queue`           | Manual auth and results queues           |
| `repose_audit_log`             | Full action audit trail                  |
| `repose_results`               | Uploaded result files and status         |
| `repose_result_notes`          | Admin/staff notes on results             |
| `repose_comment_templates`     | Reusable comment templates               |
| `repose_hl7_messages`          | Raw HL7 JSON payloads                    |
| `repose_hl7_patients`          | Patient demographics (PID segment)       |
| `repose_hl7_visits`            | Visit data (PV1 segment)                 |
| `repose_hl7_orders`            | Lab orders (ORC segment)                 |
| `repose_hl7_reports`           | Test report headers (OBR segment)        |
| `repose_hl7_observations`      | Individual analyte results (OBX segment) |
| `repose_hl7_notes`             | Lab notes (NTE segment)                  |

---

## File Structure

```
repose-healthcare/
├── repose-healthcare.php           # Main plugin bootstrap file
├── README.md                       # This file
├── admin/
│   ├── class-repose-admin.php      # Admin menu and page routing
│   └── views/
│       ├── dashboard.php           # Dashboard overview
│       ├── auth-queue.php          # Manual authorisation queue
│       ├── results-queue.php       # Results review queue
│       ├── upload-result.php       # Manual result upload form
│       ├── comment-library.php     # Comment template manager
│       ├── transmission-log.php    # Audit log viewer
│       └── settings.php            # Settings page (all tabs)
├── includes/
│   ├── class-repose-db.php              # Database install/upgrade
│   ├── class-repose-reference.php       # Reference number generator
│   ├── class-repose-order-validator.php # Order field validation & anomaly detection
│   ├── class-repose-lab-transmission.php # CSV generation & lab dispatch
│   ├── class-repose-results-manager.php  # Result upload, review, download
│   ├── class-repose-pdf-brander.php      # PDF branding/stamping
│   ├── class-repose-notifications.php    # Email notifications
│   ├── class-repose-audit-log.php        # Audit log recording
│   ├── class-repose-comment-library.php  # Comment template CRUD
│   ├── class-repose-store-api.php        # WooCommerce Store API hooks
│   ├── class-repose-lab-portal.php       # Browser-based lab upload portal
│   └── class-repose-json-import.php      # HL7 JSON REST API import
├── assets/
│   ├── css/admin.css               # Admin styles
│   └── js/
│       ├── admin.js                # Admin JS (settings save, tabs, etc)
│       └── checkout-fields.js      # Checkout field enhancements
└── templates/
    └── customer-results.php        # Patient-facing results page template
```

---

## Support

For technical support, customisation, or integration queries, contact the development team:

**iGen Solution**  
Website: [https://igensolution.com](https://igensolution.com)

---

*This plugin was developed by iGen Solution for Repose Healthcare. All rights reserved.*
