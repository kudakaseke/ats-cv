# Whole application QA audit — 9 November 2026

## Assessment

The eight original findings and six follow-up findings have been addressed in the local working tree. The original audit observations and artifacts are retained as historical evidence. The production-service limits at the end still apply: local regression results do not certify live Paynow, Google OAuth, email delivery or AI availability.

## Remediation - 9 November 2026

| Finding | Change | Regression evidence |
| --- | --- | --- |
| QA-001 | Added an explicit, non-mass-assignable `password_setup_required` marker, enabled only when Google creates a new account. Initial setup checks it again under a user-row lock and clears it after saving. Normal password changes and recovery clear it too. | `SetPasswordTest` covers a known legacy password with NULL `password_set_at`, direct new-account setup, repeated setup, tampered email, throttling and session invalidation. Google callback tests cover creation versus linking an existing account. |
| QA-002 | Restored By Position; All now includes department cards as well as all other categories. No applicant details are populated from gallery examples. | Catalogue-to-gallery browser check and actual selection of all 47 designs; builder JavaScript category regression. |
| QA-003 | Failed save-before-navigation offers an explicit leave-without-saving confirmation. Cancel retains the draft. Validation uses readable field names. | Browser cancel/discard and save/reopen checks; PHP validation-message regression; JavaScript navigation regression. |
| QA-004 | Mobile drawer focuses Home, contains forward/reverse Tab focus, makes the workspace inert, closes with Escape or its close button, and restores focus to the toggle. Desktop remains ordinary navigation. | Mobile keyboard browser check, including repeated Tab and Shift+Tab. |
| QA-005 | Grey section headings use `#707070` at 11px on white. | Browser asserts the actual grey color and a contrast ratio of at least 4.5:1 (measured 4.95:1). |
| QA-006 | Added Saved checks, Audit trail and admin-only Billing management links. | Browser history/activity navigation; PHP ordinary-user denial and admin-link visibility. |
| QA-007 | Replaced the three damaged strings with plain wording. | Verified corrected source wording; builder and subscription are included in the maintained page flows. |
| QA-008 | Replaced obsolete builder/scanner flows, corrected basic checker navigation and yearly pricing expectations. `npm run test:browser` now creates its own SQLite database and local server, disables external credentials, mocks scan/rewrite responses and cleans up. Added `.github/workflows/qa.yml`. | Maintained suite includes all templates, draft navigation, mobile accessibility, scanner retry/rewrite recovery, basic checks and pricing. CI configuration is added; a remote GitHub Actions run has not been observed. |

**Password migration policy:** legacy hashes cannot reliably distinguish a random placeholder from a chosen password. The new column defaults to false for existing accounts; none receive unauthenticated or session-only permission to overwrite an unknown existing password. Ambiguous old Google accounts use existing password-change/recovery protection. New Google registrations retain direct in-app initial setup, read-only email and password confirmation, with no Google redirect. Apply `php artisan migrate --force` with this release; the migration was applied locally and does not replace any existing password hash.

**Verification:** Final PHP suite: 393 tests / 2,258 assertions passed. JavaScript: 28 checks passed (21 builder and seven scanner checks). Production asset build passed. All nine maintained browser scripts passed together against an isolated backend. All 94 real PDF/Word exports across 47 templates passed. The simulated-provider MySQL concurrency audit passed with up to eight workers in 38.4 seconds. npm and Composer dependency audits reported no advisories. These checks do not establish production capacity or live-provider availability. Final evidence is recorded under ignored `storage/app/`: `qa-final-phpunit.txt`, `qa-final-js.txt`, `qa-final-build.txt`, `qa-final-browser.txt`, `qa-final-exports.txt`, `qa-final-payment-concurrency.txt`, `qa-final-npm-audit.json` and `qa-final-composer-audit.json`.

Reproduce locally after installing dependencies: `npm run build`, `php artisan test --compact`, `npm run test:builder-regressions`, `npm run test:checker-regressions`, then `npm run test:browser`. The browser suite uses bundled Playwright Chromium; install it once with `npx playwright install chromium`, or use `BROWSER_CHANNEL=chrome` for an existing Chrome installation. Unlike standalone smoke scripts, the suite always supplies its own disposable SQLite database and random local port. It refuses cached Laravel configuration so database isolation cannot silently be bypassed.

No commit, push or deployment was performed for these fixes. Other pre-existing working-tree changes were preserved.

## Follow-up review - 9 November 2026

The second review found and fixed two additional issues missed by the first remediation tests:

| Finding | Reproduction and impact | Fix and validation |
| --- | --- | --- |
| QA-009 - Low - Duplicate discard warning | After a validation error, accept the builder's explicit leave-without-saving confirmation. The browser then raises its own `beforeunload` warning: two prompts for one decision. The original tests did not count both prompts. | Explicit accepted discard now suppresses only the redundant unload warning and cancels the pending autosave timer. The new pointer/Back browser check verifies cancel retains data, normal unsaved navigation remains guarded, accepted discard prompts exactly once, and a successful save/navigation prompts zero times. |
| QA-010 - Medium - Missing tablet sidebar overlay | Open the sidebar between 851 and 900px. The drawer used a 900px breakpoint while the shared overlay styling stopped at 850px. The overlay was invisible and could not be clicked to close the drawer. | Workspace overlay styles now use the same 900px breakpoint. Browser coverage checks keyboard behavior, close button, outside-click dismissal and responsive transitions at 320, 390, 851, 900 and 901px. The close button is also cloaked until Alpine initializes. |

Coverage gaps closed:

- Public FAQ accordions, mobile public navigation, protected guest redirect, restored paid-template gate, signup validation, verification-code syntax, logout and recovery acknowledgement are now maintained browser checks, not just ad hoc audit artifacts. All fixtures use synthetic accounts and disabled email delivery in the isolated runner.
- The older documented `node scripts/browser-smoke.mjs` entry point now delegates to the maintained isolated runner instead of executing obsolete selectors and a missing `expect` import.
- Three added PHP security tests exercise the actual additive migration against an existing known Google-linked password, recovery invalidating initial-setup permission, and a current-password change invalidating that permission. The password-specific suite passes 12 tests / 79 assertions.
- MySQL concurrency audit rerun passed in 44.8 seconds: eight-worker duplicate verification, 16 independent users, duplicate checkout/top-up initialization, eight simultaneous renewals with 1,200 credits across sequential periods, and concurrent spending without an overdraft. Its disposable database was removed. This remains simulated-provider correctness coverage, not a production throughput guarantee.
- JavaScript builder regressions: 16 passed, including explicit discard state. Production assets rebuilt successfully. PHP regression suite: 391 passed / 2,241 assertions. The final seven-script browser run passed together; its disposable database and server were removed. Results are recorded in `storage/app/qa-second-pass-browser-final.txt`.

Additional evidence: `qa-second-pass-navigation.txt` contains the original two-prompt reproduction; `qa-second-pass-navigation-fixed.txt` records the one-prompt fix. `qa-second-pass-public-responsive.txt`, `qa-second-pass-password.txt`, `qa-second-pass-phpunit.txt`, `qa-second-pass-js.txt`, `qa-second-pass-build.txt` and `qa-second-pass-payment-concurrency.txt` contain the corresponding local verification output. All paths are under ignored `storage/app/`.

Live Paynow settlement and callback delivery, real Google OAuth, SMTP delivery, real paid AI output, real devices and production load testing remain outside this local validation. No production deployment or GitHub Actions run was performed.

## Third review - 9 November 2026

The additional review targeted interrupted saves and client-validation paths instead of only repeating the earlier successful flows. Two further defects were reproduced by new JavaScript regressions and fixed:

| Finding | Evidence and impact | Fix |
| --- | --- | --- |
| QA-011 - Medium - Rejected inline edits could be omitted from the draft | With a valid existing name, enter 151 characters. The client displayed an error but returned before updating the draft model; a later save could persist the previous value and clear the error. A regression demonstrated that `data.name` remained the old name. Company and school fields also had a 150-character client limit despite a 200-character server limit. | Invalid inline text is now retained in the model for correction. Server validation still rejects invalid saves. Navigation then offers the existing explicit discard choice instead of silently saving an older value. Company/school client limits now match the server. The browser test checks failed navigation preserves the overlong text, then corrects, saves and reopens it. |
| QA-012 - Medium - Navigation could follow a stale successful save | Delay a save and change the draft before its response arrives. The previous implementation returned success for the older payload and allowed finish/navigation before persisting the new payload. A native unsaved warning could intervene, but did not make the Save action persist the latest draft. A new regression reproduced premature navigation. | Finish, leave, guest continuation and guest download now wait for the current serialized draft to be saved, repeating when an older response is stale. Latest validation failures retain the draft and stop finishing. Required name is rechecked after the wait. A real-browser test pauses a draft request, edits during it, releases it, and verifies the newest value after navigation and reload with no discard prompt. |

The builder JavaScript suite now has 21 passing checks, including an emptied required name during a pending save and a stale success followed by validation failure of the newest edit. Targeted real-browser checks for overlong-edit recovery and delayed saves passed against the isolated local backend. All nine browser scripts passed together in the final run; its database and server were cleaned up. The run is recorded in `storage/app/qa-third-pass-browser-final.txt`.

Fresh backend regression result: 391 tests / 2,241 assertions passed. Production assets rebuilt successfully. Evidence is in `qa-third-pass-before-js.txt` (the two original failures), `qa-third-pass-js.txt`, `qa-third-pass-edge-browser.txt`, `qa-third-pass-save-race.txt`, `qa-third-pass-phpunit.txt` and `qa-third-pass-build.txt`, under ignored `storage/app/`. No real payment, external AI request or email delivery was triggered by these tests.

## Final review - 9 November 2026

This pass exercised ambiguous responses, malformed successes and credit-operation replay rather than only successful scanner requests. Two additional defects were fixed:

| Finding | Evidence and impact | Fix and validation |
| --- | --- | --- |
| QA-013 - High - Ambiguous scanner failures discarded the retry identifier | Any HTTP 503 previously cleared operation identifiers, including a proxy error after an operation may already have completed. A retry could therefore start a separate billable operation. | Keep the original identifier unless the backend confirms a failed, refunded operation and echoes the matching identifier. Reset only that feature. Backend failed-operation replay returns this confirmation without another provider call or refund. PHP and JavaScript regressions cover confirmed failure, ambiguous HTML/JSON failures and mismatched identifiers. The browser retries ambiguous and malformed responses with one operation identifier. |
| QA-014 - Medium - Malformed or incomplete successful responses were accepted | A malformed JSON success or incomplete scan/rewrite payload could clear retry state, produce invalid results or submit an empty rewritten CV. | Validate JSON response type and required scan/rewrite fields before changing state or submitting a draft. Invalid responses retain the retry identifier and inputs, show an error and restore controls. JavaScript regressions cover malformed JSON and incomplete scan/rewrite payloads; the browser exercises malformed-response recovery. |

Final verification passed: 393 PHP tests / 2,258 assertions, 28 JavaScript checks, all nine browser scripts, 94 real document exports, the simulated-provider MySQL concurrency audit, production asset build and dependency advisory checks. The checker regressions are also included in the added CI workflow; no remote GitHub Actions run has been observed.

The initial browser run timed out on the rewrite navigation assertion while other test suites were running concurrently. Its five-second navigation wait was increased to 15 seconds; the focused scanner recheck and subsequent complete browser suite both passed. This is recorded as a harness timing adjustment, not an application redirect fix. Evidence: `qa-final-browser-initial.txt`, `qa-final-checker-recheck.txt` and `qa-final-browser.txt`. Scanner regression evidence before and after the fixes is in `qa-final-before-retry-js.txt`, `qa-final-credit-targeted.txt` and `qa-final-js.txt`, all under ignored `storage/app/`.

The browser runner removed its disposable database and owned server. No real payments, paid AI requests, OAuth exchanges or email deliveries were exercised. No commit, push or deployment was performed. Live service verification, real-device testing and production load testing remain outstanding; passing local tests cannot guarantee an absence of defects.

## Original audit context

The original audit tested the local working tree at `7c67f19`, one commit ahead of the last fetched `origin/main`, with additional uncommitted UI/CV changes. The historical counts below describe that audit, before remediation.

## Coverage and evidence

| Area | Work completed | Result |
| --- | --- | --- |
| PHP regression suite | 384 tests, 2,206 assertions | Passed |
| Builder JavaScript regression suite | 14 checks | Passed |
| Public/authenticated rendering | 78 page/account/viewport combinations; widths 320, 390 and 1,440 pixels | No horizontal document overflow, broken images or uncaught browser JavaScript errors observed in that crawl |
| Control inventory | 2,286 DOM control instances, including 1,476 visible instances, across the rendering crawl | Recorded in CSV; counts include repetitions across viewports, not unique buttons clicked |
| Standard template gallery | Every exposed standard template selection/change action: 23 designs | Passed |
| Department gallery | All 11 department template actions, first role selection in each, role-list expansion; 248 role options enumerated | Passed for exercised options; not every role permutation was individually selected |
| Editor controls | Inline fields, headings, dates/Present, template switching, add-section actions, review/edit, zoom, save, reopen | Passed for exercised flows |
| Document downloads | 47 configured templates × PDF and Word = 94 real exports | All passed content-marker and single-page/XML checks for the audit fixture |
| Full CV journey | Guest start, paid-template gate, free selection, edit, sign in to save, reopen, PDF/Word download, Word reimport | Passed |
| Saved CV deletion | Cancel then confirm deletion on a temporary account | Passed |
| Account controls | Read-only email, name save, wrong-password rejection, delete-modal cancellation, direct first-password setup | Passed, subject to QA-001 below |
| Public controls | Seven FAQ accordions, mobile menu, registration validation, verification-code syntax, logout, recovery acknowledgement | Passed |
| Scanner controls | Missing-input errors, text/oversize/unsupported upload handling, basic readiness results, simulated scan success/error, close/reopen, rewrite-error recovery | Passed for exercised flows |
| Subscription controls | Removed Cards/top-up sections, correct Monthly/Pro toggle destinations, annual total disclosure, plan display | Passed |
| Access/security checks | Guest protected-route redirects, non-admin billing denial, invalid callback rejection, CSRF rejection; full suite includes ownership and payment replay/refund tests | Passed for tested cases |
| Payment concurrency | Real disposable MySQL database, up to eight simultaneous PHP workers, signed simulated provider responses | Passed duplicate verification/checkout/top-up, separate-user fulfillment, sequential renewals and no wallet overdraft |

Testing used temporary users and an isolated MySQL database on a separate local server. Payment, AI, OAuth credentials and external email delivery were disabled there. No money moved and no existing customer payment, wallet or account records were adjusted.

## Original findings (before remediation)

### QA-001 — High — Migrated Google-linked accounts can bypass the current-password check

**Location:** `database/migrations/2026_11_09_000001_add_password_set_at_to_users.php:12`, `app/Http/Controllers/Auth/SetPasswordController.php:26`.

The migration adds a nullable `password_set_at` field and backfills only users without a Google ID. A pre-existing Google-linked account that already had a user-chosen password is therefore indistinguishable from one with a generated placeholder password. Both receive NULL. The setup endpoint accepts a replacement password for any signed-in, verified Google-linked user with that marker.

**Reproduce:** Use a legacy Google-linked account with a known existing password and a NULL newly introduced marker. Sign in, open `/set-password`, enter a replacement and confirm it. The replacement is accepted without the existing password. This was reproduced with an isolated fixture representing that exact migration state; no customer passwords were changed.

**Impact:** The normal current-password protection can be bypassed once for affected legacy accounts. Someone holding an authenticated session could make a permanent password change. This is not a cross-account or unauthenticated takeover finding.

**Expected:** Direct initial setup should remain available for genuinely password-unset users, as requested. Accounts that already established a password should use the ordinary password-change protection.

**Recommendation:** Resolve legacy account state reliably rather than inferring password absence from Google linkage. Adopt a conservative migration policy for ambiguous records, preserve an explicit setup marker for new Google registrations, and add a regression test covering an existing Google-linked password before migration.

**Evidence:** `storage/app/qa-legacy-proof.json`, `storage/app/qa-evidence/legacy-google-password.png`.

### QA-002 — Medium — Thirteen advertised templates cannot be selected through the gallery

**Location:** `resources/js/cv-builder.js:217`, `resources/js/cv-builder.js:309`, `resources/views/builder.blade.php:60`, `config/cv_templates.php:28`.

The gallery/Features page advertises 47 designs. Its exposed controls provide 23 standard designs plus 11 department designs: 34 in total. Thirteen configured `By Position` designs are excluded from `templateCategories`, and the previous position selection UI is absent. The “All” view also contains only the standard category groups.

**Reproduce:** Sign in with an active Pro account. Open the template gallery, inspect all category buttons and By Department, and attempt to locate Position Classic, Position Academic or Software Developer. No selection action is exposed for these configured designs.

**Impact:** Paid users cannot access all advertised templates through normal navigation. Those templates still export successfully when addressed by the backend.

**Recommendation:** Restore a visible category/selection path for every sold template, or deliberately retire those designs and reconcile catalogue counts and marketing text. Add a catalogue-to-visible-selector completeness check.

**Evidence:** `storage/app/qa-gallery-audit.json` records all 23 standard selections and all 11 department actions; all 94 backend exports passed.

### QA-003 — Medium — Invalid draft data prevents leaving the builder through its Dashboard/Back controls

**Location:** `resources/js/cv-builder.js:378`, `app/Http/Controllers/CvBuilderController.php:123`.

Builder navigation waits for a successful save. If any field fails validation, the save returns false and navigation does not happen. There is no visible discard/leave-without-saving choice in this state.

**Reproduce:** Choose Essential, enter `not-an-email` in the inline email field, leave that field and click the builder's Dashboard link. The browser remains in the builder and displays `The data.email field must be a valid email address.` The Back control shares the same save-before-leave path.

**Impact:** Users cannot use these navigation controls until they correct the draft, even when they want to abandon it. The exposed validation message also uses an internal `data.email` field name.

**Recommendation:** Keep validation on saving, but offer an explicit discard/leave option after a failed save. Use user-facing validation attribute names. Preserve draft data when the user chooses to stay.

**Evidence:** `storage/app/qa-extra-audit.json`, `storage/app/qa-evidence/invalid-draft-navigation.png`.

### QA-004 — Medium — The mobile sidebar does not receive keyboard focus when opened

**Location:** `resources/views/layouts/app.blade.php:17`, `resources/views/layouts/app.blade.php:45`.

**Reproduce:** At 390 pixels wide, focus Toggle sidebar, press Enter, then Tab. Observed focus moves to the credit badge, account header and dashboard cards, all outside the opened navigation. It does not move to Home or another sidebar link. The background remains keyboard-focusable behind the overlay.

**Impact:** The open menu is difficult to use with a keyboard, and focus can move into visually obscured content. Mouse navigation and Escape closing work.

**Recommendation:** On mobile opening, focus the first navigation action; contain focus while the drawer overlays the page, make background content inert, and return focus to the toggle on closing. Keep desktop navigation behavior unchanged. This recommendation follows the [W3C focus-order guidance](https://www.w3.org/WAI/WCAG22/Understanding/focus-order.html).

**Evidence:** `storage/app/qa-extra-audit.json` records the five actual focus targets; screenshot `storage/app/qa-evidence/mobile-menu-keyboard.png`.

### QA-005 — Medium — Sidebar section headings have insufficient text contrast

**Location:** `resources/css/workspace.css:32`.

Overview, CV Analysis, CV Toolkit and Account use 10px text in `#858b96` on white. The measured color contrast is approximately **3.43:1**. Normal text needs at least 4.5:1 under [WCAG 2.2 contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

**Impact:** Section labels are harder to read for people with low vision. This measured finding is not a claim that the rest of the application passes a complete accessibility audit.

**Recommendation:** Darken these labels to meet the required ratio and recheck active, hover, error and disabled states separately.

**Evidence:** Computed foreground, background and font size are recorded in `storage/app/qa-extra-audit.json`.

### QA-006 — Medium — Saved history, audit trail and billing administration are not discoverable in navigation

**Location:** `resources/views/layouts/app.blade.php:22`, `resources/views/livewire/dashboard.blade.php`.

The routes `/workspace/history` and `/account/activity` render correctly, but no links to them were found in the visible controls inventoried across the public and authenticated pages. The current sidebar likewise has no admin-only billing management entry, although `/admin/billing` works for an admin and correctly rejects an ordinary user.

**Reproduce:** Sign in and inspect the sidebar, Home cards, Subscription and account settings. Try to find Saved checks or the account audit trail. For billing management, repeat as an admin. These pages currently require knowing or bookmarking their URLs.

**Impact:** Users cannot readily find saved scan results or investigate account/credit activity, and administrators cannot discover their billing tools.

**Recommendation:** Add Saved checks under CV Analysis, Audit trail under Account, and an admin-only Billing management link. Retain existing server-side authorization.

**Evidence:** `storage/app/qa-control-inventory.csv`, explicit missing-link assertions in `storage/app/qa-final-confirmations.json`.

### QA-007 — Low — Visible punctuation is corrupted

**Location:** `resources/views/builder.blade.php:40`, `resources/views/builder.blade.php:44`, `resources/views/workspace/subscription.blade.php:28`.

Confirmed literal strings include `We?ll transfer`, `Reading your document?` and `the paid term?s end`. These are in the source and interface, not merely terminal display of valid Unicode.

**Impact:** Upload instructions and credit-expiry text look unfinished and are less clear.

**Recommendation:** Replace with correctly encoded punctuation or plain wording, then check the rendered pages and exported text.

### QA-008 — Medium, QA tooling — Several existing browser scripts target obsolete UI

Examples:

- `scripts/builder-smoke.mjs` expects 22 templates and an earlier builder flow.
- `scripts/checker-smoke.mjs` expects a Log in label, an earlier score popup and consent controls absent from the current scanner.
- `scripts/detect-smoke.mjs` expects the previous dashboard card class/order.
- `scripts/pricing-switch-smoke.mjs` expects 200 annual-plan credits rather than the current upfront yearly allowance.

**Impact:** Running the existing browser commands can produce false failures or stop before testing the intended functionality. A passing PHP suite alone does not prove current browser coverage.

**Recommendation:** Update browser checks against current requirements, use stable selectors, and run a maintained end-to-end suite in CI with deterministic provider mocks and temporary data. Validate catalogue and route coverage explicitly.

During this audit, additional harness mistakes were corrected rather than called product bugs: the Monthly/Pro card intentionally changes its ID, the current registration button is Create account, and the recovery acknowledgement is visible text without a `status` role. Initial waits on external font loading also caused local browser timeouts; functional reruns with font requests blocked passed. The raw JSON marks superseded selector failures separately.

## Limits and release conditions

- Real Google OAuth, live Paynow settlement/redirect/webhook delivery, production email delivery and paid AI output were **not** exercised. Their credentials were disabled in the isolated browser environment. Mocked backend tests and simulated browser responses do not establish live-service availability or the quality of generated CV recommendations.
- Payment correctness was tested on real MySQL with eight workers; this is not a production throughput ceiling or a DDoS penetration test. Production workers, scheduler, shared rate-limit storage, public callback DNS/TLS and monitoring still require deployment checks.
- Desktop Chrome with mobile viewport emulation was used. Real-device Safari/Firefox, assistive-technology use and a complete accessibility conformance review remain untested.
- Short audit CV fixtures were exported across every configured template. This does not prove that all possible long CVs, languages, scanned PDFs, page counts or unusual source documents convert correctly.
- Shared handlers were exercised across template/category/department controls and key valid/invalid flows. The inventory is not a claim that every dynamic control instance, role option, browser combination and failure permutation was individually clicked.
- Commit and test the exact intended release tree before deployment. Current local uncommitted changes and the unpushed commit mean GitHub/deployment may differ from the build inspected here.
- Ship the security migration with the reviewed code and retain the maintained regression suite as a release check. Live-service and production-capacity checks remain deployment requirements.

## Audit artifacts

All detailed runtime artifacts are under ignored `storage/app/`; they contain synthetic QA account information, not customer records:

- `qa-control-inventory.csv` — per-page controls, visible/hidden, href/action, field and disabled/read-only metadata.
- `qa-audit-routes.json` — Laravel route inventory.
- `qa-browser-audit.json` — 78 page/viewport checks and initial interaction results.
- `qa-gallery-audit.json` — gallery filters, 23 standard templates, 11 departments and editor actions.
- `qa-extra-audit.json` — keyboard, contrast, invalid-draft and scanner-error evidence.
- `qa-final-confirmations.json`, `qa-public-auth.json` — corrected selectors and account/public checks.
- `qa-legacy-proof.json` — legacy Google-linked password replacement reproduction.
- `qa-audit-phpunit.txt`, `qa-audit-js-tests.txt` — complete regression outputs.
- `qa-export-audit.txt` — all 94 real export results.
- `qa-builder-journey-result.txt`, `qa-workspace-actions-result.txt` — complete CV journey and deletion results.
- `qa-payment-concurrency.txt` — MySQL concurrency results.
- `qa-evidence/` — screenshots for reproduced UI/account cases.

The isolated test server was stopped and its database removed after evidence collection. No commit, push or production deployment was performed for this audit.
