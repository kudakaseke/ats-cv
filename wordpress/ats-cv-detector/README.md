# ATS CV Detector for WordPress — 1.0.0

An independent WordPress plugin for private CV editing, PDF/DOCX import and export, basic structure checking, OpenAI job assessment and reviewed revamping, atomic AI credits, Paynow Zimbabwe checkout, paid access periods, receipts and billing administration. It does not require the Laravel application or WooCommerce.

## Install

1. Use a separate staging WordPress site first. PHP 8.2+ and InnoDB for both core users and plugin tables are required; use currently supported production PHP/WordPress/database versions. OpenSSL, mbstring, DOM/XML, Phar, zlib and the bundled document dependencies are needed. PDF/Word export does not require Node or the PHP ZIP extension.
2. In WordPress open **Plugins → Add New Plugin → Upload Plugin**, select `ats-cv-detector-1.0.0.zip`, install and activate. The ZIP is approximately 4.4 MB; the host's upload/post limits must allow it, or upload its extracted directory through the host's file manager. Start with a single-site installation; network-wide WordPress Multisite deployment has not been validated.
3. Activation adds private application tables, administrator billing capabilities, a scheduled maintenance hook, and a published **ATS CV Detector** page containing `[ats_cv_detector]`. Add that page to navigation. The aliases `[ats_dashboard]`, `[ats_checker]`, `[ats_builder]`, `[ats_subscription]` and `[ats_payment_result]` display the same complete workspace.
4. Enable registration in **Settings → General → Membership** if customers should sign up themselves. Ordinary customer accounts are Subscribers. Registration sends a verification email; existing WordPress customers must also verify through the workspace. Administrators are trusted to use the workspace without this extra step.
5. Configure a real mail transport. Verify signup, password reset and billing mail delivery using a test mailbox. The plugin uses WordPress mail and does not assume a specific SMTP plugin.
6. Configure provider secrets server-side as below. No real provider credentials are embedded in this distribution.
7. Configure a real cron runner, exclude private pages from caching, complete the merchant test checklist and only then approve live payment collection.

Deactivation and uninstall do not erase financial data or CVs. Deactivation removes plugin schedules. Back up the database and authentication salts before updates; changing WordPress salts makes existing encrypted CVs, AI history and Paynow URLs unreadable unless they are migrated first.

## Protected server configuration

Add constants to `wp-config.php` **before** the line loading `wp-settings.php`, or supply the same names through your hosting environment. WordPress does not automatically load the Laravel `.env`.

```php
define('ATSCV_PAYNOW_ENABLED', true);
define('ATSCV_PAYNOW_MODE', 'test');
define('ATSCV_PAYNOW_ID', 'YOUR_INTEGRATION_ID');
define('ATSCV_PAYNOW_KEY', 'YOUR_INTEGRATION_KEY');
define('ATSCV_PAYNOW_TEST_EMAIL', 'your-merchant-test-email@example.com');
define('ATSCV_PAYNOW_LIVE_APPROVED', false);

define('ATSCV_OPENAI_KEY', 'YOUR_SERVER_SIDE_API_KEY');
define('ATSCV_OPENAI_MODEL', 'gpt-4.1');
define('ATSCV_BUSINESS_NAME', 'ATS CV Detector');
define('ATSCV_BUSINESS_ADDRESS', 'Your business address');
```

Do not put credentials in a shortcode, page builder, JavaScript, screenshot, exported settings file or public Git commit. Preserve the current configured model unless a model change is separately evaluated. Provider access, model availability and billing must be checked in the actual account.

Paynow's merchant account must actually be configured for test transactions; the local `test` label cannot convert a live merchant integration into a sandbox. Confirm USD collection/settlement and the channels enabled for your merchant. Checkout is hosted by Paynow; the plugin does not collect cards or implement automatic tokenized deductions or express mobile-money initiation.

The site must have the correct **public HTTPS WordPress URL**. Private LAN addresses and an HTTP site cannot provide a production Paynow flow. Initialization supplies these per-transaction URLs automatically:

- Return: the workspace page with `?atscv_payment=<merchant-reference>`.
- Result: `/wp-json/atscv/v1/paynow/result/<merchant-reference>` (or the site's configured REST URL equivalent).

Allow the result POST through WAF/CDN/security rules without a WordPress login or REST nonce. Signature verification authenticates that endpoint; all customer REST actions require the owning signed-in, verified user and a WordPress REST nonce.

Existing payments retain their Integration ID and mode. They must be reconciled with the original configuration. Version 1.0 does not provide a multi-key historical credential vault; resolve outstanding payments before rotating merchant credentials, or temporarily restore their original server configuration for reconciliation.

Live collection requires the owner's approval, actual live merchant configuration, public callback tests and `ATSCV_PAYNOW_MODE='live'` plus `ATSCV_PAYNOW_LIVE_APPROVED=true`. Do not enable those just to make a test payment allocate live credits. Test-paid transactions deliberately grant no live allowance or subscription.

## Product rules

| Plan | Charge | Credits | Access |
| --- | --- | --- | --- |
| Free | USD 0 | 15 welcome credits once | One saved CV; seven free template styles |
| Monthly | USD 4.75 | 150 per paid period | One calendar month |
| Pro | USD 6.50 | 225 per paid period | One calendar month |
| Annual | USD 47.40 | 2,400 for the paid year | One calendar year |

Job analysis reserves 5 credits; revamp reserves 10. Basic checking, manual editing and PDF/DOCX export use no AI credits. The 150-credit top-up costs USD 4.75, does not extend a subscription and persists after period expiry. Monthly/yearly allowance lasts its funded period. Failed AI operations restore only eligible reserved credits. A consumed top-up refund can create debt, which reduces available funds.

All plan changes and renewals start after existing paid time, without proration or automatic debits. One future prepaid period is permitted through checkout. New-period credits are not available early. Customers can cancel renewal reminders while preserving purchased time. After expiry access falls back to Free, but saved CVs remain editable under their existing saved template.

## CV tools

The builder provides structured contact/summary/skills, experience, education, certifications, achievements, languages, references and custom sections, autosave/revision conflict checks, section headings/order, preview, saved documents and PDF/Word downloads. The current template catalogue and department metadata are included as style presets.

This native renderer uses text-first layouts, template font/accent/heading/order settings, and editable single-column Word output. It does **not** promise pixel-identical reproduction of the Laravel application's elaborate original layouts. Browser preview and PDF/DOCX typography can differ. Validate the export styles you intend to advertise before launch.

PDF and DOCX imports up to 5 MB preserve extracted readable text in the editable Imported CV text field. They do not reconstruct the original structured layout. Clear that field to switch to the structured form. Uploaded originals are processed using PHP's temporary upload file and are not retained. OCR, image-only PDFs and encrypted PDFs are not supported in this release. A readable PDF is required.

AI analysis uses a native versioned evidence-requirement method: education 25, certification 10, skills 25, competencies 15, experience 15 and responsibilities 10, normalized across evidenced job categories. Matching evidence must quote the source CV and job text. It is not the Laravel application's exact scoring algorithm or an employer ATS score. The result is guidance rather than a hiring guarantee.

Revamp preserves structured identity, employment titles/companies/dates and education; it proposes summary and experience wording edits. Imported text is proposed as a reviewed text revision. New numeric claims are rejected when not supported by the original text. This does not prove every AI-written sentence is factual: the user must review the before/after result and confirm facts before applying. No AI result automatically overwrites a CV.

CV/operation contents, receipt snapshots and provider URLs are encrypted at rest using a key derived from WordPress authentication salts. Financial state and ledger metadata remain queryable for accounting. The application is not a substitute for an approved privacy/retention policy.

## Jobs and scheduling

The plugin uses durable operation/outbox tables and native WordPress scheduled hooks, with a five-minute maintenance job and individual AI-job hooks. A real scheduler is required on low-traffic sites. On suitable hosting, run WP-CLI's `wp cron event run --due-now --path=/actual/wordpress/path` once per minute under the site user, using a non-overlap lock and logs. Set `DISABLE_WP_CRON=true` only once the replacement runner works. Check the **Last maintenance heartbeat** in ATS CV Detector administration.

AI HTTP requests are bounded at 120 seconds. PHP/worker hosting must support that runtime; use a proper cron/CLI process rather than tying processing to browser requests. Maintenance processes bounded batches, handles stale fenced jobs, reconciles pending and recent verified payments with backoff, marks expired periods and delivers the mail outbox. Email retries are bounded to five attempts. SMTP acceptance cannot guarantee exactly-once delivery across every crash; financial fulfillment remains independently idempotent.

Completed/failed AI input snapshots are cleared after 30 days by maintenance; saved CVs and result history remain until removed through the customer/privacy workflow. Private workspace, downloads and `/atscv/v1/` authenticated responses must bypass full-page/shared CDN caching. Do not optimize away REST nonce handling.

## Billing security and states

Prices come only from server plans. Signed initialization validates provider redirects before saving them. Return URLs are not payment proof. Notification reference/amount/provider ID must match and direct polling is performed before granting benefits. Poll URLs must be HTTPS on Paynow's allowed hosts with no credentials, unsafe port or redirect following. Persisted merchant traces support lost-response recovery.

Created/Sent/unknown outcomes remain pending. Paid/Awaiting Delivery/Delivered are eligible under the documented digital-access policy after direct verification; the latter two remain paid-but-held provider states. Cancelled does not fulfill. Refunded/Disputed reverses the associated paid period or top-up under the credit policy. A timeout alone never proves failure. Duplicate or concurrent notifications cannot create duplicate periods/receipts or overspend wallets.

If the same item already has a pending checkout, the customer resumes/checks it; choosing a different plan does not silently send them to the old plan. Do not delete pending payment records just to remove a message. An uncertain payment may need merchant support before a fresh charge is safe.

The admin menu is restricted to `atscv_manage_billing`, initially granted to Administrators. It exposes safe payment inspection, status totals, provider reconciliation, refund/dispute review notes and CSV export. A review note never fabricates a refund or moves money. Actual refunds use the merchant's supported channel, then provider status is reconciled. There is no invented automated refund endpoint.

Receipts are authenticated, owner-only downloadable HTML documents for verified live payments, with snapshot details and the current provider state. They are payment receipts, not tax invoices. Configure applicable business/tax requirements separately before claiming tax-invoice support.

## Privacy and account management

Use WordPress's personal-data export/erase tools for the plugin's private CV and AI records. Unfinished operations are fenced/cancelled and eligible reservations restored before private data is erased. Wallet/ledger/financial history is retained under the site's financial retention policy, so privacy erasure cannot grant welcome credits again. Account deletion from History requires password reauthentication and explicit DELETE confirmation. Customers with pending payments must resolve them first; administrators manage administrator accounts through WordPress.

Publish appropriate privacy, terms, refund and AI-processing notices. The plugin collects consent for AI processing and one-time payment, but does not supply jurisdiction-specific legal documents. Validate the notices, provider retention settings, mail security, backups and any external analytics before launch. No card numbers/CVV are stored.

## Build and test

The release ZIP includes Composer dependencies; end users do not need Composer. Developers can run:

```text
composer install --no-dev --prefer-dist
```

from the plugin directory, then use the repository's `wordpress/build.php` packaging script. Dependencies include Dompdf and Smalot PDFParser; their licenses are distributed in `vendor/`. Do not publish `vendor/` without the corresponding license files.

Repository tests create a new isolated WordPress database and site, never overwrite an existing installation, block/mock provider requests and use non-production credentials. See `wordpress/tests/setup.ps1`, `integration.php`, `concurrent-worker.php` and `browser.mjs`. They exercise real WordPress activation/REST/database behaviour, PDF/DOCX generation and extraction, concurrent wallet/notification processing, and browser editing/import/downloads.

No live merchant or AI request is required for automated tests. Before production, perform a real Paynow merchant test checkout on the deployed public HTTPS hostname, test mail and scheduled execution, verify the enabled merchant channels and USD settlement, and check all exported styles with your own documents. A fake API response cannot prove external account configuration.

## Release scope

This is a new native WordPress application, not an automatic migration of existing Laravel users, password hashes, encrypted files, paid balances or in-flight payments. Use the separate WordPress implementation guide to plan a controlled migration. No Laravel data is imported on activation, no WordPress administrators are silently promoted from customer inputs, and no production payment mode is enabled by default.

Optional social login, OCR, express mobile-money requests, automatic recurring tokenization, jurisdiction-specific tax invoices and pixel-perfect Laravel template/scoring parity are not included in version 1.0. They must not be advertised as enabled by this ZIP.
