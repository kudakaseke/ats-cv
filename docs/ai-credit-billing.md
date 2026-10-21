# AI credits and verified billing

Approved pricing (20 October 2026):

| Offer | USD price | Credits |
| --- | --- | --- |
| Free | 0 | 15 once per account |
| Monthly | 4.75/month | 150 each successfully paid month |
| Pro | 6.25/month | 225 each successfully paid month |
| Annual | 47.40/year | 150 released monthly during the paid year |
| One-time Monthly-bundle top-up | 4.75 | 150 purchased credits; subscription unchanged |

The user confirmed these prices, including Pro at 6.25 rather than the pasted example's 6.50. The Monthly bundle is reused for top-ups as instructed; the example's unpriced 50/100/250 packs are not offered.

Analysis costs 5 credits and includes local, server-calculated comparison results plus OpenAI recommendations. Revamping costs 10 credits and returns the validated structured CV to the builder. A complete workflow costs 15 credits independent of API token usage. Manual editing and downloads use zero credits; existing saved-CV plan limits still apply. Public scores remain estimates, not a guaranteed employer ATS result.

## Wallet rules

`AiCreditService` serializes mutations using a lock on the user row. A unique welcome reference grants 15 credits once, including for existing accounts on their first wallet access. Subscriptions replace the remaining subscription allowance each billing month; unused allowance does not roll over. Top-ups stay separate, have no monthly reset, and are spent after subscription credits. Annual allocations are anchored to the original purchase day using calendar-month arithmetic, including month-end dates. Missing past annual allocations do not accumulate. Paid access expiry removes unused subscription credits, while top-ups remain available. Cancellation stops future billing but does not revoke an already-paid term.

Usage is reserved before a paid AI request. Durable operation IDs and an input hash prevent retry charges, overlapping duplicate requests and reuse of an operation for different input. Completed results are encrypted in the operation record and can be replayed. Failed requests restore their original buckets exactly once; an expired subscription allocation is never converted to permanent top-up credits. The maintenance command refunds abandoned reservations after ten minutes, well beyond the provider's 60-second request timeout. A browser retry after an ambiguous network failure keeps its operation ID.

Actual model/provider/input/output token counts from available provider responses are recorded privately in `ai_usage_logs`; token counts never set the customer price. A crashed worker may not receive provider usage, so recovered operations can have zero recorded tokens. Refund/reversal of a spent top-up records a debt adjustment, preventing already-spent refunded credits being reused; displayed available balance never goes negative.

## Verified payments

Existing Paynow one-time and PayPal recurring checkout remain supported. Paystack API checkout, HMAC-SHA512 webhooks, remote verification, recurring receipt mapping, cancellation and reconciliation are added. Each receipt validates its saved amount, currency, environment, transaction identity and applicable plan/customer. Recurring grants are tied to an actual paid subscription period. Multiple receipts for the same billing period reuse its subscription instead of resetting spent credits again. Duplicate callbacks, failed payments, mere subscription activation and browser success URLs do not grant credits. Sandbox/test payments are recorded as tests and do not grant paid access or credits.

Subscription money and dates are snapshots on each payment; changing catalogue prices does not modify existing remote agreements. PayPal plan validation rejects stale provider prices. Paystack checks the actual remote plan price before redirecting a customer. USD catalogue prices are never treated as rand amounts: approved merchant-currency minor-unit amounts must be configured explicitly. Top-up prices are defined only on the server; browser amounts/credit counts are ignored. Only webhook routes bypass CSRF, and signatures/remote verification are still required. Authenticated purchase/cancellation forms keep CSRF protection.

References: [Paystack verification](https://paystack.com/docs/payments/verify-payments/), [Paystack subscriptions](https://paystack.com/docs/payments/subscriptions/), [Paystack signatures](https://paystack.com/docs/payments/webhooks/), [PayPal subscription events](https://developer.paypal.com/subscriptions/webhooks).

## Installation and activation

1. Run `php artisan migrate --force`. The new migration creates wallets, the ledger, durable operations, usage records and Paystack receipts; it updates the local plan catalogue. It does not charge customers or create remote plans.
2. Configure `OPENAI_API_KEY`, `OPENAI_MODEL`, and `CV_AI_PROVIDER=openai`. Ordinary OpenAI API billing is required.
3. For PayPal, configure matching Monthly/Pro/Annual plan IDs at 4.75/6.25/47.40 with their correct month/year intervals. `php artisan paypal:setup-plans --dry-run` previews these amounts; the command without `--dry-run` creates/reuses remote plans and must be run deliberately. Do not reuse an old 5.00 plan for new 4.75 purchases. Existing agreements are unchanged.
4. For Paystack, set `PAYSTACK_SECRET_KEY`, `PAYSTACK_CURRENCY=ZAR`, and approved `PAYSTACK_MONTHLY_AMOUNT_ZAR_CENTS`, `PAYSTACK_PRO_AMOUNT_ZAR_CENTS`, `PAYSTACK_ANNUAL_AMOUNT_ZAR_CENTS`. Run `php artisan paystack:sync-plans --dry-run` before deliberately syncing remote plans. Stored mismatched codes are rejected rather than repriced. The one-time 150-credit top-up uses the configured Monthly rand amount without attaching a recurring plan. Only enable `PAYSTACK_ENABLED` after setup is verified.
5. Register the public HTTPS `/webhooks/paystack` URL in the correct provider environment. Configure the existing PayPal/Paynow webhook endpoints for those methods. Secret keys remain private in `.env`.
6. Run `php artisan config:clear` and `npm.cmd run build`. The scheduler must execute `php artisan schedule:run` every minute so payment reconciliation and credit maintenance run every five minutes. Wallet reads also reconcile the current month's entitlement, so allocation is not dependent on a user logging in at midnight.
7. Test provider sandbox flows first. No real money or real OpenAI requests are used by the automated test suite. Live end-to-end billing still requires merchant credentials, matching remote plan IDs and public webhooks.

## Validation

`php vendor/phpunit/phpunit/phpunit` covers existing flows and new credit/payment cases. `node scripts/credits-browser-smoke.mjs` checks the dashboard, catalogue/top-up pricing, operation IDs, browser balance updates and AI recommendations using a mocked response. It creates and deletes only its own local test account and makes no paid calls.


Paystack checkout selection (20 October 2026)
-------------------------------------------
Local checkout now enables Paystack and disables new Paynow/PayPal purchases. Existing payment verification and cancellation routes remain available. Standalone Paystack test-page links are no longer offered because they do not create an account-linked billing reference.

Approved fixed ZAR prices, using the owner-provided USD/ZAR rate of 16.41 and rounding to cents:
- Monthly: R77.95, 150 credits/month (`PAYSTACK_MONTHLY_AMOUNT_ZAR_CENTS=7795`).
- Pro: R102.56, 225 credits/month (`PAYSTACK_PRO_AMOUNT_ZAR_CENTS=10256`).
- Annual: R777.83/year, 150 credits released each month (`PAYSTACK_ANNUAL_AMOUNT_ZAR_CENTS=77783`).
- One-time top-up: R77.95 for 150 credits, using the Monthly amount.

These are fixed merchant prices, not automatic foreign-exchange conversions. Set `PAYSTACK_SECRET_KEY` privately in `.env`, then run `php artisan config:clear` and `php artisan paystack:sync-plans`. The sync command creates/reuses plans without charging customers. Set the Paystack webhook to your public HTTPS application URL followed by `/webhooks/paystack`. Checkout supplies an account-linked return URL automatically. Test keys do not grant live paid credits. No Paystack key was present when this change was prepared, so remote plans and live checkout remain unverified.


Payment visibility and audit trail (20 October 2026)
--------------------------------------------------
Verified test payments redirect to Dashboard with an explicit test-success message. Dashboard and Subscription show the latest confirmed purchase, currency, amount, plan and reference alongside the current live plan. Test-mode payments remain separate from live subscriptions and spendable credits; an account remaining Free after a test transaction is intentional. Verified live purchases activate the real subscription and wallet through the same transaction-safe gateway application path.

`/account/activity` is the authenticated, owner-scoped, paginated audit page. It exposes payment records, the existing credit ledger and a new append-only application event stream. There are no edit/delete routes for audit events. This is an application audit log, not a tamper-proof external archive: database administrators retain database access.

Recorded activity includes payment creation/status transitions, successful provider verification, verification failures, subscription changes, credit allocations/charges/refunds/expiry, AI completion/failure, account creation/updates/deletion, authentication events, CV record lifecycle and authenticated write-request outcomes (excluding noisy draft autosave requests). Passwords, API keys, CV/job text, uploaded document contents and provider payloads are excluded. Account deletion nulls audit ownership/actor foreign keys rather than deleting the event stream. Model observers record ordinary model writes; direct database maintenance outside these services must record its own event.

Run `php artisan audit:backfill` once to include existing payment, subscription, credit and AI-use records as labelled snapshots. Reruns preserve the original snapshots. Historical changes before logging was enabled cannot be reconstructed; the existing credit ledger retains the financial movements already recorded.

Keep the Paystack webhook at the public HTTPS app URL plus `/webhooks/paystack`, and run the Laravel scheduler (`php artisan schedule:run` every minute in production) for missed-callback reconciliation and credit maintenance. The application records signed provider notifications and verification failures; it does not grant access based on an email or callback query alone.


Local test subscription credit simulation
-----------------------------------------
At the owner's request, `PAYSTACK_LOCAL_TEST_CREDITS=true` can activate credits for verified Paystack test subscriptions on the local development site. It is false by default and ignored outside `APP_ENV=local` or with live keys. Test subscriptions use status `test_active`; production/live access only recognises `active`. Verified existing test receipts can be activated once through normal provider reconciliation. Repeated checks do not refill spent credits. Test top-ups remain uncredited.

The Monthly local test subscription starts with exactly 150 credits, replacing the welcome allocation rather than adding it. Analysis costs 5, revamp costs 10, and the balance reaches zero without going negative. Local test activation and all spending remain in the audit trail. Switching out of local test mode invalidates the subscription allocation; failed operations cannot refund test subscription credits into production availability. The dashboard identifies this subscription as `(test)`.
