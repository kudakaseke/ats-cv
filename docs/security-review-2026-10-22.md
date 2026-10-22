# Security review and PHP update — 22 October 2026

Scope: this local Windows/XAMPP application and its checked-out source. The owner confirmed that it is not deployed. No remote production server was scanned, patched or certified. Tests used synthetic accounts and mocked payment/AI requests; no real card charges or paid AI requests were made. This is a bounded security assessment, not proof that every possible attack has been eliminated.

## Critical PHP advisory and installed fix

The [PHP advisory dated 21 October](https://www.php.net/archive/2026.php#2026-10-21-5) identifies unauthenticated remote code execution risks in request handling: CVE-2026-109618 and CVE-2026-109619. Application middleware cannot replace a runtime update because affected paths can execute before application code.

Installed PHP **8.2.35**, replacing **8.2.12**, using the matching x64 Thread Safe VS16 build from the [official Windows release manifest](https://downloads.php.net/~windows/releases/releases.json). This preserves the existing PHP branch and Apache module ABI. Package:

`php-8.2.35-Win32-vs16-x64.zip`

SHA-256 verified before extraction/installation:

`cfa6000be835931961e40740bd3b51111700061d6655ddbfe5a2125807947457`

Verified the new version in CLI and through Apache's `apache2handler` SAPI using a temporary version-only probe, removed immediately afterwards. Both web workers and the development server were restarted. Standard configured extensions loaded successfully, and Composer platform requirements passed.

The download, original PHP directory, original Apache configuration and database configuration backup are outside the web root at `C:\xampp\security-updates\2026-10-22`. Backups are rollback material, not safe runtimes for continued use. Do not publicly serve that directory or roll back to the vulnerable PHP release.

Composer now requires patched minimum versions for supported PHP 8.2–8.5 branches. PHP 8.2 security support ends on 31 December 2026; choose a longer-supported patched branch for production. See [PHP supported versions](https://www.php.net/supported-versions.php).

## Confirmed findings and remediation

| Finding | Evidence and action |
| --- | --- |
| Critical: vulnerable PHP | Updated 8.2.12 to 8.2.35; verified Apache and CLI after restart. |
| Critical: project secrets and logs accessible over Apache | Before changes, `/ats-cv/.env`, `/ats-cv/composer.json` and `/ats-cv/storage/logs/laravel.log` returned HTTP 200. Body contents were not printed or copied into this report. Root `.htaccess` now denies non-public paths; those probes and `.git/config` now return 403. Actual historical external access has not been established. |
| Network exposure of development services | Apache listened on all interfaces at ports 80/443; MariaDB listened on all interfaces at 3306. All now bind to 127.0.0.1. The Laravel development listener remains 127.0.0.1:8000. Database restart was graceful; no schema or user records were changed. |
| Direct CGI entry points | Local Apache configuration now denies `/php-cgi/` and `/cgi-bin/`; probes return 403. Laravel uses the Apache module, not these CGI entry points. |
| Detailed public errors and runtime disclosure | Local `APP_DEBUG=false`; PHP display/startup errors and `expose_php` disabled. PHP logging remains available privately. The example environment now defaults to debug off. |
| Host-header poisoning | Early exact-host allowlist rejects unknown hosts before routing or URL generation. `TRUSTED_HOSTS` supports explicit additional deployment hostnames. Forged host probes return 400. |
| Unnecessary proxy trust | Removed automatic trust of loopback forwarded headers. Only explicitly configured `TRUSTED_PROXIES` addresses are trusted; forwarded Host is not trusted. Rotating spoofed forwarded IPs cannot evade the registration IP limit in regression tests. |
| Browser and session protections | Added a limited CSP for base URLs, embedded objects, framing and form targets, plus device-permission restrictions. Production HTTPS responses include HSTS. Session cookies default to Secure in production; HttpOnly and SameSite defaults remain. This CSP is not a strict script policy and does not replace output escaping. |
| Brute-force surface | Account deletion now shares the existing password-operation throttle. Existing login, signup, verification, password reset, import, scan, rewrite and checkout limits remain. |
| Request and upload exposure | Apache body limit 8 MiB, field/header limits, PHP post limit 8 MiB and upload limit 5 MiB. Existing builder import limit remains 2 MiB. Upload-progress tracking disabled before request parsing; URL includes remain disabled. TRACE disabled. |

Machine-specific Apache settings are in `C:\xampp\apache\conf\extra\httpd-ats-local-security.conf`, included from `httpd.conf`. Existing configs were backed up first. Loopback binding intentionally prevents LAN access; use an explicitly configured, secured deployment rather than reopening XAMPP to the network.

## Attack-path review

- **Authentication:** session regeneration, password hashing, verified-account gates, OAuth state validation, verified Google identity checks, password reset response privacy and existing brute-force limits reviewed.
- **Authorisation:** resume, analysis and payment ownership checks reviewed and exercised by the regression suite. Client-supplied IDs do not authorise another user's documents or payments.
- **Payments/credits:** unsigned Paystack notifications rejected without provider requests or credit grants. Existing provider verification, amount/currency checks and idempotent credit/reversal tests pass. Return URLs alone do not prove payment.
- **CSRF:** enabled real CSRF validation in a targeted test; draft and login submissions without tokens return 419. Provider callback exemptions remain protected by provider signature verification.
- **Uploads:** executable content disguised as DOCX, DOCX external-entity payloads and excessive uncompressed document XML rejected. Existing MIME, extension, file-size and character limits retained; uploaded archives are not extracted into public storage. PDF extraction runs in a timed subprocess. No intentionally destructive runtime exploit was executed.
- **Stored/reflected markup:** malicious content in CV names, contact fields, section headings, skills, jobs, education and custom sections tested across 47 templates in gallery/editor/review rendering. All 141 combinations escaped injected markup without executing it.
- **SQL/commands/SSRF:** reviewed application query construction and subprocess invocation. Relevant queries use the query builder/bindings; document tools use argument arrays rather than user-built shell commands. Payment destinations are provider-controlled or allowlisted; Paynow polling rejects arbitrary hosts. Export browser network requests are blocked.
- **Dependency checks:** Composer and npm vulnerability audits returned no advisories at the time checked. This does not prove an absence of undisclosed vulnerabilities. Python runtime packages are audited separately; see verification results below.

## Required before any public deployment

1. **Do not publish this XAMPP stack.** Apache is 2.4.58 and MariaDB is 10.4.32. Apache has [subsequent security fixes](https://httpd.apache.org/security/vulnerabilities_24.html); MariaDB 10.4 is [out of community maintenance](https://mariadb.org/about/). They are isolated locally, not represented as patched. Use maintained web-server/database packages on the hosting platform. A database major-version upgrade needs a separate verified backup, migration and restore test; it was not attempted as an in-place overwrite.
2. **Replace potentially exposed credentials.** The earlier Apache access to `.env` creates an exposure risk, and API keys were previously supplied in chat. Rotate OpenAI, payment-provider, mail and OAuth secrets in their respective accounts before deployment. Review provider usage and logs. We cannot revoke or replace provider credentials without access to those accounts. No compromise is claimed or ruled out by this review. Do not print secrets or commit `.env`.
3. **Use a dedicated database account.** The local XAMPP root account permits passwordless loopback administration. It is now network-isolated but remains inappropriate for production. Use a strong, unique runtime account with only necessary database privileges; separate privileged migration credentials. Do not expose the database publicly.
4. **Configure production explicitly:** `APP_ENV=production`, `APP_DEBUG=false`, canonical HTTPS `APP_URL`, explicit `TRUSTED_HOSTS`, `SESSION_SECURE_COOKIE=true`, and only actual load-balancer/reverse-proxy IPs in `TRUSTED_PROXIES`. Do not use `*` proxy trust. Configure any tunnel hostname explicitly before local callback tests. Local HTTP cannot demonstrate production TLS protection.
5. **Web root must be `public/`.** Never serve the repository root. The new root `.htaccess` is defence in depth for Apache; Nginx and other servers do not read it. Keep uploads, exports, backups, logs, `.env`, source control and dependencies outside public access. Public storage must never execute PHP.
6. **HTTPS and infrastructure:** valid certificates, restricted firewall ports, a maintained reverse proxy/CDN, request/time/connection limits and monitored rate limiting. Application throttles alone cannot stop a distributed denial-of-service attack. Restrict administrative interfaces and hosting access; use MFA for provider/hosting accounts.
7. **Operations:** deploy from a trusted checkout with lockfiles, run dependency audits regularly, keep runtime/OS/browser/PDF-parser updates current, configure a shared persistent rate-limit store, run scheduler/queue maintenance, monitor payment and security failures, and test encrypted backups/restoration.
8. **Re-test the deployed environment.** Verify secure cookies, redirects, TLS, proxy/host rules, sensitive-file denial, webhook signatures, document ownership, uploads and downloads on the actual server. Local tests cannot certify a hosting configuration that does not yet exist.

## Verification

- Final full PHP suite after the runtime upgrade and hardening: **248 tests, 1,366 assertions passed**, including **9 targeted threat tests, 43 assertions**.
- Browser page audit: **66** public/authenticated page and viewport checks passed, no JavaScript errors, 15 synthetic welcome credits unchanged.
- Malicious CV rendering: **141** template/editor/review combinations passed, no injected code executed.
- Complete synthetic browser journey passed after the runtime update and database isolation: onboarding, template access restrictions, inline editing, dates, review, sign-in/save, reopening, PDF/Word downloads and DOCX reimport. No provider or AI charges made.
- Sensitive Apache files, source-control paths and CGI probes: **403**; legitimate public entry point: **200**; forged Host header: **400**.
- CLI/Apache PHP version: **8.2.35**; configured extensions and Composer platform checks passed.
- Composer/npm audits: **0 reported vulnerabilities** at audit time.
- Python audit of all **9** installed runtime-environment packages: **0 reported vulnerabilities**. Audit tooling was installed in a separate environment outside the project/web root. No runtime Python packages were changed.
- PHP formatting and Git whitespace checks passed. PHP binary/config backups and synthetic audit outputs are outside tracked application files.

Generated logs and synthetic test files remain in ignored `storage/app/` paths. No secrets, PHP binary archives, runtime backups or personal CVs are included in this report or staged for Git.
