# WordPress plugin verification — 2 November 2026

Release: ATS CV Detector 1.0.0. The Laravel application and the pre-existing WordPress site's files/database were not replaced. Tests used a new isolated WordPress copy and a uniquely named test database.

## Environment and results

- Actual WordPress core: 7.1.4, copied from the local installation.
- PHP CLI: 8.2.35; MariaDB: 10.4.32; plugin financial tables and core users table use InnoDB. This describes the test environment, not a production hosting recommendation; use supported production versions.
- Native WordPress/database integration suite: **84 passed, 0 failed**.
- Browser workflow: **passed** with Chrome/Playwright, including login, private REST access, autosave, saved CVs, entry editing, preview, free structure check, four plans, PDF download/import, reopening, Word download and mobile overflow checks.
- Actual concurrent PHP worker tests: passed for overspending prevention, duplicate-operation reservation and simultaneous Paynow notifications/receipts.
- Real PDF/DOCX generation and round-trip text extraction: passed.
- Package: WordPress successfully extracted the release ZIP through its normal ZIP extraction functions; plugin metadata, bundled document dependencies/licenses and exclusion of current environment credentials were checked.
- PHP syntax checks and JavaScript syntax check: passed.
- Composer dependency audit: no security vulnerability advisories reported at the time of the check.

## Important test coverage

Tests cover activation, repeatable schema setup, admin/customer capability separation, verified-email/private API access, welcome credits once, free saved-CV/template limits, encryption, revision/ownership checks, operation idempotency, insufficient balance, AI success/failure restoration, independent official Paynow hash vector, unsafe URLs, all eight documented provider statuses, duplicate/forged/mismatched notifications, poll amount mismatch, provider timeouts, test/live isolation, refund/top-up debt reversal, calendar month/year boundaries, future renewal credit timing, trace recovery, expiry without cron, scheduled expiry, maintenance heartbeat, privacy cancellation/refunds, and real concurrent financial mutations.

Provider HTTP is mocked in the integration/concurrency tests. Browser tests do not initiate checkout or AI processing. No Paynow or OpenAI charges were made. A real merchant test-mode transaction, deployed callback reachability, production mail/cron and the site's own plugin/theme combinations still need deployment validation.

## Release scope

This is an independent native WordPress application with a text-first document renderer and native evidence-requirement assessment. It is not an automatic Laravel migration or a pixel-identical/template-and-score-identical replacement. Its README describes the differences, required configuration and optional features not included in this version.
