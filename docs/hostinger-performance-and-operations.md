# Hostinger deployment, performance and error reporting

Reviewed 22 October 2026. The site has not yet been deployed. Changes here improve the application; they do not certify that a future hosting plan can sustain 1,000 concurrent users.

## Hosting arrangement and remaining launch work

The requested plan is Hostinger Web/Business. Laravel can run there, but this complete application also uses **Python/PyMuPDF for PDF extraction** and **Node/Playwright/Chrome for PDF/Word rendering**. Hostinger's [supported-language documentation](https://www.hostinger.com/support/which-programming-languages-and-frameworks-are-supported-at-hostinger/) states that Python is supported on VPS hosting, not Web/Cloud plans. Node availability does not prove that Chrome and its system libraries can be installed or launched.

The chosen arrangement is Laravel on Web/Business plus a separate authenticated document-processing service. Its adapter, worker, container recipe and local tests are prepared in [the document-service guide](../services/document-service/README.md). No service host/account exists yet and no external document processing has been activated. Laravel still defaults to local mode. Before switching to remote mode, provision a compatible host, configure HTTPS and the separate private service key, review data processing, and pass the deployment checks and real import/export tests. Hostinger then needs no local Python or Chrome for these functions.

Hostinger's [resource limits](https://www.hostinger.com/support/1583532-what-to-do-if-your-hosting-plan-limits-are-reached-in-hostinger/) include CPU, memory, processes and PHP workers. One thousand signed-in users browsing with pauses is different from one thousand simultaneous AI requests or Chrome processes. Queueing smooths work but cannot create additional CPU or increase provider quotas. A shared plan cannot be promised to handle that workload without testing.

## Query work completed

| Path | Improvement |
| --- | --- |
| Legacy CV review recovery | Replaced a history query inside each matching rewrite iteration with one joined, window-ranked query. Preserves the original owner restriction, operation order and latest-20-history-per-operation boundary. Regression verifies **two queries with 20 operations**, including another account's records. Requires a database with window functions, such as supported MySQL 8+/MariaDB LTS versions. |
| Template entitlement checks | Select only distinct JSON template identifiers rather than loading every saved CV's full content. Existing owned templates still remain editable. |
| Current plan and wallet subscription | Join subscription plans into the lookup instead of an additional relation query. |
| Dashboard/checker/subscription/header | Reuse a **request-only presentation wallet snapshot**. Credit ledger mutations invalidate it. Spending, granting, reversing and allocating still lock/read the authoritative wallet; no cross-request credit cache was introduced. |
| Subscription payment confirmation | Join the purchased plan name and reuse the controller's current plan. |
| Saved CV list | Fetch only list metadata instead of full CV JSON. |
| Abandoned-operation maintenance | Process bounded batches and fetch the batch's users together rather than an outer `User::find` for every operation. Per-user transactional credit checks remain intentional. |
| Future accidental relation loads | Development lazy-loading protection enabled to expose new Eloquent N+1 problems before deployment. |

Added **10 composite indexes** for owner payment history, recurring-payment lookups, provider customer/plan lookups, reconciliation, active subscription/term searches, credit history, abandoned operations, allocation recovery and rewrite history. Applied and verified on the local MariaDB database. The migration tolerates an interrupted MySQL DDL retry. Indexes have write/storage costs; review `EXPLAIN` and slow-query logs on real production data before adding further indexes.

No claim is made that every query inside a loop is removable: payment/credit writes intentionally perform independent locked, idempotent operations. Replacing those transactions with an unsafe bulk join would risk financial correctness. Query-budget tests cover the identified hot paths; production query profiling is still required.

## Work moved out of production HTTP requests

- **Verification mail:** encrypted queued notification, sent after the account/code transaction commits. Retries are bounded; expired, replaced or already-consumed codes are not sent.
- **Password reset mail:** encrypted queued job; confirms that the account and reset token remain valid before sending.
- **Payment return/status polling:** queues a unique payment verification job and responds from stored state instead of waiting for a provider on each poll. Repeated polls deduplicate while the job is pending. Credits/access are still granted only by verified payment logic. Existing signed callbacks remain verified and can update status immediately.
- **Scheduled reconciliation:** queues individual payment checks rather than serial provider calls when queued mode is enabled.

Local development defaults to `sync` for these jobs so existing email/payment testing remains usable without a worker. Production defaults to a durable queue. Financial ledger/audit changes remain synchronous with their transaction. AI generation, PDF extraction and document generation still run as foreground operations because the current UI expects their result; this work does **not** claim they were migrated into a distributed processing system. High-volume AI/rendering would need a durable asynchronous status/download workflow and suitable worker infrastructure.

### Production queue settings

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

QUEUE_CONNECTION=database
BACKGROUND_QUEUE_CONNECTION=database
QUEUE_PAYMENT_CHECKS=true
DB_QUEUE_RETRY_AFTER=240
CACHE_STORE=database
```

Use one shared persistent cache for locks and throttles across all app/cron processes. A VPS can use Redis instead, with `REDIS_QUEUE_RETRY_AFTER=240`. Do not use an in-memory per-process array cache in production. Queue reservation lifetime must exceed the longest job timeout; payment jobs have a 180-second timeout and mail jobs 30 seconds. Enable/support CLI timeout handling on the chosen platform and test it; PHP extensions and process restrictions differ by plan.

### Hostinger cron configuration

Hostinger provides scheduled tasks on Web/Cloud plans; see [server capabilities](https://www.hostinger.com/support/which-server-capabilities-are-supported-at-hostinger/) and [cron troubleshooting](https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/). These are bounded cron runs, not an assumption that an always-running daemon is permitted.

Create two once-per-minute cron entries in hPanel, using the actual supported PHP CLI binary and private application path:

```sh
/actual/php/path /home/ACCOUNT/domains/DOMAIN/private-app/artisan schedule:run
/actual/php/path /home/ACCOUNT/domains/DOMAIN/private-app/artisan app:work
```

`app:work` obtains a shared lock, records a heartbeat and drains `mail,payments,default`. It starts no new jobs after its 45-second work budget; an in-flight job may finish later, up to its timeout. The 240-second lock prevents overlapping cron workers. It does not execute the worker during web requests. A busy queue can outgrow this bounded worker: monitor queue depth and oldest-job age, particularly because verification codes expire after ten minutes. A long queue is a capacity problem, not a reason to extend authentication-code validity indefinitely.

Configure the cron through hPanel using its documented syntax; do not expose scheduler/worker commands through public HTTP URLs or query-string secrets. Hostinger may restrict shell redirection in its cron editor. Inspect cron output and confirm the heartbeat and job completion rather than assuming a saved cron entry is running.

Use an appropriately provisioned transactional mail provider for a large signup volume. Hostinger documents low [built-in scripted-mail limits](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/); do not assume its native mail facility can send thousands of verification messages. Provider setup/quotas are separate from application queueing.

## Rate limiting

All Laravel web routes receive a broad **600 requests/minute/IP** ceiling, in addition to specific limits. Static files served by the web server/CDN are outside Laravel's limiter and need infrastructure limits. Authenticated workspace routes add **120/minute/user** and **600/minute/IP**.

| Endpoint/work | Additional limit |
| --- | --- |
| Payment return/status GET and PayPal success | 20/minute/user, 60/minute/IP |
| PDF/Word export | 5/minute/user, 20/minute/IP |
| Job-to-CV AI analysis | 6/minute/user, 30/minute/IP |
| Google OAuth redirect/callback | 20/minute/IP, 60/hour/IP |
| Account deletion and CV deletion | Existing password-operation limiter |
| Drafts, imports, login/signup, verification, password resets/changes, basic checks, rewrites, checkout and webhooks | Existing specialised limits retained, plus applicable broader ceilings |

Limits return 429 with a retry interval. Specific limits and queue deduplication protect expensive endpoints before further work starts. Consider legitimate shared networks: many users behind one corporate IP can hit an IP ceiling. Test real traffic patterns and tune deliberately; do not trust arbitrary forwarded IP headers or add a public rate-limit bypass to make a load test pass. Hostinger/CDN/WAF limits must supplement Laravel against large distributed traffic.

## Security settings on the actual host

Local PHP/Apache changes do not patch Hostinger's servers. Select a supported PHP branch with the relevant security fixes in hPanel and confirm the actual web runtime as well as CLI. Composer enforces patched version floors. Keep `APP_DEBUG=false`.

Only Laravel's `public/` files may be web-accessible. Keep `.env`, `vendor`, `storage`, application source, backups and Git metadata outside `public_html`. Have the hosting configuration serve the application's `public/` directory, or use an officially supported deployment layout that preserves the same boundary. Do not upload the entire repository into a publicly served folder and rely only on rewrites. The repository-root deny rules are defence in depth; Hostinger uses LiteSpeed, so verify actual denial behaviour there.

Use a valid HTTPS certificate, a dedicated database user, explicit extra hostnames in `TRUSTED_HOSTS`, and only actual provider-supplied reverse-proxy IPs/CIDRs in `TRUSTED_PROXIES`. Leave proxy trust empty for direct connections. Do not guess a proxy range or trust `*`. Secure cookies and HSTS are enabled for production HTTPS responses; HTTP-only local tests cannot validate the final TLS termination arrangement.

Laravel sessions and personal CV/payment responses must never be publicly cached. Cache static fingerprinted assets at the CDN. Any future full-page cache must exclude authenticated/cookie-bearing requests and account/builder/payment pages. Rotate the potentially exposed provider credentials identified in the security review before deployment.

### Launch commands and checks

Run with a verified backup and the production database credentials:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan app:deployment-check
```

Build Vite assets during a trusted build/deployment step and deploy `public/build`; do not expose a development Vite server. Keep the existing application key when moving an existing database: changing it makes encrypted records unreadable. Configure persistent scheduled work, then run the readiness check again after its heartbeat exists.

The readiness command returns failure for unsafe/missing settings, unavailable document tools or absent worker heartbeat. Running it locally is expected to fail production-only checks. It is not a benchmark or a replacement for verifying the public document root, web PHP version, TLS, backups, DNS and live provider callbacks on the actual host.

## Developer error visibility

Production exceptions are recorded in private rotating JSON logs:

`storage/logs/incidents-YYYY-MM-DD.log`

Records contain a support reference, exception class, fingerprint, route name, code location, stack locations without arguments and HTTP status for provider request exceptions. Exception messages, SQL bindings, CV text, request bodies, tokens, passwords and provider payloads are deliberately omitted. Repeated occurrences can be grouped by fingerprint. Console/background incidents receive independent references. Logs rotate with a 14-day retention policy.

Users receive a generic 500 page or JSON message. CV checker/builder failures display the available support reference, so you can correlate their report with a private incident. Existing feature-specific messages and credit-refund handling are preserved. Expected validation failures and ordinary 404s are not treated as application incidents.

Over SSH from the private application directory:

```sh
php artisan app:errors --limit=20
php artisan app:errors --date=2026-10-22 --limit=50
php artisan queue:failed
```

The incident reader bounds its file read to the last 1 MiB and validates dates. There is no public log viewer or publicly reachable developer console. Through hPanel File Manager, only an authorised hosting-account operator should access private logs. Treat `failed_jobs` and cron output as sensitive too; the scheduler prunes failed jobs older than seven days. Monitor available disk space and centralise logs before growing to multiple servers.

For a searchable dashboard, release grouping and email alerts, **Sentry is the recommended next integration**, including its browser SDK for JavaScript-only errors. See its [Laravel guide](https://docs.sentry.io/platforms/php/guides/laravel/). It has not been enabled and no incident/CV data is sent to Sentry. Set up an account/project, keep PII collection disabled, and explicitly scrub CV/job-description bodies, cookies, tokens, breadcrumbs and exception messages before enabling transmission. Configure alert recipients and browser source-map privacy deliberately. The private local reporter already works without a third-party account.

## Capacity test plan

`scripts/load-test.k6.js` defines a gradual 50 → 200 → 500 → **1,000 virtual-user** run, with realistic pauses. It requests only pages and does not spend AI credits, create payments or upload documents. Default mode exercises public pages. Authenticated mode requires distinct pre-authorised **staging-only** session Cookie strings in an external JSON file, one per virtual user; never commit them or use real customer sessions. Expired/login-redirected responses fail the checks.

Example, against your own authorised staging host:

```sh
k6 run -e ALLOW_LOAD_TEST=yes -e TARGET_URL=https://staging.YOUR-DOMAIN -e PEAK_VUS=1000 scripts/load-test.k6.js
```

For authenticated coverage add `-e SESSIONS_FILE=/private/path/load-test-sessions.json`. Store it outside public access and delete/revoke the sessions afterwards. Use distributed authorised load generators when IP throttles would otherwise dominate; 429 responses are measured as failed capacity checks, not hidden or counted as successful page loads.

Initial acceptance targets: fewer than 1% failed/throttled responses, p95 under 1.5 seconds and p99 under 3 seconds. These are proposed targets, not measured hosting results. Observe CPU/RAM/I/O/PHP workers, database slow queries and connections, queue depth/age and provider quotas during the run. Also run separately bounded, mocked-provider AI/export workloads before making a capacity claim. Read-only page throughput does not prove AI/rendering throughput. See [Grafana's performance-testing guidance](https://grafana.com/docs/k6/latest/testing-guides/automated-performance-testing/).

The 1,000-user run has **not** been executed: no Hostinger deployment or account/resource profile exists yet. Do not stress-test shared hosting without provider approval and staging isolation.

## Verification completed locally

- Applied the index migration on actual MariaDB; query-budget and owner-isolation regressions pass.
- Full PHP regression suite: **256 tests, 1,425 assertions passed**, including query-budget, queue, throttling and incident-redaction regressions.
- **16 real concurrent MySQL processes** tested credit spending: 12 funded operations, four insufficient-credit rejections, final balance zero; no provider calls.
- Real database queue smoke: token-bearing job stored encrypted and consumed by an isolated worker without sending email or draining other queues.
- **66** public/authenticated browser and viewport checks pass; **14** editor JavaScript regression tests and the production asset build pass.
- Browser verification confirms both builder and checker show safe support references for failures.

All load/queue fixtures are synthetic and cleaned up. No real payment or paid AI call was made. These checks validate the code paths and safeguards, not the capacity of an unprovisioned hosting account.
