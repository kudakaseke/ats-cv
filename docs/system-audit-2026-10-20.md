# System audit — 20 October 2026

The audit used the local application at port 8000, disposable browser accounts, synthetic CVs and the isolated PHP test database. Existing customer CVs were not edited. No new payment was created. Two small OpenAI requests were explicitly run using synthetic documents and a disposable account; the owner's 150 purchased credits were preserved.

## Findings fixed

- The current `.env` no longer contained Paystack configuration. Restored the previously approved test credentials, ZAR prices and local test-credit setting without changing the OpenAI key. Verified the existing Monthly test payment with Paystack; the account has 150 purchased credits.
- Temporarily removing local test configuration could clear the displayed subscription balance. Restoring that configuration now recovers the unspent balance using the original allocation and operation records. It cannot refill spent credits or activate test credits in production. Added regression coverage for both cases.
- Inline editing used `innerText`, which applies CSS text transformations and could uppercase saved names. Editing now reads the original text while preserving entered line breaks.
- Review/PDF could include the empty date editor label `Dates` and dangling separators for empty fields. These are removed from review. Empty standard sections are omitted consistently across template categories.
- Home-page FAQs incorrectly described monthly free analyses and disabled paid upgrades. Updated the wording for one-time welcome credits, paid plans and AI processing; replaced remaining technical score terminology with a plain description.
- Updated browser checks for the current sign-in button and compact hero design. Added a complete guest-to-saved-CV journey. Legacy workspace-action checks now require `--live-ai` before making a billable scan.

## Verification

| Area | Evidence |
| --- | --- |
| Backend and security | 188 PHP tests, 1,044 assertions passed after the fixes, including registration, email verification, login, password reset, account ownership, payments, credits, refunds and audit logging. |
| Home and accounts | Desktop/mobile public pages, menu behavior, sign-in, profile update, password change, sign-out/sign-in and account deletion/cancel passed with a disposable account. |
| CV journey | Guest starting question, role recommendation, department selection, direct editing, renamed section headings, month/year dates, review, sign-in-to-save, reopening, PDF/Word downloads and Word import passed. |
| Templates | All 46 templates retained design and editing controls. All 11 department templates preserved role selection and data. |
| Pagination and review | 92 short/long template combinations matched review and PDF page fitting. All 11 department templates handled one-page and two-page examples. |
| Exports | Real PDF and DOCX artifacts generated; synthetic documents were exported and reimported through the browser. |
| Responsive workspace | Dashboard, Subscription, Settings, ATS checker, My CVs and Audit trail checked at widths 320, 390 and 1440 with no horizontal page overflow. |
| Scanner UI | Input steps, operation IDs, recommendations and credit updates passed using a browser response fixture. |
| OpenAI live workflow | Real `gpt-4.1-mini` analysis and rewrite passed: credits 15 → 10 → 0; replays did not charge again and further use at zero was rejected. Usage was 1,501 input and 587 output tokens. |
| Existing Paystack purchase | Reverified the R77.95 test payment; its purchased-credit balance remained 150. All three plan checkout pages showed the approved ZAR prices. |
| Audit trail | Owner-scoped payment history, credit ledger, account activity and mobile navigation passed. |
| Builder regressions | All 14 JavaScript regression checks passed. |
| Build | Production asset build and Blade view compilation passed. |

## External checks and deployment limits

- Paystack is in test mode. Live card charges, refunds and recurring live renewals were not executed. A public HTTPS webhook URL and a running Laravel scheduler are required for production notification/reconciliation coverage.
- Registration, code verification, reset and mail-failure handling passed in tests using simulated notifications. Actual SMTP inbox delivery was not tested in this audit.
- Google OAuth configuration is present; callback behavior is covered by application tests. An interactive Google account sign-in was not performed.
- The local environment and keys remain private in `.env`; they are not included in Git. Preserve these settings when editing the OpenAI configuration.

Reusable browser checks: `scripts/browser-smoke.mjs`, `scripts/system-journey-smoke.mjs`, `scripts/credits-browser-smoke.mjs`, `scripts/payment-audit-browser-smoke.mjs`. Billable live AI verification is explicitly opt-in through `scripts/openai-live-smoke.mjs --live`.
