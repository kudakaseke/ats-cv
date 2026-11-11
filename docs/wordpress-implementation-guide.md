# ATS CV Detector: complete WordPress implementation guide

Prepared 2 November 2026 from the existing ATS CV Detector project and the official documentation linked below. This is an implementation specification and delivery checklist, not an installable plugin. Suggested plugin names, shortcodes, capabilities and endpoints below must be built; they are not WordPress features supplied automatically.

## 1. Decide what “in WordPress” means

There are two valid architectures:

| Approach | What runs in WordPress | What happens to Laravel |
| --- | --- | --- |
| Full WordPress application | Public pages, accounts, CV tools, plans, credit ledger, Paynow and administration, through a custom plugin | Its business logic and tests are ported; existing app remains available during migration |
| WordPress public site with existing application | Marketing/content pages; links or an authenticated integration to the app | Laravel remains the application backend and source of truth |

This guide specifies the **full WordPress application**, because you asked to recreate the system in WordPress. The second option avoids rewriting working application logic, but it is not a full WordPress conversion. Do not accidentally run two independent billing/credit systems for the same purchase.

A theme/page builder alone cannot recreate this application. Standard plugins can supply infrastructure, but the tailored CV editor, evidence-based assessment, document exports, paid periods and atomic credits require custom development. Do not promise a no-code build with feature parity.

Use one custom plugin, provisionally `ats-cv-detector`, as the owner of business logic. Use a theme for appearance and public-page layouts. Keep application logic out of a theme's `functions.php` so changing the theme cannot disable payments or credit accounting.

## 2. Preserve the existing product rules

Use these existing settings as the initial specification:

| Plan | Actual charge | Paid duration | Credit allowance | Saved CV limit |
| --- | --- | --- | --- | --- |
| Free | USD 0 | No paid period | 15 welcome credits once | 1 |
| Monthly | USD 4.75 | One calendar month | 150 per paid period | Unlimited under current product policy |
| Pro | USD 6.50 | One calendar month | 225 per paid period | Unlimited under current product policy |
| Annual | USD 47.40 | One calendar year | 2,400 upfront for that paid year | Unlimited under current product policy |

Annual may be advertised as USD 3.95/month equivalent, but checkout must clearly show the full USD 47.40 yearly charge.

Current operation costs:

- Job-to-CV ATS Detector analysis: 5 credits.
- AI CV revamp: 10 credits.
- One analysis plus one revamp: 15 credits.
- Manual editing and downloads: no AI credits.
- Basic checker without a job advertisement: preserve the existing free/uncharged behaviour rather than treating it as the paid job analysis.
- Existing top-up: 150 credits for USD 4.75; a one-time purchase, not a subscription renewal.

Free template IDs are `double-column`, `elegant`, `graduate`, `essential`, `crest`, `student` and `portfolio`. Import all other current template definitions and departmental variants from `config/cv_templates.php`; do not rebuild only a small sample and claim full parity. Retain the existing policy allowing already-saved CVs to remain editable after paid access expires.

Store plan prices in integer cents. Calculate amounts and credit costs on the server. Store versioned plan/price/credit snapshots on purchases, paid periods and AI operations so later settings changes cannot rewrite historical promises.

Monthly allowances expire/reset at the paid-period boundary. Annual credits last for their paid year. Purchased top-ups persist. Welcome credits are granted once, not every login or free-plan renewal. Preserve existing refund/debt and failed-operation restoration policies. Require explicit approval for changes to these commercial rules.

## 3. Inventory the Laravel application before development

Create a feature-parity checklist from the current repository, including the actual templates, example CV content, upload/import rules, builder steps and scoring fixtures.

Use these files as porting references:

| WordPress component | Existing reference |
| --- | --- |
| Plans and saved-CV access | `app/Services/PlanService.php`, `config/cv_access.php` |
| Credit accounting and reservations | `app/Services/AiCreditService.php`, `config/ai_credits.php` |
| Paynow API | `app/Services/Billing/PaynowGateway.php` |
| Financial fulfillment and periods | `app/Services/Billing/PaymentService.php` |
| Receipts and email outbox | `app/Services/Billing/BillingRecords.php` |
| Maintenance and recovery | `app/Console/Commands/MaintainBilling.php`, `ReconcilePayments.php`, `MaintainAiCredits.php` |
| Import/extraction | `CvTextParser.php`, `CvPdfExtractor.php`, `CvStructuredResume.php` |
| Analysis and scoring | `CvAiAssessment.php`, `CvCompatibilityScore.php`, `CvExperienceMatcher.php`, `CvJobRequirements.php`, `CvKeywordMatcher.php`, `CvIndustryRelevance.php` |
| Revamp and before/after review | `CvAiRewriter.php`, `CvReviewComparison.php`, `CvRevampReviewRepair.php` |
| PDF/Word output | `CvDocumentExporter.php`, `CvWordWriter.php`, `scripts/export-cv.mjs` |
| User interface | `resources/views/builder.blade.php`, workspace views, existing JS/CSS |
| Regression fixtures | `tests/Feature`, `tests/Unit`, browser and export scripts |

Laravel controllers, Eloquent models, facades, middleware, Blade directives and `artisan` commands do not run simply by being copied into a WordPress plugin. Port their behaviour into WordPress controllers/repositories/services. Framework-independent algorithms and frontend assets may be reusable after dependency and license review.

## 4. Hosting, environments and installation

Use separate local, staging and production installations. Begin in a new folder or staging domain; do not replace the working Laravel application during development.

For a new installation, meet the current WordPress recommendations: PHP 8.3+, MariaDB 10.11+ or MySQL 8.0+, and HTTPS. Also verify the selected PHP branch remains security-supported at deployment. Use Apache or Nginx with correct rewrite rules. See [WordPress requirements](https://wordpress.org/about/requirements/).

The application additionally needs:

- InnoDB tables for transactions and row locks.
- PHP extensions needed by WordPress and selected libraries, including cURL, mbstring, DOM/XML, ZIP and fileinfo where required.
- Composer during build/deployment, and Node.js during frontend build.
- Private file storage, backup storage, sufficient disk space and cleanup jobs.
- Outbound HTTPS access to Paynow and the chosen AI provider.
- A public HTTPS origin that Paynow can reach.
- SMTP or a transactional email API with domain authentication configured by the mail provider.
- Real scheduling and background-job execution.
- A Node/headless-browser worker or authenticated document service if preserving current browser-rendered PDF fidelity.

Ordinary hosting can serve the public site but may restrict processes, job duration or document rendering. Confirm these capabilities before paying for a plan. If using WordPress.com or another managed platform, confirm custom plugin upload and required background/document execution are actually allowed on the selected plan; do not assume all hosting products support them.

Install WordPress from the official distribution. Configure the site URL, admin email, HTTPS, a supported theme and readable permalinks. Store timestamps in UTC and format user-facing dates in the intended timezone. Do not use floating local times for subscription expiry.

Keep staging out of search indexes and protected from unauthorized access. Allow only the required provider test callback path through staging protection. Disable real customer mail and use merchant test credentials there. A password-protected callback endpoint will reject Paynow.

## 5. Keep the plugin stack small

Recommended baseline:

| Component | Choice |
| --- | --- |
| Public design | A maintained WordPress block theme; optional child theme/custom styles |
| Application | Custom ATS CV Detector plugin |
| Background jobs | Action Scheduler, integrated as a declared dependency or correctly bundled library |
| Mail | One maintained SMTP/API integration, or a custom mail adapter |
| Backups/security/cache | Host facilities or a small set of maintained tools chosen after testing |
| PDF | Existing browser-rendering approach or a tested document worker |
| Word export | Port existing writer, or evaluate PHPWord against all template fixtures |

WooCommerce is **optional** for this design, not a requirement. Paynow maintains a [WooCommerce integration repository](https://github.com/paynow/Paynow-for-WooCommerce), but that does not establish compatibility with every current WordPress/WooCommerce/PHP version, checkout mode, storage mode, refunds or recurring-payment feature. Audit and test those before adopting it.

If choosing WooCommerce, use its order API/CRUD rather than direct assumptions about order tables; define the exact verified-payment event which invokes the custom entitlement service. The custom plugin still owns credits, CV access and paid periods. Do not let both WooCommerce hooks and a separate callback allocate benefits independently. Do not install several membership/points/subscription plugins that each believe they own the same balance.

The remaining steps use direct Paynow integration in the custom plugin to match the existing application.

## 6. Pages and navigation

Build public pages for Home, Features, Pricing, How It Works/ATS Explained, About, Contact, Privacy, Terms, Refund Policy and any editorial content. Use the WordPress editor for these.

Build application routes/pages for registration, login, email verification, password reset, dashboard, basic checker, Job-to-CV Detector, CV builder, drafts, saved CVs, analysis history, individual analysis, revamp comparison, subscription/credits, payment result/status, receipts and profile/account deletion.

Implement custom blocks or shortcodes such as `[ats_dashboard]`, `[ats_checker]`, `[ats_builder]`, `[ats_subscription]` and `[ats_payment_result]`. These are proposed custom interfaces, not plugins you can install by searching those names. Each must render the appropriate app component and enforce access server-side.

Hide the admin toolbar and prevent access to administrative screens for ordinary customers where appropriate, without breaking legitimate REST/AJAX operations. Keep public content editable in WordPress while private application screens remain protected.

## 7. Custom plugin structure and lifecycle

Suggested structure:

```text
ats-cv-detector/
  ats-cv-detector.php
  composer.json
  package.json
  src/
    Install/             # versioned schema and capability migrations
    Rest/                # authenticated/private and signed public endpoints
    Repositories/        # parameterized database access
    Auth/
    Plans/
    Credits/
    Payments/Paynow/
    Subscriptions/
    Resumes/
    Analysis/
    Documents/
    Jobs/
    Mail/
    Admin/
    Privacy/
  assets/src/
  assets/dist/
  templates/
  tests/
  uninstall.php
```

Namespace PHP classes, prefix hooks/options/tables, declare dependencies and load compiled assets only where needed. Scope CSS so WordPress themes cannot corrupt CV layouts. Avoid a second conflicting copy of React or another shared library.

Activation installs schema, records a schema version, adds approved capabilities and registers scheduled actions. Plugin upgrades need their own version check/migration path; activation alone does not handle every update. Deactivation unschedules plugin jobs but retains documents and financial data. Uninstall must not silently wipe paid history or private documents; destructive cleanup needs a deliberate, documented retention/export procedure.

Use [WordPress dbDelta](https://developer.wordpress.org/reference/functions/dbdelta/) where suitable for initial/additive tables, with explicit versioned migrations for operations it does not safely handle. Do not run schema-altering SQL on every request.

## 8. Database design

Use WordPress users for identity. Use `$wpdb->prefix`, not a hardcoded `wp_` prefix. Dedicated InnoDB tables are appropriate for the financial/AI workload; user meta and transient caches must not be the authoritative spendable balance.

Minimum logical tables and important fields:

| Table suffix | Essential contents |
| --- | --- |
| `ats_plans` | Unique slug, name, cents, currency, interval, credit policy, feature JSON, active flag, version |
| `ats_resumes` | Owner ID, UUID, name, schema version, structured JSON, selected template, draft state, revision, timestamps |
| `ats_files` | Owner, private storage key, MIME, size, checksum, purpose, retention/expiry |
| `ats_analyses` | Owner/resume/revision, job snapshot, result JSON, scoring/prompt version, operation ID, status |
| `ats_payments` | Unique merchant reference, owner, kind, immutable price/plan/credits snapshot, mode, integration-version ID, raw/internal statuses, provider reference, encrypted URLs, unique merchant trace, verification/backoff fields, fulfillment timestamp |
| `ats_payment_notifications` | Unique fingerprint, payment ID, redacted metadata, authentication/processing flags, error code, timestamps |
| `ats_subscriptions` | Owner, plan snapshot/version, start/end UTC, funding payment, status, cancellation metadata, renewal capability |
| `ats_wallets` | Unique owner, materialized subscription/top-up/debt balances and allocation period |
| `ats_credit_ledger` | Immutable deltas, reason, payment/subscription/AI references, unique idempotency key and timestamps |
| `ats_ai_operations` | Unique owner-operation key, input hash/revision, reserved amounts, period reference, state, attempt/lease metadata, output/usage |
| `ats_receipts` | Unique payment ID and receipt number, immutable purchase/customer/business/period snapshot |
| `ats_message_outbox` | Unique event key, owner, message type/payload, attempts, due time, sent time |
| `ats_billing_reviews` | Payment, actor, refund/dispute type, notes, processing history |
| `ats_audit_events` | Actor/subject/event, safe metadata, correlation key, UTC timestamp |

Use unique constraints for provider reference plus integration/mode, payment fulfillment, period allocation, receipt payment and message event. Index owner/date, payment state/next check and subscription end. Use explicit relationships and validate referential integrity even where WordPress conventions or migrations do not use foreign keys. Keep financial retention compatible with account deletion requirements rather than accidentally cascading everything away or blocking deletion without explanation.

For atomic changes, use the same database connection and transactions, explicit row locks and consistent lock ordering. `$wpdb` methods can return failure without throwing an exception: check return values and database errors and roll back. Do not send email, make AI calls or invoke arbitrary plugin hooks inside the ledger mutation transaction. Store an outbox event and act after commit. Test deadlocks and retries on the actual database engine.

## 9. Accounts, login and authorization

Use WordPress's user creation, password hashing, login cookies and password-reset mechanisms. Implement or select verified-email behaviour explicitly; ordinary WordPress registration is not the application's complete email-verification workflow.

Define capabilities such as `ats_use_app`, `ats_manage_billing`, `ats_manage_plans`, `ats_manage_templates` and `ats_view_audit`. Grant administrative capabilities only to approved roles. Never accept role/admin status from a registration form.

For same-origin logged-in REST requests, use the WordPress authentication cookie and `X-WP-Nonce` carrying a `wp_rest` nonce. Every private endpoint needs a permission callback plus resource ownership and plan checks. A nonce is not authorization and is not financial idempotency. See [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) and [custom endpoint permissions](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/).

Add rate limits for login, registration, email verification/resends, uploads, AI actions, checkout and status checks. Use a shared persistent store for multi-worker limits. Provide useful expired-session recovery and retain unsaved work safely. Optional Google sign-in needs validated OAuth state, correct public redirect URLs and deliberate account linking; do not merge accounts solely on an unverified email claim.

## 10. Private upload and import workflow

Accept only the document formats the importer supports, initially PDF and DOCX. Validate MIME/content as well as extension. Set explicit configurable limits for file size, extracted characters, page count, ZIP expansion and processing time. Reject executable, macro-enabled and malformed documents. Do not execute embedded content or follow document-supplied network URLs.

Store files outside the public web root or in private object storage. Do not place confidential CVs in the public Media Library and rely on an obscure filename. Downloads must authorize ownership each time, or use narrowly scoped short-lived access issued after authorization.

Extract text, normalize it and map it to the versioned CV schema. Preserve original employment/education dates, year-only dates, ongoing roles, phone/email, links, skills and custom sections. Show an editable import review; do not claim layout-perfect conversion.

Scanned PDFs require an explicitly implemented OCR path. If OCR is absent or fails, explain the limitation rather than reporting a low ATS score for an empty extraction. Keep extraction warnings separate from assessment results. Log technical identifiers and error categories, not complete CV contents.

If guest drafts are retained, choose a short retention policy, prevent cross-user adoption, clear drafts on shared-device logout and avoid placing CV content in URLs or analytics. AI operations and permanent saves should follow the approved account-verification policy.

## 11. CV editor and templates

Use structured JSON as the source of truth for both the editor and exports. Include identity/contact, professional summary, experience, education, skills, courses, languages, achievements, references where supported, custom headings/sections, section order and template/style settings.

Port the actual builder workflow: choose role/department, select an allowed template, start blank or import, enter content, edit on-document, review, save and export. Preserve useful existing examples without inserting fictional claims into the customer's CV.

Implement debounced autosave, explicit saved/error indicators, revision numbers/optimistic concurrency, retry recovery, navigation that waits for the last save, and reliable reopening. Resolve conflicts instead of silently overwriting a newer tab's changes. Preserve stable IDs for rows and custom sections.

Enforce template and saved-document limits on REST save/select/export operations, not only by hiding buttons. Manual editing and downloads cost no AI credits. After expiry, apply the existing saved-document editing policy without unlocking all new premium selections.

Provide keyboard labels, focus states, accessible controls, mobile editing and horizontal-overflow tests. Keep controls out of printable output. Long CVs must paginate; never shrink text indefinitely just to force one page.

## 12. Basic checker, job matching and honest scores

Keep the basic structure/readability checker separate from paid job-specific analysis. The latter compares the CV with the actual supplied advertisement and identifies evidenced matches, missing requirements and experience gaps.

Port the existing qualification, skill, industry and experience rules, including synonyms, context, overlapping employment dates, relevant versus unrelated experience and year-only uncertainty. Do not count the same overlapping months twice or award a qualification merely because the job advert contains it.

Return separate evidence-backed findings for structure, job requirements, skills, qualifications and relevant experience, alongside improvement suggestions. Preserve existing score fixtures and version algorithms. Explain the calculation and uncertainty; a match score is an estimate for this tool, not a guaranteed employer ATS result. Do not label CV checking as dependable AI-authorship detection.

For legacy saved results, retain the score version and avoid presenting new calculations as if the original source evidence had been re-evaluated.

## 13. AI assessment and revamp workflow

Keep the current configured provider/model until a separately evaluated change is approved. Call it only from the server; never put API keys into a shortcode, JavaScript bundle, REST response, HTML data attribute or source-control commit.

Recommended sequence:

1. Authenticate, authorize, validate the CV/job input and verify the exact revision.
2. Accept/create a stable operation ID and compute its input hash.
3. Atomically reserve the configured credits exactly once.
4. Enqueue an AI job, returning an operation reference and processing state.
5. Build versioned prompts treating CV/job text as untrusted content, not executable instructions.
6. Request schema-constrained output when supported; validate output, refusals, truncation and provider errors on the server.
7. Apply deterministic scoring/evidence checks; do not let arbitrary generated numbers override policy.
8. Save a valid result and finalize the reservation once, or restore the eligible reservation on confirmed failure.
9. Present results and the current wallet; allow the user to review proposed changes before saving a replacement CV.

OpenAI's Structured Outputs can constrain supported model responses to JSON Schema, but this does not prove that the content is factually correct. Retain application validation. See [official OpenAI Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs).

Revamps may improve grammar, ordering and relevance using the person's real information. They must not invent employers, degrees, dates, skills or achievements. Show before/after content, preserved facts, suggestions requiring user confirmation and any unresolved gaps. A retry of the same operation must not charge again; changed inputs require a new operation.

Set account quotas, bounded concurrency, input/output limits, maximum attempts, timeouts and provider-spend alerts. A browser timeout must not launch another chargeable operation blindly. Use job leases/fencing so a cleanup process cannot refund an operation that is still legitimately running and then accept a late success for free.

Describe what CV data is sent to the provider and how it is retained. Do not promise zero retention merely because a request disables response storage; provider settings, abuse monitoring and account eligibility matter. See [official OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data).

## 14. Atomic credit accounting

Never use a read-then-write user-meta balance for spending. Serialize a user's wallet mutations using an authoritative row lock and unique ledger keys.

Track welcome allocation, paid-period allocation, top-up, reservation, successful completion, failed-operation restoration, expiry and financial reversal as separate immutable events. Maintain balances as a checked projection of the ledger.

A useful operation state machine is `created -> reserved -> running -> completed`, with explicit `failed/refunded` outcomes. Validate that the same operation key cannot be reused for different input hashes.

When reserving, verify available funds and record exactly which allowance/top-up portions were reserved. On failure, restore only credits allowed by the original period policy; an expired monthly allowance must not become permanent top-up credit. At expiry, remove unused period allowance but preserve purchased top-ups. Reconcile materialized balances against ledger totals with an admin diagnostic.

Financial reversals must follow the existing policy: subscription funding revokes its associated period and remaining allowance; top-up reversal removes corresponding value and records configured debt if already consumed. Do not silently invent retroactive AI charges or erase ledger history.

## 15. Paynow credentials and checkout

Only Paynow Zimbabwe is the visible payment provider. The user's existing Integration ID can be configured securely; do not copy the Integration Key from this conversation into the guide or source code. Use host environment secrets or protected server configuration, and a dedicated plugin configuration reader. WordPress does not automatically load Laravel's `.env` or interpret Laravel configuration names.

If using constants such as `ATSCV_PAYNOW_ID`, `ATSCV_PAYNOW_KEY` and `ATSCV_PAYNOW_MODE`, implement their handling explicitly. Never expose secret values through the WordPress options REST API or allow ordinary users to edit them. Separate merchant test/live credentials and preserve an integration-version reference on each payment so rotating configuration does not strand older transactions.

Checkout sequence:

1. Signed-in, verified user selects a plan/top-up and accepts the payment terms.
2. Server validates the purchasable item, current access and renewal/change rules.
3. Server calculates cents/currency/credits and snapshots the offer.
4. Server creates a unique pending reference and persists a unique merchant trace before sending.
5. Send a form-encoded POST to `https://www.paynow.co.zw/interface/initiatetransaction` with the documented fields, correct URLs and Paynow hash.
6. Verify the decoded response hash, success state and safe HTTPS provider URLs.
7. Persist the encrypted poll URL, checkout URL and provider reference when supplied.
8. Redirect to hosted checkout. Card details stay with Paynow.

Use the documented SHA512 hash of concatenated decoded values in received order, excluding hash, with the Integration Key appended, returned as uppercase hex. Compare in constant time. Parse and verify the original provider message before generic WordPress sanitization/slashing changes the signed values. Validate field types and expected details afterward. Test against Paynow's independent published vector. See [Paynow initialization](https://developers.paynow.co.zw/docs/paynow/initiate_transaction/) and [hash generation](https://developers.paynow.co.zw/docs/paynow/generating_hash/).

In the WordPress HTTP client explicitly disable redirects for financial API/poll requests, enforce timeouts and HTTPS verification, and restrict poll/browser URLs to the expected Paynow hosts and ports. Do not let the client submit an arbitrary poll URL. Failed/unsigned/ambiguous responses must never redirect the browser or grant benefits.

## 16. Result notifications, browser return and recovery

Implement proposed endpoints such as:

- `POST /wp-json/atscv/v1/checkout`: authenticated and authorized; requires same-origin nonce.
- `POST /wp-json/atscv/v1/paynow/result/{reference}`: public provider endpoint with full hash authentication and strict body/size/rate limits.
- `GET /payment-result/?reference=...`: owned browser return; redirect logged-out users through login and back.
- `GET /wp-json/atscv/v1/payments/{reference}`: owned status read without forced provider polling.
- `POST /wp-json/atscv/v1/payments/{reference}/check`: owned, nonce-protected, rate-limited reconciliation request.

A public REST route may deliberately use a public permission callback, but the financial handler must then authenticate the provider payload itself. Do not require a WordPress login or nonce on Paynow's server-to-server result endpoint, and do not disable authentication globally for all other routes.

For each important result, verify signature, reference, amount, optional currency and provider-reference consistency, then reconcile with Paynow directly. Poll using an empty POST to the trusted stored poll URL; verify its hash and transaction details before fulfillment. Never trust a browser return or a result body alone as proof of payment. See [Paynow status updates](https://developers.paynow.co.zw/docs/paynow/status_update/) and [polling/trace](https://developers.paynow.co.zw/docs/paynow/polling_status/).

| Provider state | Product handling |
| --- | --- |
| Created / Sent | Pending; no paid benefits |
| Paid | Eligible after authenticated direct verification |
| Awaiting Delivery / Delivered | Paid but held; use the explicit existing digital-access policy after verification, retaining raw status |
| Cancelled | Closed attempt; no fulfillment, allow a fresh attempt |
| Refunded / Disputed | Record separately in raw status, revoke/review associated benefits under policy |
| Unknown, timeout, missing notification | Remain unresolved; reconcile |

Paynow does not supply a general documented Failed transaction state for every declined attempt. Do not invent that mapping or assume leaving checkout cancels it. An authenticated explicit initialization rejection can fail locally; a network timeout cannot prove that no provider transaction exists.

Deduplicate notifications and serialize fulfillment. In one transaction, record the paid result/fulfillment, create the funded paid period, allocate due credits, create the receipt and queue durable messages. Duplicate delivery returns success without repeating benefits. A verification outage returns a retryable HTTP error; only acknowledge a queued notification after its authenticated data and work item are durably saved.

Use bounded status checks, retry/backoff and missed-notification reconciliation. Preserve a user-friendly retry/continue flow: an existing attempt for the same item may be resumed, but selecting a different plan must not silently redirect to an old plan. Show the actual item before payment; resolve/cancel the old provider attempt only with verified state. Remove obstructive wording without deleting the financial safety rules.

If initialization loses its poll URL, recover using a signed matching notification or the documented signed merchant-trace request. Validate a recovered URL and poll again before fulfillment. If recovery remains uncertain, keep the reference for support rather than creating repeated charges.

Configure a public HTTPS hostname for both return and result delivery. A private LAN URL like the current `192.168.1.104` address cannot receive Paynow's external server notifications. Test cache/WAF/security-plugin behaviour and nonce-free provider delivery explicitly.

## 17. Subscriptions, renewal and plan changes

Paynow checkout purchases are one-time payments by default. Do not assume WordPress, a membership plugin or a merchant test credential enables automatic recurring deductions.

Use date-based entitlements. Access requires the appropriate status and `starts_at <= now < ends_at`. A database row labelled active but starting in the future grants no current access. Check expiry during protected requests even if a scheduled housekeeping job is late.

For early renewal, the next period starts at the end of the existing paid time. Monthly/Pro add a calendar month and Annual adds a calendar year, with defined month-end/leap-year behaviour. Allocate the new allowance at the new period start, not when a future renewal is prepaid.

Keep the current end-of-cycle plan-change policy: any paid plan can follow another after the existing period, without proration or discarding paid time. Limit future prepaid periods as specified. Immediate prorated upgrades are a different business feature and require an explicit approved formula and tests. Paid-to-Free occurs after the last purchased period if no renewal is paid.

Display current plan, actual charge/billing interval, start/end, current credit allowance, renewal due date, future paid periods and payment history. A Cancel renewal reminders action must say what it does: it does not refund a purchased period or cancel a non-existent automatic debit. Preserve documents/history after expiry and provide a visible renewal route.

## 18. Receipts, messages, refunds and administration

Generate an immutable receipt for verified live payments with customer/business details, receipt number, merchant/Paynow references, plan/top-up, cents/currency, paid time and funded period. Expose downloads only to the owner or a specifically authorized administrator. Distinguish test confirmations from live receipts. Do not label receipts tax invoices without the required configured business/tax details and appropriate review.

Use an outbox for successful payment, period confirmation/renewal, failure/cancellation update, reminder, expiry and refund/dispute messages. Unique event keys prevent routine duplicates; delivery retries have bounded attempts and backoff. Mail failure never rolls back a verified payment. SMTP does not guarantee exactly-once delivery across every crash, so keep financial idempotency independent.

Admin screens should cover payments, raw statuses/references, test versus live, live revenue by month/year, plan distribution, active/future/expired periods, credits/operations, notifications, refunds/disputes, job failures and audit events. Add search, filters, pagination, CSV export and capability-protected provider reconciliation. Protect CSV cells from spreadsheet formula injection.

Refund availability depends on the merchant agreement. Do not invent a reversal endpoint. Record refund requests and confirmed manual merchant actions with actor/time/notes; process real money movement through the supported merchant channel, then reconcile the provider result. A review note alone must not fabricate a Paynow Refunded status. Separate off-provider adjustments from provider-confirmed reversals.

## 19. PDF and Word export

Create PDF and DOCX from the same approved CV revision and template settings. Retain the current renderer/writer where feasible. A plugin rewrite should not quietly replace editable Word output with a screenshot.

Browser-rendered PDF can preserve HTML/CSS fidelity; Puppeteer provides PDF generation, but production needs a browser-capable worker. See [Puppeteer PDF documentation](https://pptr.dev/guides/pdf-generation). For DOCX, evaluate the existing writer or [PHPWord](https://phpoffice.github.io/PHPWord/) using real fixture documents. Do not assume HTML/CSS layouts convert identically to Word.

Test text extraction, reading order, links, Unicode, fonts, dates, tables/columns where used, long sections, pagination and removal of empty placeholders. PDF text must remain selectable/searchable. Treat support for a visually attractive multi-column template separately from evidence that a parser reads it correctly; do not guarantee universal ATS acceptance.

Escape content, sanitize links, disable arbitrary remote assets, isolate browser rendering and limit CPU/memory/runtime. Store exports privately, authorize download, send correct MIME/disposition headers, and clean temporary files after a defined period. Do not allow arbitrary HTML/URL rendering or shell-command injection.

## 20. Scheduling and background work

Use Action Scheduler or an equivalent durable queue for AI work, document jobs, reconciliation, credit/expiry maintenance, reminders and email. It offers traceable queued actions, but it still needs a working runner. See [Action Scheduler](https://actionscheduler.org/).

WordPress's page-traffic-driven scheduler alone is insufficient for dependable billing timing. Configure the host's actual cron/Task Scheduler to run due work regularly, verify it, then disable the page-load trigger if using a replacement. See [WordPress system scheduler integration](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

For example, a developer can arrange `wp cron event run --due-now --path=/absolute/wordpress/path` through WP-CLI once per minute under the site user, with an overlap lock and logs. The correct absolute executable/site paths and any dedicated Action Scheduler runner must match the deployment. Do not copy a placeholder path into production.

Track a scheduler/worker heartbeat, queue age, attempts and last successful reconciliation. Retry status reads safely; do not blindly retry financial initiation. Use bounded batches and exponential backoff. Expiry checks remain enforced in access logic even if jobs stall. Test both low-traffic operation and a failed runner.

## 21. Security, caching and privacy

Build the following into implementation and review:

- Prepared SQL, strict validation, escaped output and safe URL handling.
- Capability and ownership checks on all CV, analysis, receipt, profile, export and admin actions.
- Browser CSRF protection, plus separate provider signature authentication.
- Server-only secrets, restricted file access, encrypted sensitive configuration/URLs where appropriate, and a documented key-rotation/recovery process.
- No card/CVV storage, public CV attachments, arbitrary outbound fetches or untrusted shell arguments.
- Rate limits and size/time/concurrency limits; protect paid API usage from automated abuse.
- Separate admin privileges, secure login/recovery and administrator MFA where available.
- A managed update/staging-test process for WordPress, plugins, PHP, libraries and document workers.
- Redacted logs and diagnostics, correlation IDs, no complete CVs or keys in logs/analytics.
- Backups of database/private documents/configuration keys, with restore drills and access restrictions.

Exclude the dashboard, builder, profile, analyses, credits, payments, receipts and authenticated REST responses from shared page/CDN caching. Static assets and public marketing pages may be cached. Do not cache one user's credit balance or nonce into another user's page, and do not let object caches become the authoritative balance.

Publish accurate privacy, terms and refund documents reflecting CV storage, AI processing, Paynow, retention, exports, account deletion, credits and manual renewal. Add plugin callbacks to WordPress's personal-data exporter/eraser tools for custom tables/files, while handling required financial retention explicitly. See [WordPress plugin privacy guidance](https://developer.wordpress.org/plugins/privacy/). These controls support a policy implementation; they do not by themselves certify legal compliance.

## 22. Migration from Laravel

Do not replace production tables or import raw Laravel tables as WordPress core tables. Build a versioned import tool and run a dry run on a restored staging copy.

1. Inventory users, verified email states, profiles, saved CVs, drafts, uploads, analyses, template IDs, plan prices, active/future subscriptions, payments, receipts, wallet/ledger/AI operations, outbox and audit history.
2. Define stable old-to-new IDs and import checkpoints. Preserve reference strings, payment times, paid-period boundaries, operation keys, currencies and monetary precision.
3. Migrate accounts with a validated login strategy. Default to a secure password reset if password-hash compatibility has not been established; never email or export plaintext passwords. Preserve verified state only from trusted records and prevent accidental duplicate account linking.
4. Import documents privately, rewrite storage references safely, preserve checksums and test ownership. Convert structured JSON through an explicit schema adapter.
5. Migrate balances with ledger provenance. Do not recreate welcome credits or allocate existing paid credits again during first login. Reconcile each user's opening balance, top-ups/debt and future periods.
6. Laravel-encrypted poll/checkout URLs cannot simply be decrypted by WordPress. Use a restricted migration process with access to the old application key, then re-encrypt under the new system; do not expose plaintext in public exports. Preserve the integration version needed to verify outstanding transactions.
7. Leave historical test data marked as test; never transform test_paid into real paid entitlement. Preserve old scoring/prompt versions and receipts.
8. Decide which system owns each pending/in-flight payment and AI operation during cutover. Keep old callbacks/jobs running only for records they still own, or deliberately forward them with verified authentication. Do not let both systems fulfill the same purchase.
9. Rehearse maintenance/read-only cutover, final delta import, comparison counts, balances and rollback. Switch URLs/DNS only after parity checks pass. Preserve access to old callback paths until their transactions are settled or safely transferred.
10. After cutover, verify login/reset, private downloads, a real merchant test checkout, credits, notifications and schedules. Retain the old backup according to the approved retention policy.

The present Laravel setup is test mode and uses a private LAN application URL. Do not carry those deployment assumptions into the WordPress live setup. Existing pending payments and legacy test subscriptions must be deliberately classified during migration.

## 23. Automated and manual acceptance tests

Use PHP unit tests for pure scoring/period/credit logic, WordPress integration tests for hooks/routes/capabilities/database behaviour, fake HTTP for provider tests, and browser tests for user workflows. Port current Laravel fixtures as specifications rather than claiming the Laravel suite tests WordPress automatically.

Test at least:

| Area | Required checks |
| --- | --- |
| Identity | Register, verify, resend/rate limit, login/logout, reset, expired nonce/session, ownership, admin capabilities, optional OAuth |
| Imports | Valid PDF/DOCX, corrupted/password-protected/scanned files, huge ZIP/document, MIME spoofing, empty extraction, unusual Unicode, private storage |
| Editor | All templates/department variants, free limits, saved-document expiry policy, mobile/keyboard use, autosave races, refresh/navigation, multi-tab conflict |
| Analysis | Evidence and score fixtures, missing requirements, irrelevant experience, overlapping dates, year-only dates, empty/oversized inputs, prompt injection |
| AI/credits | Welcome once, 5/10 costs, duplicate operations, changed input hash, concurrent spending, timeout/retry, failure restoration, expired allowance, lost worker and late result |
| Exports | Every layout in PDF/DOCX, real editable text, reading order, pagination, links, dates/fonts, empty sections, authorization, temporary cleanup |
| Checkout | Exact server price, tampered amount/plan, inactive item, consent, test/live isolation, malformed response, unsafe redirect/poll URLs, credentials/config error |
| Provider results | Official hash vector, invalid signature, wrong amount/reference/provider ID/currency, unknown payment, duplicate and out-of-order delivery, all documented statuses |
| Return/recovery | Browser return before/after result, logged-out return, no callback, initialization timeout, trace recovery/NotFound, provider outage, safe retry/different-plan behaviour |
| Fulfillment | One period/allocation/receipt/outbox event, concurrent callbacks, test never grants live access, failed renewal preserves existing access |
| Renewals | Early monthly/annual renewal, month-end/leap-year boundaries, future period credits, every plan transition, no automatic deductions, expiry/free fallback |
| Refunds | Manual review permissions, authenticated refund/dispute reversal, consumed top-ups/debt, duplicate reversals, no fabricated provider state |
| Operations | Runner absent/stalled, low traffic, retry/backoff, mail failure, cache isolation, migrations twice, plugin update/deactivate, backup/restore and cutover rollback |

Run financial concurrency tests on the production database engine, not only mocked or single-process tests. Never charge real money in automated tests. Complete merchant test-mode transactions against the real public HTTPS deployment before live approval; mocked success cannot prove merchant settings or external callback reachability.

## 24. Implementation order and release gates

Build in this order, keeping each stage demonstrable:

1. Feature inventory, approved business rules, architecture and migration mapping.
2. Staging hosting, WordPress/theme, plugin skeleton, schema/versioning and roles.
3. Registration/login/verification and private storage/imports.
4. Structured editor, autosave and all template access rules.
5. PDF/DOCX parity and saved CV/history screens.
6. Ledger/reservations and operation idempotency/concurrency tests.
7. Basic checker, job analysis, truthful revamp and before/after review.
8. Paynow test initialization/result/return/poll/trace recovery and atomic fulfillment.
9. Periods, early renewal, plan changes, expiry, top-ups and reversal policy.
10. Receipts, outbox, administration, scheduling/monitoring and cache exclusions.
11. Migration rehearsal, privacy/security review, full regression and public merchant testing.
12. Owner-approved production cutover and live-payment configuration.

Do not enable live collection while callbacks are private/unreachable, jobs unmonitored, duplicate fulfillment untested or migration balances unreconciled. Keep business-rule choices visible and versioned rather than hiding them in page-builder forms.

## 25. Developer handover and final checklist

Required deliverables are the installable custom plugin ZIP, version-controlled source, compiled frontend assets and build instructions, dependency/license inventory, theme/custom styles, schema migrations, page/shortcode setup, capability matrix, secure configuration instructions, cron/worker instructions, tests/fixtures, migration and rollback tools, operational runbook and known-limitations register.

Final acceptance requires a customer to be able to register, verify, import or create a CV, choose an allowed template, save/edit/reopen, run the correctly charged analysis and revamp, review changes, download a readable PDF/editable DOCX, pay through Paynow, return to an accurate result, receive exactly one entitlement/allowance, view history/receipt, renew/change plan correctly and retain documents after expiry. Admins must be able to investigate failures without seeing secrets, trigger safe reconciliation, review refunds and restore the system from backups.

A complete design guide cannot guarantee a bug-free implementation. Completion means the actual WordPress build meets these checks, passes its own tests and succeeds with the merchant's real test integration. No WordPress site was installed, no existing Laravel code was replaced and no payment was initiated in preparing this guide.
