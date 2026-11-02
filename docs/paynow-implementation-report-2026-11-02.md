# Paynow implementation audit — 2 November 2026

Both attached specifications were reviewed against the existing Laravel 12 application and Paynow's official initiation, hash, status and polling documentation. The implementation extends existing services instead of introducing parallel payment/credit systems. Paystack code is preserved and its checkout card remains commented out.

## Behaviour delivered

- Server-priced, consented Paynow hosted checkout; validated signed initialization; protected HTTPS provider redirects; encrypted poll/checkout URLs; persisted provider reference and merchant trace.
- Owned browser return and status checking; authenticated result notifications; independent polling before financial fulfillment; signed trace recovery after a lost initialization response; bounded browser and background polling.
- Successful live verification atomically creates the paid period, current-period credits, receipt and durable billing messages. Test verification records `test_paid` without granting live benefits.
- Cancellation and signed initialization failure permit retry without granting access. Pending, unknown and timeout outcomes stay unresolved. Duplicate checkouts resume the existing attempt; duplicate notifications and returns do not allocate again.
- All eight documented provider statuses are mapped. Raw status and verification timing are recorded. Disputed/refunded funding revokes its associated entitlement; manual refund/dispute reviews retain an audit trail without fabricating provider confirmation.
- Manual monthly/annual renewal, one future prepaid period, end-of-cycle changes between every paid plan, future-period display and credit release at the correct start date. No proration, immediate upgrade or automatic Paynow deductions. Paid-to-Free occurs at final paid expiry without deleting documents.
- Scheduled subscription expiration, renewal reminders, reminder cancellation and bounded mail-outbox delivery. Existing atomic AI reservations, deductions and failure refunds are preserved.
- Owned printable/downloadable HTML receipts, payment history and admin-only reporting, filtering, CSV export, refund/dispute review and provider reconciliation.
- Pro price corrected from USD 6.25 to the supplied USD 6.50 specification. Monthly/Annual prices and all configured credit allocations/AI costs remain unchanged.

## Created files

- `app/Console/Commands/MaintainBilling.php`
- `app/Http/Controllers/BillingManagementController.php`
- `app/Jobs/SendBillingMessage.php`
- `app/Services/Billing/BillingRecords.php`
- `database/migrations/2026_11_02_000001_extend_paynow_billing.php`
- `database/migrations/2026_11_02_000002_add_paynow_merchant_trace.php`
- `resources/views/workspace/admin-billing.blade.php`
- `resources/views/workspace/receipt.blade.php`
- `tests/Feature/PaynowLifecycleTest.php`
- `README-PAYNOW.md`
- This report.

## Modified files for this work

- `.env.example`, `config/billing.php`: test-first defaults, currency confirmation, configurable plan-change policy and receipt business details.
- `app/Services/Billing/PaynowGateway.php`, `app/Services/Billing/PaymentService.php`: verified API lifecycle, recovery, atomic fulfillment and period preservation.
- `app/Http/Controllers/BillingController.php`, `app/Http/Controllers/CreditBillingController.php`: duplicate-attempt protection, validated recorded notifications, safe recovery and check throttling.
- `app/Http/Controllers/WorkspaceController.php`: future paid periods.
- `app/Models/BillingPayment.php`, `app/Models/Subscription.php`, `app/Models/User.php`: verification/cancellation date casts and non-mass-assignable admin flag.
- `app/Console/Commands/ReconcilePayments.php`, `app/Console/Commands/WorkBackground.php`, `routes/console.php`: reconciliation backoff, maintenance scheduling and billing queue consumption.
- `routes/web.php`: receipt, reminder cancellation and protected admin routes.
- `database/seeders/SubscriptionPlanSeeder.php`: requested Pro price.
- `resources/views/layouts/app.blade.php`: admin billing navigation; existing unrelated edits were retained.
- `resources/views/workspace/payment-methods.blade.php`, `resources/views/workspace/payment.blade.php`, `resources/views/workspace/subscription.blade.php`: preserved hidden Paystack card, Paynow policy copy, pending/failure/cancellation/retry states, future periods, renewal controls and receipts.
- `scripts/paynow-smoke.mjs`: current three-plan Paynow-only browser checks.
- `tests/Feature/BillingTest.php`, `tests/Feature/AiCreditsTest.php`, `tests/Feature/WorkspaceTest.php`, `tests/Feature/HostedPaystackTest.php`, `tests/Feature/PaystackPlansTest.php`: documented callback fields, currency guard setup, requested price and intentionally hidden Paystack UI expectations. Existing provider backend tests remain enabled.

Other pre-existing workspace changes were not part of this payment implementation.

## Database and routes

Both additive migrations were applied to the local database. Existing financial and application rows were retained. They add verification/trace metadata, notification/receipt/message/review tables, cancellation metadata and an explicit admin flag. Existing billing, subscription, wallet, ledger and audit tables remain authoritative.

Routes and scheduled commands were inspected with `route:list` and `schedule:list`. The stale route cache was cleared. Exact route names, middleware, setup and deployment commands are documented in `README-PAYNOW.md`; existing provider-specific paths were reused rather than duplicating the alternate paths in the prompts.

## Account-dependent limits and production steps

Only hosted checkout is enabled. The merchant's Paynow account decides its available card/mobile channels. Optional express mobile checkout, automatic tokenized deductions and an invented refund API are not included. Manual merchant refunds are reviewed locally and provider-confirmed refunds/disputes are reconciled.

No live-mode switch or Paynow transaction was initiated during implementation. Before live approval, confirm merchant test/live settings, USD collection/settlement, enabled channels, public HTTPS callback delivery, mail, queues and scheduler. Explicitly provision the intended administrator. Review the paid-but-held digital-access policy and end-of-cycle plan-change setting. Complete the real merchant test checklist in `README-PAYNOW.md`.

The automated suite uses mocked provider HTTP and SQLite. It verifies sequential idempotency, expected lock usage and credit conservation; production-engine parallel load/deadlock testing and real merchant delivery remain operational checks. SMTP delivery has the usual crash-after-acceptance duplicate window; it cannot duplicate payment fulfillment.

## Final validation results

- `php artisan test --compact`: **391 passed, 0 failed; 2,168 assertions** in the final full run.
- `node scripts/paynow-smoke.mjs` against the temporary local server: **passed**, covering Monthly, Pro, Annual, Paynow-only checkout visibility, mobile layout and pending status/check UI. No provider checkout was initiated.
- Paynow hash: official published SHA512 test vector passed, alongside independent signed fake responses.
- Both migrations: applied locally and confirmed with `migrate:status`.
- Routes/scheduler: inspected successfully; billing maintenance/reconciliation/credit maintenance are scheduled.
- Blade compilation: passed; compiled views cleared afterward.
- Laravel Pint: applied to the changed billing PHP and test files.
- `git diff --check`: no whitespace errors.

The suite includes the existing ATS/CV, authentication, credit and retained provider backend regression tests. Tests expecting the now-hidden Paystack checkout card were updated to enforce its hidden state rather than disabling their backend coverage.
