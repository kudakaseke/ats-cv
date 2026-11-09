# Paynow billing

The Laravel 12 application uses a Laravel HTTP client implementation of Paynow's form-encoded protocol. Paynow is the only supported payment provider. Existing billing, subscription, credit ledger, audit, authentication and queue services are reused. Historical migration definitions and payment records are retained for upgrade compatibility and accounting; they do not enable another payment provider.

## Provider references

- [Initiation](https://developers.paynow.co.zw/docs/paynow/initiate_transaction/)
- [Status definitions and result notifications](https://developers.paynow.co.zw/docs/paynow/status_update/)
- [Polling and merchant trace](https://developers.paynow.co.zw/docs/paynow/polling_status/)
- [Hash generation and test vector](https://developers.paynow.co.zw/docs/paynow/generating_hash/)
- [Hash validation](https://developers.paynow.co.zw/docs/paynow/validating_hash/)
- [Official PHP integration guide](https://developers.paynow.co.zw/docs/paynow/php_quickstart/)

## Configuration

Obtain an Integration ID and Integration Key from the merchant's Paynow receive-payment integration. Store them only in the server environment. Configure test mode first:

```dotenv
PAYNOW_ENABLED=true
PAYNOW_MODE=test
PAYNOW_INTEGRATION_ID=your-id
PAYNOW_INTEGRATION_KEY=your-key
PAYNOW_TEST_EMAIL=your-paynow-merchant-email
PAYNOW_CALLBACK_URL=https://your-public-host
PAYNOW_LIVE_CURRENCY_CONFIRMED=false
PAYNOW_PLAN_CHANGE_POLICY=end_of_cycle
BILLING_BUSINESS_NAME="ATS CV Detector"
BILLING_BUSINESS_ADDRESS="Your business address"
```

`PAYNOW_CALLBACK_URL` is an optional HTTPS base, without query, fragment or credentials. Leave it empty when `APP_URL` already points to the correct public HTTPS origin. Add a different callback hostname to `TRUSTED_HOSTS`; configure `TRUSTED_PROXIES` for the actual proxy. Public callback routing, trusted-host validation and the login session must work on that host. A temporary tunnel requires an updated callback base whenever its hostname changes.

The application sends per-transaction URLs in every initialization request:

- Customer return: `GET https://your-public-host/billing/paynow/return/{reference}`. Authentication and ownership are required. A logged-out customer signs in before viewing their payment.
- Server result: `POST https://your-public-host/billing/paynow/notify/{reference}`. This endpoint is public, rate-limited and CSRF-exempt. Its body must have a valid Paynow hash.

These existing routes replace the alternative `/paynow/return`, `/paynow/webhook` and `/payments/paynow/result` names in the supplied prompts. Do not enter literal `{reference}` in a dashboard setting; the application provides the real URL for each payment. Return/result environment variables with different names are not used. The initialization endpoint and accepted provider hosts are fixed to official production HTTPS Paynow hosts, including for merchant test transactions. There is deliberately no arbitrary configurable API base or user-supplied poll URL.

Test mode is also controlled by the merchant integration at Paynow: setting the local mode cannot turn a live merchant account into a sandbox. Confirm the merchant integration is in test mode before completing any hosted checkout. `PAYNOW_TEST_EMAIL` is used only for test checkout authentication. Local test payments are recorded as `test_paid` and **never activate live access or allocate paid credits**.

## Deployment

1. Back up the database and environment; retain the existing `APP_KEY`, which encrypts stored checkout and polling URLs.
2. Run `php artisan migrate --force`. The additive migrations are `2026_11_02_000001_extend_paynow_billing` and `2026_11_02_000002_add_paynow_merchant_trace`. They add verification tracking, merchant trace recovery, notification records, receipts, an email outbox, admin reviews, subscription cancellation metadata and `users.is_admin`. They adjust the existing Pro price from 625 to the requested 650 cents; custom other prices are preserved.
3. Run `php artisan optimize:clear`, then cache configuration/routes as appropriate for deployment.
4. Configure real mail transport and a durable queue/cache backend. Set `BACKGROUND_QUEUE_CONNECTION=database` (or Redis), `QUEUE_PAYMENT_CHECKS=true`, and run a worker for `mail,payments,billing,default`: `php artisan queue:work database --queue=mail,payments,billing,default --timeout=180 --tries=3`. The queue `retry_after` must exceed 180 seconds. Restart workers after deployment.
5. Run `php artisan schedule:run` every minute through cron/Task Scheduler. `billing:reconcile`, `billing:maintain` and the existing `credits:maintain` run every five minutes. On bounded-worker hosting, the existing `php artisan app:work` now also consumes the billing queue; invoke it every minute instead of a permanent worker.
6. Provision an admin explicitly through a trusted server console, for example `$user = App\Models\User::where('email', 'your-admin@example.com')->firstOrFail(); $user->forceFill(['is_admin' => true])->save();`. Public registration/profile mass assignment cannot set admin status. No user is promoted automatically.
7. Verify test checkout, return, result POST, mail, scheduler and queue processing on the deployed HTTPS hostname. Monitor failed queue jobs, unprocessed notifications, verification audit failures and unresolved initialization attempts. Run `queue:retry` only after resolving the underlying fault; payment jobs poll status rather than repeating charges.
8. **Do not switch to live without the owner's approval.** Confirm USD collection/settlement with Paynow, merchant payment channels, test/live integration details and HTTPS callbacks first. Only then set the approved live credentials, `PAYNOW_MODE=live` and `PAYNOW_LIVE_CURRENCY_CONFIRMED=true`, and rebuild configuration caches.

No external provider transaction or live-mode switch is part of automated validation.

## Checkout, verification and status policy

Prices come from active database plans, never posted amounts. Monthly is USD 4.75 / 150 credits, Pro USD 6.50 / 225 credits, Annual USD 47.40 / 2,400 credits, and Free grants the existing 15 welcome credits once. AI costs stay at 5 for a job check and 10 for a revamp. Integer cents are the internal money representation.

Every checkout creates a unique pending payment before contacting Paynow. Initialization uses `application/x-www-form-urlencoded`, validates the response hash, permits only HTTPS Paynow redirects, and saves encrypted provider URLs before redirecting. The original provider reference is saved when returned. A verified, signed initialization rejection becomes `failed`; timeouts, unsigned errors and ambiguous outcomes remain pending.

A browser return is never payment proof. A valid notification alone never grants access either: reference, expected cents, optional currency and pinned provider reference must match, then the stored provider poll URL is checked using an empty POST with redirects disabled. If initialization lost the poll URL, a correctly signed matching notification may supply the first safe Paynow URL; existing URLs are never overwritten by notifications. New initializations also persist a unique 32-character `merchanttrace` before sending. When no poll URL is available, reconciliation sends a signed POST to the documented `/interface/trace` endpoint, validates its response and recovers a safe polling URL. It then polls that URL before fulfillment. An authentic NotFound trace remains pending, with backoff; it is not treated as permission to charge again. Legacy attempts without a trace or poll URL require merchant support. Hash validation uses decoded field values in their received order plus the integration key and uppercase SHA512, with constant-time comparison. Tests include Paynow's published independent hash vector.

| Paynow status | Internal status | Entitlement policy |
| --- | --- | --- |
| Created, Sent | pending | No paid access or credits |
| Paid | paid / test_paid | Live payments fulfill once; tests never fulfill |
| Awaiting Delivery, Delivered | paid / test_paid | Verified paid-but-held funds fulfill under the existing digital-access policy; raw status is retained |
| Cancelled | cancelled | No fulfillment; allow a new attempt |
| Disputed, Refunded | reversed | Revoke the subscription funded by that payment; reverse purchased top-ups with the existing debt policy |
| Unknown | pending | No grant; reconcile or review |

Paynow's documented statuses do not include a general `Failed` transaction status. Declines can leave hosted checkout incomplete until Paynow reports a cancellation or another documented state; the app does not manufacture a failure from elapsed time. The first supplied prompt's suggestion to cancel a still-pending payment is intentionally not followed because it conflicts with the second prompt's uncertainty rule and provider behaviour.

Concurrent fulfillment serializes on the user and payment rows inside a database transaction. Subscription creation, wallet/ledger updates, receipt creation and durable billing-message creation commit atomically. A paid timestamp and unique receipt/ledger/message keys prevent repeated benefits. Polls do not downgrade a fulfilled payment for an out-of-order pending/cancelled result. Reversed payments never automatically regrant access after dispute resolution; an administrator must review recovery.

Notifications store a SHA256 body fingerprint and only bounded reference, amount, provider reference and status fields. Tokens, payment instruments, email details, secrets, complete bodies and poll URLs are not logged there. Invalid hashes are retained for review but cannot cause polling or fulfillment. Duplicate processed bodies return 200; verification outages return 503 for provider retry. Keep notification records according to your chosen retention policy; do not indiscriminately delete financial/audit records.

## Renewals and plan changes

Paynow purchases are one-time, customer-authorized payments. No tokenization, card storage or automatic deductions are enabled. Hosted checkout exposes only the channels enabled by the merchant; the application does not claim that Visa, Mastercard, EcoCash or OneMoney is enabled for an account. Express mobile checkout is optional in the prompts and is not implemented.

- Same-plan early renewal begins at the end of all existing paid access, not at the payment date. Monthly/Pro add a calendar month; Annual adds a calendar year, without day overflow.
- `PAYNOW_PLAN_CHANGE_POLICY=end_of_cycle` permits every paid-plan transition after the existing paid period. Access changes begin at the new period's start. Repeat-purchase credits are added immediately under SUBSCRIPTION_CREDIT_TIMING=immediate. No proration or immediate upgrades are assumed. Set the policy to `after_expiry` to block changes until current access ends.
- With immediate credit allocation, multiple paid future periods may be purchased sequentially. Deferred allocation or after-expiry billing retains the one-future-period limit. Only one unresolved subscription checkout per user/provider/mode is allowed. A repeat checkout resumes that attempt. Top-ups similarly resume an unresolved Paynow top-up. A pending attempt with no recoverable provider URL requires support review, not repeated initialization or an unverified cancellation.
- Cancelling renewal reminders stops reminders, not an already purchased period. Paid-to-Free happens naturally at the end of the final purchased period if no new payment is made. No documents/accounts are deleted. Refund a future purchased period through the refund review process rather than pretending it was never paid.
- The existing credit policy remains: monthly allowances reset per paid period, annual allowance lasts the paid year, purchased top-ups persist, failed AI operations restore eligible reserved credits, and all spending serializes on the user row. An early renewal adds the new allowance immediately to remaining subscription credits; the combined balance expires at the end of the extended paid term and is not granted again at the next period start.
- Expired periods are marked `expired` by maintenance; the existing access service already checks dates on each request. A cancelled/refunded funding period revokes its associated access. Refund/dispute of subscription funding removes its remaining allowance; consumed AI usage is not retroactively billed as new debt. Purchased top-up reversals use the existing balance/debt rules.

## Results, receipts, mail and admin

The existing payment result view handles pending, cancelled, failed and reversed states. Verified success returns to the dashboard with a confirmation and current credit balance. Pending browser checks stop after one minute; server-side direct checks are limited to once per 30 seconds. Scheduled pending reconciliation runs no more often than five minutes, with bounded exponential error backoff up to a day; paid/test-paid statuses are checked daily for refunds/disputes for up to the existing 400-day horizon. Financial rows are never auto-deleted as abandoned. Important authenticated notifications reconcile immediately.

The subscription page shows current dates, manual renewal, future paid periods, history and receipt downloads. Downloadable, printable HTML receipts snapshot customer, business, plan, amount, references and period details and show the current payment status. They are **not tax invoices**. Receipt routes require the owning authenticated user.

`/admin/billing` is restricted to explicitly provisioned admins. It provides status/mode totals, gross verified live revenue for the current month/year, paid plan distribution and purchased credit totals, subscription counts, reference search, status filters, CSV export, failed-notification review, refund/dispute notes and safe provider reconciliation. Test transactions are separated from live revenue. Gross revenue is not a settlement or accounting report and excludes currently reversed rows.

Refund APIs are not assumed. Admins record requests/manual merchant actions with notes, perform actual money movement in Paynow or an agreed external channel, then reconcile. Recording a review alone never marks a payment refunded or grants access. Provider-confirmed `Refunded`/`Disputed` status controls the automated entitlement reversal. Off-provider financial adjustments need a separately approved accounting policy; they must not be disguised as Paynow confirmations.

Billing messages cover verified payment, confirmed paid period, cancellation, failure/reversal, renewal reminders and expiration. The durable outbox is created alongside the payment transaction, delivered by maintenance/queue, and retried at most five times with backoff. Mail failure cannot undo a payment. Unique event keys and a delivery lock suppress normal duplicates. Like most SMTP systems, delivery cannot be guaranteed exactly once across a crash after SMTP acceptance but before saving `sent_at`; the financial effects remain idempotent.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| POST | `/billing/paynow/{monthly\|pro\|annual}` | Authenticated, consented checkout |
| POST | `/billing/paynow/notify/{reference}` | Signed server notification; only Paynow endpoint exempt from CSRF |
| GET | `/payments/{reference}` | Owned return/status view |
| POST | `/payments/{reference}/check` | Owned status check, JSON or HTML |
| GET | `/payments/{reference}/receipt` | Owned receipt download |
| POST | `/subscriptions/{subscription}/cancel-renewal` | Owned manual-renewal reminder cancellation |
| GET | `/admin/billing` | Admin analytics/filter/export |
| POST | `/admin/billing/{reference}/reconcile` | Admin provider verification |
| POST | `/admin/billing/{reference}/review` | Admin refund/dispute audit notes |

Paynow is the only enabled payment integration. Retired provider schema is handled by the archival migrations described below.

## Manual merchant test checklist

1. Select each plan and confirm USD 4.75 / 6.50 / 47.40, consent, public HTTPS return/result URLs and account email.
2. Complete a merchant **test** checkout; return to the app and confirm `test_paid`, a success message, no live access/credits and no live receipt.
3. Cancel checkout; poll the confirmed Cancelled state and safely start a new attempt. Try an unsuccessful method enabled by your merchant and confirm no benefits appear while pending or cancelled.
4. Leave checkout pending; checks stop in the browser after one minute and the transaction remains available for later reconciliation. Never assume abandonment proves failure.
5. Return before the result arrives, and separately receive the result before browser return. Replay a signed result; verify one entitlement/ledger allocation/receipt for approved live verification in a controlled release test.
6. Interrupt initialization and confirm a second click does not create a second provider request. Confirm a later signed result with a valid poll URL recovers the original attempt.
7. Test wrong signature, wrong amount/reference, invalid poll host, cross-user receipt/status access and non-admin access. All must fail without fulfillment.
8. Test early monthly/annual renewal and a paid plan change: old period remains intact, next period begins at old expiry, purchased credits become available immediately and are not granted again when that next period starts.
9. Test expiration, reminder cancellation, receipt download, mail delivery/retry, refund/dispute review and scheduled missed-result reconciliation.
10. Confirm actual supported hosted payment channels, settlement currency, refund process, notification delivery and operational monitoring with Paynow before approving live processing.

## Automated verification

Run `php artisan test --compact`. `PaynowLifecycleTest` uses fake HTTP responses, independent response signatures and a published provider hash vector to cover all documented statuses, duplicate result/return ordering, callback recovery, signed initialization errors, timeout ambiguity, amount/reference/currency mismatch, SSRF restrictions, receipts/admin authorization, monthly/annual early renewal, end-of-cycle plan changes, expiration, cancellation and mail-outbox failure isolation. Existing billing, AI reservation/restoration/overspending and application regression tests remain in the suite. SQLite tests verify sequential idempotency and locking structure; they are not a substitute for parallel transaction testing on the production database engine.

`APP_TEST_URL=http://127.0.0.1:8017 node scripts/paynow-smoke.mjs` checks the three visible Paynow plan pages, mobile layout and status/check UI using a temporary local user. It never clicks checkout or contacts Paynow. A real merchant test checkout is still required to validate merchant configuration and external reachability.

`php scripts/payment-concurrency-audit.php` creates and migrates a disposable MySQL database with a strictly validated `ats_cv_payment_audit_` name, runs up to eight PHP workers using simulated signed provider responses, and removes that database afterward. It checks duplicate verification, different users, simultaneous checkout, sequential renewal periods, and wallet overspending. It requires database-creation permission and never runs migrations against the customer database. This is a concurrency correctness test, not a production throughput benchmark.

## Payment abuse controls

Checkout and top-up routes share limits of 6/minute and 30/hour per authenticated user plus 120/minute per IP. Status checks allow 12/minute per user and 300/minute per IP; browser returns allow 20/minute per user and 600/minute per IP. Existing workspace and site limits also apply.

Callbacks have independent ingress limits of 100/second and 3,000/minute per IP, plus 60/minute per payment reference. Ordinary page limits do not consume this callback allowance. Callback bodies over 16 KiB are rejected before sessions; callback routes do not create browser sessions. Invalid signatures, unknown payments and mismatched details are rejected before notification storage or provider requests. Valid decoded payloads use a canonical fingerprint so URL re-encoding cannot bypass deduplication. Limit violations return HTTP 429 with `Retry-After`.

Provider verification uses a 90-second shared-cache lock per payment. Provider I/O runs outside database transactions; entitlement and wallet changes use short transactions locking the user/payment rows. A busy verification is retryable. Paid states cannot regress to pending/cancelled, and reversals cannot be reactivated by a late Paid response. Poll URLs require the exact HTTPS Paynow payment-status endpoint with redirects disabled.

For multiple application hosts, use the same Redis cache/limiter and database on every host (`CACHE_STORE=redis`, `CACHE_LIMITER=redis`), durable queues and supervised `payments` workers. Keep queue `retry_after` longer than the 180-second job timeout (database/Redis defaults are 240 seconds). Run the scheduler every minute, monitor failed jobs and stale payments, and restrict trusted proxies to real proxy addresses. Put request-body/connection limits and bot/DDoS filtering at the reverse proxy or WAF; application throttling cannot absorb a network-level flood. Capacity-test the deployed infrastructure and agree callback volume limits with Paynow before increasing them.


## Retired provider database cleanup (8 November 2026)

The two `2026_11_08` migrations remove `paystack_receipts`, `paypal_subscription_receipts`, the three `paystack_*` plan fields, and the unused `hosted_reference`, `hosted_page_slug`, `capture_reference`, `provider_plan_id` and `provider_customer_id` payment fields. Historical migration definitions remain unchanged for upgrade compatibility; a fresh installation ends with the cleaned schema.

Before each drop, the migrations encrypt and verify the old rows in `retired_billing_records`. Its source/id uniqueness makes retries safe. It is an accounting archive, not a gateway integration. No payment rows, subscription links, wallet balances or credit ledger entries are deleted. Generic recurring flags/statuses remain only to detect unresolved historical agreements and prevent duplicate billing or account deletion; no automatic billing is enabled.

Deployment: put the app in maintenance mode, pause queue workers, take and verify a full database backup and securely retain the original APP_KEY, deploy the Paynow-only code and Composer lockfile, run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:clear`, and `php artisan view:clear`. Inspect migrated table/column names and archived row counts before resuming workers and traffic. Run the documented mocked suite against a disposable database first. Do not run `migrate:fresh` on customer data.

Rollback requires the pre-deployment full database backup and previous application release; these cleanup migrations intentionally refuse a lossy `down()`. Preserve APP_KEY: encrypted payment URLs and the accounting archive depend on it. An encrypted local full snapshot was verified before the local cleanup; it is stored under ignored `storage/app/billing-backup-*.encrypted` and is not a portable SQL dump. For production use the database engine's tested backup/restore tooling, including a restore rehearsal.

Optional separate environment credentials are `PAYNOW_TEST_INTEGRATION_ID`, `PAYNOW_TEST_INTEGRATION_KEY`, `PAYNOW_LIVE_INTEGRATION_ID` and `PAYNOW_LIVE_INTEGRATION_KEY`. Set only the credentials for the intended deployment. Existing shared `PAYNOW_INTEGRATION_ID`/`PAYNOW_INTEGRATION_KEY` remain the compatibility fallback. Empty mode-specific values also fall back to shared credentials. Never commit actual secrets. Existing local keys were preserved and the local integration remains in test mode.


With `QUEUE_PAYMENT_CHECKS=true` and a durable `BACKGROUND_QUEUE_CONNECTION`, validated result notifications enqueue `ProcessPaynowNotification` on `payments` and return promptly. The job uses the persisted notification fingerprint, retries five times with backoff, records sanitized failures and marks successful processing idempotently. Keep the worker running; callbacks never grant credits before the worker verifies Paynow. Local synchronous verification remains available for development.

Checkout returns bypass the ordinary 30-second status-check cooldown so a recent Created response does not delay confirmation of a subsequent payment. Returns still require authentication, ownership and server-side Paynow verification. Successful test returns redirect to the dashboard with a test confirmation; they do not grant live credits or activate a live plan. Existing payment-history links continue to use `/payments/{reference}`.

Plan selection is checked when resuming an unfinished checkout. An existing Monthly order is never silently used for a Pro or Annual selection. The app blocks the new selection with a clear message until the old Paynow attempt is resolved; it does not alter historical prices or blindly create a second charge. Checkout review and Paynow descriptions include the selected plan and credit allowance.


## Optional local test subscriptions

Set `PAYNOW_LOCAL_TEST_CREDITS=true` only in local development with `PAYNOW_MODE=test` to exercise subscription credits and plan access after a signed, verified test payment. This opt-in is ignored outside APP_ENV=local and when Paynow mode is live. Default test checkout still grants no entitlements. Sandbox subscriptions use the separate `sandbox_active` status; legacy `test_active` records are never enabled. Payments remain `test_paid` and do not generate live receipts or live revenue.

Pro grants 225 credits and Annual grants 2,400 for the applicable period. Existing purchased credits remain intact. Repeated verification cannot refill spent credits or create duplicate subscriptions. Disabling the option excludes sandbox subscriptions and expires their remaining wallet allowance; restoring it recovers only unspent allowance. Existing renewal timing and no-proration rules also apply to local test subscriptions. Test top-ups do not create spendable credits. Never deploy a development database as a production database; production must retain its own real payment and wallet records.


## Immediate credits for repeat subscription purchases

With `SUBSCRIPTION_CREDIT_TIMING=immediate` (the selected default), each verified early renewal or plan change adds its credit snapshot immediately. Paid access periods stay sequential. Existing remaining subscription credits and the new allowance form a pool valid until the extended paid term ends. The future subscription has `credits_granted_upfront=true`, preventing any second grant on its start date. Set the timing to `next_period` only to restore the former delayed-allocation behavior for future purchases.

The wallet and reserved AI operations track funding sources per subscription. Spending uses the oldest available subscription source first; failed AI calls restore only valid, non-refunded sources. Refunds remove the unspent credits funded by that payment without taking another purchase's credits. Separate purchased top-up credits are preserved. Local test pools are excluded outside local payment testing. The additive November 8 migration adds the upfront marker and nullable source maps; existing payment and ledger history is not rewritten. An existing verified future purchase can be reconciled once to apply the new timing; its unique ledger reference prevents replay.
