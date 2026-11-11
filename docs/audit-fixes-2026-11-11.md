# Audit remediation — 11 November 2026

The five findings in [the original audit](full-audit-2026-11-11.md) have been addressed in the local application. No production deployment, commit or push was performed.

| Finding | Resulting behavior | Verification |
| --- | --- | --- |
| AUD-01: GET mutations | Starting a new CV asks for confirmation when a draft exists; only a CSRF-protected POST discards it. GET finish displays a save form; only POST creates a saved CV. The editor submits that form after saving the latest draft. Opening a saved CV renders it without overwriting another session draft. | HTTP tests prove GET preservation, no GET creation, rejection without CSRF, POST success and quota enforcement. Real browser checks exercise confirmation, cancellation, save and discard. |
| AUD-02: residual account data | Deletion removes the user's database sessions, registered sessions in other backends and outstanding password recovery token, then deletes the account inside a transaction. A stale session for a deleted user is invalidated before a public builder page can expose its previous draft. | Tests cover database and file session erasure, recovery-token deletion, and a deleted user returning with a stale draft. Two real browser contexts verify the second browser loses its session/private draft. |
| AUD-03: non-database session revocation | Login records a password fingerprint; Laravel's session-authentication middleware validates it on web requests independently of storage. Password change, recovery and initial setup invalidate older fingerprints. Unfingerprinted legacy sessions require fresh sign-in. The active password-change session remains usable. | Separate file sessions are rejected after each of the three password flows; an unchanged session is accepted and registered. Legacy sessions are rejected. Existing database revocation tests and the two-browser password-change flow pass. |
| AUD-04: DOCX namespace handling | Extraction walks DOM elements by namespace/local name. Transitional and strict WordprocessingML, alternate prefixes and a default namespace preserve paragraphs, tabs, breaks and table-cell boundaries. Unrelated namespaces are rejected. | Prefix `w`, prefix `x`, default namespace and strict namespace fixtures preserve identical text and section boundaries; original malformed/overlong upload checks remain. |
| AUD-05: unrelated negation | Requirement matching separates independent claims before evaluating negative evidence. `SQL; no Python experience` retains SQL. Negative lists such as `No SQL or Python` remain negative. `not only` and `without supervision` do not erase positive skill evidence. | Mixed positive/negative statements and genuine negative evidence have regression coverage. Scoring method is versioned as `weighted-requirements-v6` so historical checks are not represented as recalculated results. |

## Session storage and upgrade behavior

The additive migration `2026_11_11_000001_create_account_sessions_table.php` adds a session registry containing only session ID, user ID, driver and activity time. It contains no CV text or password. The migration was applied locally; production must run its normal `php artisan migrate --force` release step before serving this version. No account, saved CV or payment data was reset.

Authenticated requests register sessions after login/session regeneration. Account deletion can therefore remove tracked file/cache/database sessions through their configured handlers. The existing credit-maintenance task prunes expired registry entries; each storage backend retains its normal expiry policy.

**Existing sessions without a password fingerprint will require sign-in again.** Remember-me cookies remain subject to the framework's password/token validation. Pre-upgrade, unregistered non-database session records cannot be enumerated per user using this new registry; they are rejected and flushed on use, and otherwise follow the backend's existing expiry/garbage-collection policy. This change does not claim to erase historical backups or remote browser cookie copies. Live Redis infrastructure was not exercised; the revocation check itself is independent of the session handler and file/database paths were verified.

## Validation

- Full backend suite: **405 tests / 2,338 assertions passed**, recorded in `storage/app/fix-2026-11-11-phpunit.txt` and its JUnit XML.
- Dedicated security/import regressions: 10 tests / 54 assertions passed, including both rejected stale file sessions and an accepted unchanged session.
- Builder/scanner JavaScript regressions: 28 checks passed.
- Production assets rebuilt successfully.
- Isolated browser run passed all four selected scripts: `audit-fixes-smoke`, `builder-save-race-smoke`, `public-auth-smoke` and `checker-smoke`.
- Browser test tooling now closes readiness-probe connections and allows a 600-second child timeout, addressing the harness failures recorded during the audit. The focused security script is included in the maintained runner and therefore its normal CI invocation.

Evidence remains under ignored `storage/app/fix-2026-11-11-*`. The browser runner removed its disposable database and owned server. The developer's existing server/database were preserved apart from the explicitly applied additive migration.

Live merchant settlement/callback delivery, OAuth, email delivery, paid AI quality, production TLS/worker configuration and backup restoration retain the validation limits documented in the original audit. No external provider charges were initiated by these checks.
