# Paynow payment audit — 9 November 2026

## Result and scope

Audited the Laravel Paynow checkout, signed callbacks and polling, return-to-dashboard flow, subscription and credit fulfillment, reversals, concurrency, authentication/ownership, rate limiting and dependency advisories. Paynow remains the only payment integration. No real payment was initiated, merchant secrets were not changed, and customer payment/wallet records were not adjusted during this audit.

The automated checks below pass. This is an application audit and a bounded concurrency correctness exercise; it is not a penetration-test certification, a production capacity benchmark, or a guarantee of Stripe-equivalent availability.

## Findings fixed

| Priority | Finding | Change |
| --- | --- | --- |
| High | Unauthenticated callbacks could persist notification rows before signature validation. Equivalent URL encodings could produce different replay fingerprints. | Validate signature, route reference, payment ownership of provider details and amount before insertion. Deduplicate a canonical decoded field map. Invalid callbacks do not trigger provider requests. |
| High | Late provider Paid responses could reach deferred renewal credit allocation after reversal. Confirmed test states could regress on older pending responses. | Reversed payments remain reversed; paid/test-paid cannot regress to pending, cancelled or failed. Future allocation additionally requires a valid paid payment and eligible subscription status. |
| High | Concurrent checkout reads could use an earlier MySQL repeatable-read snapshot and create multiple attempts. | Pending subscription and top-up lookup now uses a locking current read under the user lock, with transaction deadlock retries. Parallel tests verify one payment and one initialization per simultaneous checkout wave. |
| Medium | Provider requests held user row locks, blocking unrelated wallet work for the network timeout duration. | Poll and trace outside the fulfillment transaction; use a shared-cache lock per payment. Short database transactions serialize entitlement/receipt/wallet changes. Busy verification remains retryable. |
| Medium | Ordinary page and callback IP limits could throttle unrelated customers sharing the provider's IP. | Separate callback ingress allowance and reference limit; checkout/check/return limits combine user and IP scopes. Limits return HTTP 429 and Retry-After. |
| Medium | Callback requests used browser sessions and admitted unnecessarily large bodies. | Stateless callback route; 16 KiB body limit before database sessions. Preserve signature authentication and CSRF on browser mutations. |
| Medium | Signed polling URLs allowed other Paynow paths, and reference pinning was not atomic in every recovery branch. | Restrict polling to `/interface/checkpayment` on the two approved HTTPS Paynow hosts; reject credentials, fragments and nonstandard ports; disable redirects. Pin provider references under a payment lock during poll/trace recovery. |
| Medium | A future legacy subscription plus welcome allowance could create a pooled balance without matching funding sources. | Pool balance equals its subscription source map, replacing unbacked welcome/expired allowance and recording the correct ledger delta. |
| Medium | First-period allowance used the current credit catalogue instead of the purchased snapshot. | Verified Paynow subscriptions use their payment's credit quantity; legacy/manual subscriptions retain the configured allowance. |
| Low | Confirmation messages still said early-renewal credits would be delayed. | Messages follow the selected immediate/deferred credit policy. |
| Dependency | Node audit reported `follow-redirects` advisory GHSA-mrwp-f3q8-3g9p. | Updated the dependency lockfile to the patched release. Node audit and Composer audit report no current vulnerabilities/advisories. |

## Rate limits

| Operation | Account/reference scope | IP scope |
| --- | --- | --- |
| Subscription checkout and top-up, combined | 6/minute and 30/hour per user | 120/minute |
| Explicit payment checks | 12/minute per user | 300/minute |
| Payment return/status pages | 20/minute per user | 600/minute |
| Signed notification endpoint | 60/minute per route reference | 100/second burst and 3,000/minute ingress; 3,000/minute route limit |

Existing workspace/site limits also apply to browser routes. Callbacks have a separate allowance so normal site traffic cannot exhaust it. Callback bodies are limited to 16 KiB; the signature and transaction fields must validate before durable notification storage. Do not raise these limits without measuring actual merchant traffic. Distributed attacks require edge protection as well as these application controls.

## Verification

- `php vendor/phpunit/phpunit/phpunit --stop-on-error --stop-on-failure`: **376 tests passed, 2,148 assertions** across the complete application suite, including payment lifecycle, credit/refund accounting, authorization, malicious callbacks, rate limits, CSRF, timeout/retry, catalogue snapshot and replay regressions. Final output is recorded in `storage/app/payment-final-audit-tests.txt`.
- `php scripts/payment-concurrency-audit.php`: disposable MySQL database, real independent PHP processes, shared database cache locks, simulated independently signed Paynow responses, maximum eight simultaneous workers. All six scenarios passed:
  - Eight verifications of one payment: one subscription, one receipt, exactly 150 credits.
  - Sixteen different users: all payments paid, exactly 150 credits each.
  - Eight simultaneous subscription checkout requests: one payment and one provider initialization.
  - Eight simultaneous top-up requests: one payment and one provider initialization.
  - Eight concurrent payments for one account: eight sequential periods, 1,200 immediately available credits, no overlapping access periods.
  - Twenty concurrent spending attempts against 150 credits at 10 credits each: 15 accepted, five rejected, final balance zero.
- The final concurrency run completed in 29.18 seconds including worker startup; this timing is not a throughput claim. Its database was removed. Results: `storage/app/payment-concurrency-audit-results.txt`.
- `node scripts/paynow-smoke.mjs`: passed subscription plan/payment-method pages, mobile layout, payment-status rendering and check button; no provider checkout initiated.
- `npm.cmd run build`: production asset build passed.
- `composer audit --format=json`: no advisories or abandoned packages. `npm.cmd audit --json`: zero vulnerabilities after dependency update.
- Laravel Pint was applied to the files touched by this audit. A broader formatting check also identified pre-existing style issues elsewhere in the dirty workspace; unrelated files were not reformatted.

## Deployment conditions still required

The inspected local installation is `APP_ENV=local`, Paynow enabled in **test** mode, synchronous checks, database cache/limiter, and no separate public callback base configured. Local test subscriptions are isolated from production entitlement logic. These settings are suitable for local testing, not evidence of live deployment readiness.

Before enabling live payments:

1. Use a stable public HTTPS application/callback domain; confirm DNS, return/result paths, allowed hosts, trusted proxy addresses and secure session cookies. A temporary or expired tunnel cannot serve as the production return address.
2. Confirm live credentials and merchant USD settlement configuration with Paynow. Perform controlled merchant acceptance checks for the selected plan/price, confirmation, dashboard credits, receipt, cancellation and refund/reversal. Test-mode success does not prove live merchant configuration.
3. For multiple hosts, share the same Redis cache/limiter and database. Use durable queues, supervised workers consuming `payments`, and the scheduler. Keep retry_after above the 180-second job timeout; database/Redis defaults are 240 seconds. Monitor failed jobs, verification errors and old pending payments; reconcile missed notifications.
4. Use a production PHP application server and size workers, database connections and queue concurrency against measured checkout traffic and Paynow's permitted request volume. The eight-worker correctness test does not establish a maximum supported user count.
5. Enforce request-body/connection limits, TLS, bot filtering and DDoS controls at the proxy/WAF. Keep secrets outside source control, restrict database privileges, retain encrypted-URL APP_KEY securely, and rehearse database restore. Application throttling alone cannot stop network-level floods.

No live deployment, Git commit or push was performed for this audit.

## Provider/framework references

- [Paynow initiation](https://developers.paynow.co.zw/docs/paynow/initiate_transaction/)
- [Paynow status updates](https://developers.paynow.co.zw/docs/paynow/status_update/)
- [Paynow polling](https://developers.paynow.co.zw/docs/paynow/polling_status/)
- [Paynow hash generation](https://developers.paynow.co.zw/docs/paynow/generating_hash/)
- [Laravel rate limiting](https://laravel.com/framework/docs/12.x/rate-limiting)
- [Dependency advisory](https://github.com/advisories/GHSA-mrwp-f3q8-3g9p)
