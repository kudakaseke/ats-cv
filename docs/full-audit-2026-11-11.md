# ATS CV Detector audit — 11 November 2026

## Assessment and scope

**Remediation update:** all five findings below have now been addressed locally. See [the fixes and validation report](audit-fixes-2026-11-11.md). The remaining sections preserve the original pre-fix audit evidence and its production-validation limits; they are not a claim that these five defects are still present.

Audited the local working tree at commit `cc9cdd0`, including the pre-existing untracked native WordPress plugin and WordPress connector. Scope includes Laravel routes/controllers, authentication, CV import/build/save/export, local scoring, AI orchestration, credits, Paynow, subscriptions, history, administration, privacy, the Node document worker, deployment configuration and automated QA. Historical reports were used as context; results below were rerun.

Tests use synthetic documents/accounts, in-memory SQLite, disposable SQLite/MySQL databases and mocked external providers. The developer database was not reset. No live payment, paid AI request, real OAuth exchange, email delivery, deployment, commit or push was performed. Production infrastructure and third-party service behavior are not certified by this local audit.

## Original reproduced findings (before remediation)

### AUD-01 — Medium — State-changing GET requests can discard drafts

**Location:** `app/Http/Controllers/CvBuilderController.php:24`; `routes/web.php` also exposes `finish()` through GET.

`GET /get-started?new=1` deletes `cv_builder` from the session without a CSRF token or server-side confirmation. An observation test populated a draft, issued that GET, and verified that the draft disappeared. A cross-site top-level navigation can carry a SameSite=Lax session cookie. Consequently, a crafted link can discard the current session draft. Previously saved database CVs are not deleted by this request.

`GET /get-started/finish` also creates a saved CV when a suitable draft exists. Its state-changing implementation was identified by code review; the dedicated reproduction here targets draft deletion.

**Fix:** move new/discard and finish mutations to CSRF-protected POST routes; make GET routes display or redirect without mutations. Preserve the editor's explicit discard confirmation. Test cross-site navigation, refresh, browser Back and link prefetch behavior.

Reference: [OWASP CSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html) advises against state-changing GET requests and explains the SameSite=Lax limitation.

### AUD-02 — Medium — Account deletion leaves other sessions and recovery records

**Location:** `app/Http/Controllers/ProfileController.php:55`; `database/migrations/0001_01_01_000000_create_users_table.php`.

Deletion removes the user and invalidates the requesting session. It does not delete the user's other database sessions or their password-reset token. The sessions table's `user_id` is indexed but has no cascading foreign key.

**Reproduction:** insert another session containing synthetic CV data and a reset-token row; delete the account with its correct password; verify the user is gone but both rows remain. The observation test reproduced this. This establishes residual storage; it does not establish that a deleted user can still authenticate. Recovery rows are keyed by email, so they should also be invalidated before that email can be reused.

**Fix:** revoke all sessions and outstanding recovery/verification artifacts during account deletion, with an explicit policy for each configured session driver. Keep legally required billing retention separate from session/CV deletion. Test another browser session, account recreation, and a deletion concurrent with an in-flight save.

### AUD-03 — Medium, configuration-dependent — Password changes do not revoke non-database sessions

**Location:** `app/Http/Controllers/Auth/PasswordController.php:32`, `NewPasswordController.php:57`, `SetPasswordController.php:36`; `bootstrap/app.php`.

The three password flows delete other sessions only when `session.driver === 'database'`. The web middleware does not include a password-hash/session-version check to revoke old file/Redis sessions.

**Reproduction:** create and save an independent file session containing the user's authentication key; change the password while using the file driver; load the old session into a fresh SessionGuard. It still resolves the same authenticated user. The observation test verifies that the new password hash was saved, so the result is not caused by a failed password update.

**Impact:** someone already holding a valid old session can retain access after a password change on affected configurations. File sessions were dynamically demonstrated. Redis and the reset/initial-setup branches have the same missing invalidation by code inspection, but were not independently exercised against a real Redis server. The default database-session configuration has an explicit revocation path and is not demonstrated vulnerable by this test.

**Fix:** implement revocation independently of session storage, such as a persisted authentication version checked on protected requests, or correctly configured framework session authentication covering every sign-in/recovery flow. Add actual two-session tests for every supported deployment driver.

Reference: [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html) and [password recovery](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

### AUD-04 — Medium — DOCX import depends on a literal XML prefix and merges text

**Location:** `app/Http/Controllers/CvBuilderController.php:283`.

The importer parses XML, then extracts text with replacements targeting literal `w:` element names. XML namespace prefixes are aliases; WordprocessingML can use a different prefix or a default namespace.

**Reproduction:** submit WordprocessingML using prefix `x` bound to the WordprocessingML namespace, with `Alex`, a tab, `Morgan`, then separate `Skills` and `PHP SQL` paragraphs. The endpoint returns `AlexMorganSkillsPHP SQL`. Paragraph and word boundaries are lost, which can corrupt the candidate name, section parsing and subsequent matching. Existing tests cover only `w:` forms.

**Fix:** extract paragraph, tab, break and table-cell nodes through namespace-aware DOM/XPath. Cover alternate/default namespaces, strict/transitional namespace forms, tables, headers and footers with clear documented import scope.

### AUD-05 — Medium — Negation of one skill suppresses unrelated positive evidence

**Location:** `app/Services/CvJobRequirements.php:139`.

The matcher skips an entire resume line when it contains words such as `no`, `not`, `without`, `lack` or `missing`.

**Reproduction:** for job `Required skills:\nSQL`, resume `Skills\nSQL` produces a keyword score of 100. Resume `Skills\nSQL; no Python experience` produces 0, although the SQL evidence is unchanged. Both results were reproduced in the same observation test.

**Impact:** valid qualifications/skills can be marked missing because another clause contains a negative statement. This affects the local keyword score and related comparisons; it does not prove that every AI evidence score changes in the same way.

**Fix:** scope negation to the relevant clause and requirement. Add mixed positive/negative examples, `not only`, and positive phrases such as `without supervision`, while retaining tests that reject genuinely negated qualifications.

## Process coverage

“Passed” below means the stated tests passed, not that every possible input, timing or deployment was tested.

| Process | Work performed and result | Limits / findings |
| --- | --- | --- |
| Public pages and pricing | Browser checks for guest redirects, FAQs, mobile navigation, paid-template gate and signup validation passed in the initial maintained browser rerun. | Not a complete assistive-technology or SEO audit. |
| Registration and email verification | Backend validation, throttling, verification attempts/expiry and browser form validation exercised. | Real email transport, spam placement and delivery latency unverified. |
| Login, logout and recovery | Backend authentication/security suite passed; browser logout/recovery acknowledgement passed. | AUD-02 and AUD-03. Real recovery email delivery unverified. |
| Google sign-in and initial password | Backend tests cover state validation, verified identities, account linking and initial-password permissions. | Provider responses mocked; real OAuth credentials/redirect configuration unverified. AUD-03 for non-database sessions. |
| Profile and account deletion | Name/email restrictions, password checks, deletion and recurring-payment guard tested. | AUD-02. |
| Guest builder and draft start | Session drafts, guest continuation and template access tested. | AUD-01. |
| Template selection | Final isolated browser rerun selected all 47 templates and completed successfully, including cleanup. | An earlier parent-runner timeout is retained in the evidence. |
| Inline editing, save and navigation | Backend and JS tests plus browser tests for invalid edits, explicit discard, overlong fields and delayed saves passed. | Multiple-tab editing and every input/timing permutation not exhaustively tested. |
| Saved CV ownership, quotas and reopen | Backend ownership/plan tests and browser save/reopen assertions exercised. | Saved templates intentionally remain editable after expiry. |
| PDF/TXT/DOCX import | File limits, validation, malformed XML, extraction errors and existing import regression tests exercised. | AUD-04. Scanned/image-only PDF OCR is not implemented by these text extraction paths. |
| Basic ATS structure detector | Backend checks cover readable text, contact details, headings/dates and no AI credit charge. | Text heuristics do not assess original PDF layout or certify employer ATS acceptance. |
| Job requirement/keyword/experience scoring | Unit suite plus independent TF-IDF comparison against scikit-learn: 108 pairs agree to 1e-8. | AUD-05. This validates arithmetic for the shared tokenizer, not semantic accuracy for every profession. |
| AI analysis | Mocked success/failure, schema/evidence handling, saved analysis and charge/refund/idempotency tests passed. | Live model output quality, nondeterminism, prompt injection resilience and provider availability require separate live evaluation. |
| AI CV revamp and review | Backend/JS tests exercise confirmed skills, original facts/title retention, schema validation, malformed responses and retry identity. | No real paid generation; factual truthfulness cannot be guaranteed solely by a schema/prompt. |
| Credit allocation/spend/refund | Backend tests cover welcome/annual/expiry, failed operations and replay. MySQL concurrency audit passed. | Real production throughput and Redis behavior unverified. |
| Checkout and Paynow callbacks | Mocked initialization/poll/notification tests cover signed payloads, amount/reference checks, unsafe URLs and status transitions. | Live settlement, refund/chargeback delivery, merchant currency and public callback reachability unverified. |
| Subscription renewal and reversal | Backend tests and simultaneous-renewal MySQL audit passed. | Simulated provider, not a merchant test transaction. |
| Receipts, history and administration | Ownership/admin controls, billing records and CSV escaping reviewed/tested by existing backend coverage. | No production data reconciliation performed. |
| PDF/Word downloads | All 94 real PDF/Word exports passed across 47 templates. Export scripts block network loading. | Synthetic short-content round trips do not cover every long CV, font or office application. |
| Document worker | Two Node tests cover signed content, replay prevention, private temporary cleanup, cancellation and concurrency rejection. PHP remote-client tests passed. | Linux container build/sandbox behavior, actual remote TLS and production crash recovery not exercised. |
| Security middleware and Apache | Host/method/CSRF/rate-limit tests passed. Local Apache returned 403 for `.env`, `.git/HEAD`, `composer.lock`, private logs and `public/.env`; public homepage returned 200 with frame and nosniff headers. | Production virtual host/CDN behavior unverified; GET mutation exception is AUD-01. CSP is deliberately partial. |
| Logging and monitoring | Incident/Sentry privacy-filter regression tests passed. Secret-pattern scan found no matches in scanned first-party source; `.env` is not tracked. | No new live Sentry event sent. Pattern scanning is not a complete secret-history audit. |
| Schedulers and deployment | Deployment-check command run; syntax/dependency checks rerun. | Local production prerequisites fail as described below. Backup restore/failover unverified. |
| Native WordPress application | 84 integration checks passed, including real concurrent financial workers and document round trips. Browser login, autosave/edit/reopen, import/export, basic check, plans and mobile layout passed. | Separate implementation with different renderer/scoring; not proof of Laravel parity. |
| WordPress connector and packages | 28 connector/release checks passed; fresh native-plugin package extracted 416 files with metadata, bundled dependencies/licenses and current credential exclusion verified. | Real theme/plugin combinations, site cron, email and merchant settings unverified. |

## Verification and evidence

Evidence is under ignored `storage/app/`, with prefix `audit-2026-11-11-` unless otherwise stated. Evidence is local and is not included automatically in a Git checkout.

| Check | Result | Evidence suffix |
| --- | --- | --- |
| Full PHP regression suite | **394 tests / 2,269 assertions passed** | `phpunit.txt`, `phpunit.xml` |
| JS builder/scanner and document-worker tests | **30 passed** | `js.txt` |
| Dedicated defect observation tests | **5 tests / 15 assertions passed**, asserting the reproduced defective behavior | `observations.txt`; source `storage/app/AuditObservationTest.php` |
| PHP syntax | **234 PHP files, including 13 automation scripts; zero failures** | `syntax.txt` |
| JavaScript syntax | **58 JavaScript/module files, zero failures** | `syntax.txt` |
| Production asset build | Passed | `build.txt` |
| TF-IDF arithmetic comparison | **108 scores matched scikit-learn to 1e-8** | `tfidf.txt` |
| MySQL payment/credit concurrency | Passed; up to 8 PHP workers, 32.02 seconds; disposable database removed | `concurrency.txt` |
| Root Composer / npm advisories | Zero reported | `composer.json`, `npm.json` |
| WordPress Composer / document-worker npm advisories | Zero reported | `wordpress-composer.json`, `document-npm.json` |
| Python installed-package audit | Nine installed distributions checked; zero advisories and zero skips | `python.json`, `python.txt` |
| Native WordPress integration | **84 passed, 0 failed** | `wordpress-integration.txt` |
| WordPress connector and package | Passed: 28 connector/release checks; 416-file package extraction | `wordpress-original.txt`, `wordpress-package.txt` |
| Apache exposure probes | Five sensitive-path probes returned 403; homepage and health returned 200 | `apache.txt` |
| Real exports | **94 PDF/Word exports passed across 47 templates** | `exports.txt` |
| Browser injection probes | **141 template/editor/review combinations passed** | `browser-remaining.txt` |
| Scanner, basic detector and pricing browsers | Passed in isolated follow-up runner | `browser-remaining.txt` |
| Native WordPress browser | Passed real login, autosave, edit/reopen, preview, basic check, plans, PDF import/download, Word download and mobile layout | `wordpress-browser-final.txt` |
| Template browser runner completion | **All 47 templates passed; final isolated rerun exited successfully and cleaned up** | `builder-final.txt`; earlier timeout retained in `browser-retry.txt` |

The root `npm.ps1` command was initially blocked by Windows execution policy; `npm.cmd` completed the audit/build without changing that policy. The first browser attempt lacked bundled Chromium; reruns use installed Chrome. One public registration navigation timed out during concurrent checks, then passed on rerun. The maintained browser run printed success for all 47 templates but exceeded the runner's 300-second child-process timeout; subsequent scripts therefore did not run in that invocation. These failures are preserved and are not silently counted as passes. The remaining scanner/detector/pricing scripts subsequently passed in an isolated follow-up run, together with the injection probes. Temporary audit runners under `storage/app/` add the injection script, allow a longer child timeout and close readiness-probe HTTP connections; maintained application/test source was not edited. Early worker/WordPress readiness attempts timed out; the final WordPress browser runner passed after its readiness-probe adjustment. These local harness results are not production availability measurements.

The route inventory contains **72 routes**, including framework routes (`routes.json`). This is an inventory count, not a claim that every route/method/input combination received an independent browser test.

Final browser status: all nine maintained browser scripts completed their functional assertions across the recorded split runs. The final standalone template run exited successfully with the longer audit-runner timeout; scanner, detector, pricing and injection probes also exited successfully in their follow-up runner. This is not described as a clean single invocation of the original nine-script runner, which had the timeout recorded above.

Cleanup: the disposable MySQL concurrency database and WordPress test database were removed. Automatic approval review rejected recursive removal of the temporary WordPress site and isolated Python audit-tool environment with the message “blocked by policy.” Those folders remain at `storage/app/wordpress-test-20261111064538_6dcbb4` and `storage/app/audit-python-tools`; neither is served through the application's public document root. Test servers owned by the completed browser runners were stopped by their cleanup handlers. The pre-existing developer server was preserved.

## Deployment readiness and remaining validation

The local deployment check passed runtime minimum, debug-off, writable private storage, built assets, host allowlist, proxy restriction, database/queue schema and local document tool checks. It failed **eight production prerequisites**: production environment, canonical HTTPS URL, Secure session cookie, durable background connection, queued payment polling, recent worker heartbeat, shared Redis rate limiting and a dedicated non-root database identity. These are observations about this local development configuration. They are not proof that a separately deployed server has the same settings.

Before a production sign-off:

1. Resolve AUD-01 through AUD-05 and add desired-behavior regressions; verify session revocation using the actual configured session driver.
2. Verify deployed TLS/cookie/header behavior, public-only document root, origin access, secret storage, database privileges and supported runtimes.
3. Observe real queue/cron execution and recovery of abandoned AI reservations and payment notifications.
4. Complete a merchant-authorized Paynow test transaction and callback/poll/reversal reconciliation; confirm real OAuth and email delivery.
5. Evaluate real AI output against a labeled, profession-diverse CV/job set, including adversarial document instructions and factual-preservation checks.
6. Perform backup restoration and authorized staging load/resource tests, including untrusted document parsing, worker interruption and concurrent editing.
7. Test production WordPress theme/plugin combinations, real devices, keyboard/screen-reader behavior and representative long PDF/Word documents.

Passing tests and zero reported dependency advisories do not establish an absence of vulnerabilities. No critical exploit was confirmed in the exercised local paths. The five original findings have since been addressed as described in the linked remediation report; production validation remains separate.
