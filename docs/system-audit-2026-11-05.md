# System audit — 5 November 2026

This audit covers the Laravel application in this workspace: authentication, account ownership, CV creation/import/editing, analysis and revamp accounting, downloads, subscription entitlements, checkout, callbacks, reconciliation, receipts, queues, security controls and dependency health. It combines source review, automated tests, browser checks and read-only inspection of the configured local MySQL database. It does not certify a deployed production environment or guarantee the absence of every possible defect.

## Problems fixed

| Finding | Result |
| --- | --- |
| PDF and Word rendering embedded the application entry script without its Vite shared dependencies. The offline browser could not resolve the relative imports and timed out before rendering a CV. | Export scripts now recursively embed their shared modules as data URLs. The export content policy permits those embedded modules while other network access remains blocked. A regression test covers nested dependencies. |
| When an offline dependency loaded after the document was ready, Alpine started before the importing entry registered the CV component. | Startup is deferred to the next task when the document is already complete. The component can register before rendering begins. |
| Dashboard and checker top-up links targeted a missing `credit-topups` section. | Subscription now includes the 150-credit, USD 4.75 Paynow purchase form, server-defined pricing, consent and explicit test-mode messaging. |
| A confirmed top-up initialization rejection remained pending, so subsequent attempts reused a failed checkout. | Explicit provider rejection marks the attempt failed. Ambiguous timeouts remain pending for reconciliation. |
| Checkout availability did not check the live USD confirmation flag consistently. | Subscription and top-up purchase forms use the same readiness check, and unconfirmed live top-ups are blocked before contacting the provider. |
| Historical test recurring agreements could block Paynow purchases; completed/disabled agreements were not excluded consistently. | Test agreements no longer block Paynow checkout. Unresolved live agreements still prevent duplicate live billing and direct the customer to support. Account-deletion guidance also directs legacy agreement holders to support. |
| Terms and billing documentation described monthly annual allocations and obsolete prices/providers. | Current documentation describes Pro at USD 6.50, annual at USD 47.40 with 2,400 upfront credits, and Paynow manual renewal. |
| Installed dependencies had security advisories. | Updated affected Symfony packages and `follow-redirects`; Composer and npm audits report no known advisories after updates. |

## Paynow-only payment implementation

Removed the other payment providers' controllers, gateways, subscription services, setup/sync commands, configuration files, route handlers, webhook CSRF exemptions, SDK dependency, branding asset, UI branches and obsolete provider-specific tests/scripts. Their keys were removed from the ignored local environment and the environment example without displaying values. Regression tests reject their checkout and top-up requests.

Paynow is the only active provider. It supports one-time USD subscriptions and purchased credit top-ups. New payments use server prices, consent, ownership checks and pending-payment reuse. Fulfillment requires verified provider status; a browser success URL does not grant access. Existing tests cover signatures, pinned provider references, amounts, unsafe URLs, duplicate callbacks, timeout recovery, merchant trace, refunds, receipts, manual renewals and scheduled entitlements.

Historical database migration definitions, accounting tables, payments and audit records remain intact. Removing those records would destroy accounting history and could break existing installations. Older dated audit reports and upstream dependency funding URLs may still mention removed providers; these do not enable integrations. An existing external recurring agreement is not cancelled by deleting local integration code.

## Current local operating state

- Environment: local; debug disabled; database migrations all applied.
- AI: OpenAI configured with `gpt-4.1`; key presence verified without disclosing it or making paid calls.
- Paynow: enabled in **test** mode; integration credentials present; live USD settlement not confirmed.
- Public callback override: absent. The configured application URL is local/LAN HTTP, so a stable public HTTPS callback is still needed for external merchant notifications.
- Queue: database; no queued or failed jobs at inspection. Payment status checks are synchronous locally.
- No stale AI reservations or unprocessed payment notifications at inspection.
- Historical local payments: six pending and three confirmed test Paynow payments; ten pending and eight confirmed test payments from a removed provider. No live payments were present in the grouped records.
- Nine historical nonterminal recurring records were found, all among the old test data. They were retained; they are not evidence of nine real live subscriptions.
- Local PDF extraction uses the project's Python environment internally. Deactivating that environment in PowerShell does not remove the executable. Deleting it would break local PDF extraction unless document processing is moved to the remote service.

## CV tailoring and token records

At the initial read-only inspection, application operations recorded:

| Operation | Completed | Failed |
| --- | ---: | ---: |
| Job-to-CV analysis | 50 | 11 |
| CV revamp | 44 | 6 |

These are recorded operations across the local database, including any historical test activity. They are not necessarily unique CVs or paying customers, and cannot be attributed to a particular USD 5 API purchase. Successful revamps consumed 440 application credits; successful analyses consumed 250. Application credits and provider token billing are separate.

Usage logs retain model and input/output token totals where available. Failed requests show zero customer credits charged; a provider may still bill tokens it processed before an incomplete response. The automated suite verifies refund/idempotency behavior without spending real API funds. Current provider availability and the external billing balance were not tested with a live paid request.

## Validation

- Full PHP suite: **351 tests, 1,921 assertions passed** after the export and payment fixes.
- Final credit/payment checks after removing the obsolete test-credit recovery branch: **72 tests, 398 assertions passed**.
- JavaScript/document service suite: **20 tests passed**.
- Real offline export audit: **94 PDF/Word exports passed across all 47 configured templates**.
- Browser page audit: **66 public/authenticated page and viewport combinations passed**, including mobile layout, images, payment choices, read-only email and browser error checks.
- Paynow browser smoke: passed for all three paid plans and payment status/check behavior, without initiating a provider payment.
- Account audit browser smoke: passed for balances, owner account history, credit ledger and mobile layout.
- Full browser journey: passed guest onboarding, template access, editing, saving after sign-in, reopening, PDF/Word downloads, Word import, Paynow USD prices and responsive workspace without provider or AI charges.
- Export-specific PHP tests: 13 passed, including the new offline shared-module regression.
- Frontend production build: passed.
- Composer audit, root npm audit and document-service npm audit: no known vulnerabilities after updates.
- Composer manifest validation and installed platform requirements: passed.
- Deployment readiness command: local tooling checks passed, while production environment, HTTPS, secure cookies, background worker/polling settings, worker heartbeat, shared Redis and dedicated database identity checks failed. This local XAMPP setup is not production-ready.
- Changed PHP files formatted using the existing formatter; patch whitespace checks passed.

Detailed local outputs are in `storage/logs/audit-*.txt`. `php scripts/system-audit.php` repeats the read-only configuration and aggregate database inspection without printing keys, emails or CV contents.

## Remaining verification and limits

1. Confirm merchant settings and USD collection/settlement, provide stable public HTTPS callbacks, and complete merchant test checkout/notification delivery before any approved switch to live mode. No live switch or charge was made.
2. Scheduler definitions and queue behavior were checked locally. Actual production cron, workers, email delivery, Google OAuth and remote hosting connectivity still require deployment-level verification.
3. Retained uncertain payments require reconciliation; a pending record is not proof of failure or permission to pay again. No real customer payment state was manually changed.
4. `scripts/browser-smoke.mjs` has stale homepage expectations: it expects more than 20 icons and homepage feature/about sections that now reside on separate pages. It fails those old assertions. The newer 66-page audit passed; this legacy script remains a test-maintenance limitation.
5. Existing untracked WordPress work was preserved. This audit does not certify native WordPress equivalence or a separate deployed WordPress installation.
