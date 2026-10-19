# System audit and fixes — 16 October 2026

Application: atscvdetector. Audit performed against the local Laravel application, MySQL database and Chrome on Windows. Existing work was preserved. Browser tests used disposable fictional accounts and CVs. No customer documents were submitted to external AI, no real payments were initiated, and no production deployment was performed.

## Findings and corrections

| Priority | Problem found | Correction and verification |
| --- | --- | --- |
| High | Builder draft, import and export POST endpoints allowed requests without the login/verification required by Get Started. | Applied authentication and verified-email middleware consistently. Regression tests cover guests and unverified accounts. Get Started returns guests to login and resumes the builder after login. |
| High | Completed scans did not persist results or consume the advertised free allowance. | Save completed analyses independently of saved CVs, enforce the monthly allowance inside a transaction with a user-row lock, and return the saved analysis ID. Tested three permitted scans and rejection of the fourth. |
| High | The saved-CV allowance was not enforced. | Check the limit under a user-row lock when creating a CV. Existing CV edits continue to work, and an over-limit attempt preserves the draft. Added owner-protected deletion so users can free their slot. Tested ownership, cancellation, confirmation and deletion. |
| High | AI processing consent was preselected internally instead of being an explicit user choice. | Added an unchecked processing notice and privacy link. The rewrite cannot start until consent is checked. Browser verification confirms zero rewrite requests without consent; server consent validation remains in place. |
| High | Windows exports could fail with HTTP 503 because the development server stripped environment variables required by Node/Chrome. | Preserve necessary Windows variables in the development server and provide required process variables to the exporter. Actual PDF and editable Word exports now succeed for all 22 templates. |
| High | DOCX imports could accept malformed XML/encoding, merge words at tabs/cells or silently omit content beyond 20,000 characters. | Validate UTF-8 and XML with network access disabled, retain entity/DOCTYPE rejection and size limits, preserve word separators and reject excess text explicitly. PDF imports now use the bounded extraction service. Regression tests cover malformed XML, invalid encoding, excessive content and tab-separated names. |
| High | Password changes retained remembered-login credentials and other database sessions. | Rotate the remember token; password changes revoke the user's other database sessions while keeping the current session. Password reset revokes the user's database sessions. Tests verify other users' sessions are untouched. This invalidation applies to the configured database session driver. |
| Medium | A PayPal plan setup failure could leave an agreement pending and block later checkout/account deletion although creation had never begun. | Mark pre-creation validation failures SETUP_FAILED, exclude them from open-agreement guards and hide cancellation controls for them. Provider timeouts after possible creation remain protected against duplicate subscriptions. |
| Medium | Private pages lacked explicit no-store response headers. | Add private/no-store caching for signed-in pages and authentication forms, plus content-type sniffing, framing and referrer headers. Regression coverage includes login and Livewire dashboard responses. |
| Medium | Deep structured JSON and malformed Google profile fields could generate server errors. | Reject excessive JSON nesting with 422 validation; validate Google identifier/email lengths and use a safe fallback for non-string names. Added regression cases. |
| Medium | Registration/profile/reset email normalization differed; non-string login email could reach a string cast in rate limiting. | Normalize string email consistently and handle malformed input in rate-limit keys. Tested uppercase registration and array input rejection. |
| Medium | Leaving the editor could race its latest save. | Dashboard navigation now waits for saving and remains in the editor on failure. Browser unload warns while changes are pending or saving failed. JavaScript regression verifies that navigation waits for the current save. |
| Medium | Account-deletion backdrop could intercept the dialog buttons after transitions completed. | Give the modal panel an explicit stacking position above its backdrop. Actual browser checks now exercise Cancel and successful deletion. Added dialog semantics. |
| Medium | Offscreen mobile sidebar links remained keyboard-accessible. | Apply inert while the mobile sidebar is closed and update state on resize. Browser tests check the attribute is present when closed and removed when opened. |
| Medium | Fresh migration-only installations could have no free plan and fail when loading the dashboard. | Added a migration that creates missing default plan records without overwriting existing plan settings. Fresh-database tests now load dashboard and pricing without manual seeding. |
| Medium | Saved check history and revamp actions led to placeholder functionality. | Added paginated private history and an owner-protected saved-result view. Dashboard/checker link to saved checks; the revamp URL opens the existing checker workflow. Removed unreachable placeholder configuration. The sidebar stays as requested. |
| Low | Paid pricing actions led to Contact rather than the relevant plan. | Link each public pricing action to its subscription card. Disabled/unconfigured providers remain explicitly unavailable. Updated stale feature wording. |
| Medium | Export working files had no scheduled expiry. | Added cv:prune-exports and daily scheduling. Only matching generated UUID filenames older than 24 hours in the private export directory are eligible; symlinks are skipped. Dry-run and preservation of recent/unrelated files are tested. |
| Low | Several browser tests used obsolete headings or the earlier guest-builder workflow. | Updated fixtures and assertions to the current authenticated flow, added account/CV actions and consent tests, and added an actual 44-export audit script. |

## Verification completed

- Full PHP suite: **143 tests passed, 724 assertions**. After the final placeholder cleanup, the affected workspace/audit/PayPal suites also passed: 30 tests, 152 assertions.
- JavaScript regression suite: **7 tests passed**; includes save races, navigation, date handling, import preservation, empty sections and error responses.
- Production Vite build passed. Blade views compiled successfully.
- Public/browser smoke passed: homepage, responsive widths from 320 to 1920, navigation, FAQ, About/Features flows, ATS Explained, Terms, Privacy, Refund, Contact, login/register/recovery screens, real MySQL login/dashboard, sidebar keyboard exclusion, profile update, password change, sign-out/sign-in, account-deletion cancellation and confirmation, and no uncaught JavaScript errors in those flows.
- Builder browser smoke passed: login handoff, onboarding, 22 templates, live edits, date fields, draft reload, responsive editor, escaped content, saving/reopening and text-based PDF import.
- Workspace actions passed: saved-CV deletion cancellation/confirmation, subscription card rendering, persisted scan history and saved-result navigation.
- Checker browser smoke passed: pasted input, uploaded PDF, score dialog, explicit AI consent, simulated external-AI failure/retry, structured CV review, server-recomputed score, mobile view and actual PDF download. AI responses were mocked deliberately; no external-AI success is claimed.
- Download smoke passed for actual PDF and editable Word downloads.
- All **22 templates** passed preview/editor/mobile/print parity and the long-CV one-page checks.
- **44 actual exports** passed: PDF and Word for every template, checking file structure and fictional content markers. PDF checks also verified one-page output for the test CV.
- ATS analysis verification: **108 TF-IDF scores matched scikit-learn to 1e-8**. PDF extraction tests covered normal text, spaces in paths, blank/scanned-style, encrypted and malformed inputs. These establish implementation consistency, not equivalence to any employer's proprietary ATS.
- PHP syntax checks passed for the application/test/config files checked. Composer reported no security advisories; npm audits reported zero known vulnerabilities. This does not prove absence of unknown vulnerabilities.
- All local migrations are applied. Scheduler lists billing reconciliation every five minutes and export cleanup daily.

## Checks that remain outside the local audit

1. **Live payments:** PayPal/Paynow remain disabled pending merchant configuration, credentials, plan IDs, registered public HTTPS callbacks and sandbox/live acceptance tests. Paystack links are development test links and do not grant application access. Payment ownership, verification, replay/renewal/refund/cancellation logic is covered by automated tests using provider fakes; real settlement and callbacks were not exercised. See payment-providers.md and paystack-plans.md.
2. **Google and mail:** real Google authorization, provider console configuration and email deliverability require configured accounts. Local authentication, callback validation and notification behavior are tested, but delivery is not certified.
3. **Production operations:** configure the scheduler, HTTPS, secure production settings, backups/restore, worker/process supervision and monitoring in the actual hosting environment. Local successful scheduling registration does not run a production scheduler.
4. **External AI:** confirm suitable processor/data-use settings before submitting real CVs. A consent checkbox does not establish a suitable processor arrangement. Failure/retry is tested; live output quality and factual accuracy require review.
5. **Paid product scope:** advanced insights, priority-support operation and any template-level paid restrictions need a concrete product definition before those promises can be fully accepted. Existing scan/CV count entitlements are enforced; no arbitrary restrictions were invented during this audit.
6. **Policies:** production entity, hosting/processor details, retention and support operations still require owner verification as documented in policy-review.md. This technical audit does not certify legal compliance or eliminate litigation risk.

This is a tested local audit with reproducible fixes, not a guarantee that every possible browser, input, provider failure or production configuration is error-free.

## Reproduce checks

Use an isolated local/testing database for PHP tests. Browser fixture scripts refuse production and use disposable example.invalid accounts. Run the application on the desired local port and set APP_TEST_URL to it for browser commands.

```text
php artisan test --compact
npm run build
npm run test:builder-regressions
npm run test:browser
npm run test:builder
npm run test:workspace-actions
npm run test:downloads
npm run test:checker-pdf
npm run test:ats
npm run test:one-page
npm run test:template-parity
npm run test:ats-analysis
npm run test:exports
composer audit --no-interaction
npm audit
php artisan schedule:list
php artisan cv:prune-exports --dry-run
```

On this Windows setup, use npm.cmd where PowerShell blocks npm.ps1. Browser/export checks require installed Chrome and the configured Node/Python tools. Screenshots are under storage/app/previews; generated test exports are removed by the export audit.
