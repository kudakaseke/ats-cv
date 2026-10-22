# Sentry error monitoring

The Laravel SDK is installed. It forwards the existing private incident reports to Sentry when `SENTRY_LARAVEL_DSN` is configured. Without a DSN, the existing private error logs and user error references continue working. The assistant plugin is installed separately; it is not the website SDK and does not supply a project DSN.

## Activate

1. In your Sentry organisation, create or select a **Laravel** project. Use the SDK Setup / Client Keys (DSN) screen to copy that project's DSN.
2. Add the following to the private Laravel environment. Do not paste API tokens into source code or chat, and do not commit `.env`:

   ```dotenv
   SENTRY_LARAVEL_DSN=https://<project-key>@<ingest-host>/<project-id>
   SENTRY_ENVIRONMENT=local
   SENTRY_RELEASE=
   ```

3. Run `php artisan config:clear`, then `php artisan app:sentry-test`.
4. Open the project's **Issues**, include the `local` environment in its filter, and look for `App\Exceptions\SentrySetupTest`. Its message is deliberately `Application failure`. Verify the event ID printed by the command, the `error_reference` tag and the PHP source file/line.

The test throws and catches a synthetic exception through the same incident reporter used by HTTP failures and background jobs. It sends no customer CV or payment data and does not deliberately crash a public page. A transport return alone is not proof of ingestion: confirm the issue inside Sentry. No public debug endpoint was added. Avoid the vendor's generic `sentry:test` command here: it constructs a separate SDK configuration and does not exercise this application's privacy filter.

For assistant access, finish the Sentry MCP browser authorisation and restart Codex to load the newly installed plugin/tools. This lets the assistant inspect issues after authentication. The onboarding handoff is not a replacement for the project DSN. If the login URL expires, run `codex mcp login sentry` to start a new login. Do not store OAuth credentials in the application repository.

## What is sent

The allowlist includes exception class, a generic message, relative PHP source locations and function names, a fingerprint, the application's named route, optional upstream HTTP status, environment/release and the same error reference shown to the user. Search Sentry by the `error_reference` tag when a user reports a failure. Expected validation errors and normal 404s are not deliberately turned into incidents.

Raw exception messages, SQL bindings, CV text, job descriptions, provider response bodies, request URLs/bodies/headers/cookies, user/email/IP fields, breadcrumbs, source snippets, frame arguments, attachments and arbitrary scope context are omitted. The final `before_send` filter reconstructs a clean event and rejects capture paths that did not pass through the application's incident reporter. Do not add `Integration::handles`, a raw Sentry log channel, request breadcrumbs or `captureException` calls as a shortcut: use Laravel's existing `report($exception)` path instead.

Session replay, browser JavaScript SDK, performance tracing, profiling, AI prompt monitoring, metrics and structured log streaming are not enabled by this initial backend integration. This is deliberate for a CV app with sensitive documents. Enabling additional signals needs its own data review, tests and volume budget. PHP stays configured to ignore exception arguments; no change to `zend.exception_ignore_args` is required.

If reporting fails, the original safe response and private local incident log remain available. Sentry connections have short timeouts; external reporting is synchronous and may add latency during an outage. The application does not guarantee delivery or automatically retry failed external events. The automated tests use an in-memory transport and never contact a real project.

## Hostinger production setup

Install the locked Composer dependencies on deployment and use the same project DSN (or a separate production project if you choose). Set `SENTRY_ENVIRONMENT=production`, `SENTRY_RELEASE` to the exact deployed Git commit or release name, `APP_ENV=production` and `APP_DEBUG=false`. Run `php artisan config:cache` and restart any existing queue workers. Ensure outbound HTTPS and certificate validation work on the host; never disable TLS verification to work around a hosting error.

Run `php artisan app:sentry-test` on the actual host and verify its issue under the production environment. PHP file/line/function traces are kept readable without JavaScript source maps. Browser JavaScript errors are not covered by this backend SDK. No production hosting/deployment was performed during setup.

In Sentry's project alert settings, configure a new-issue/regression notification to your own developer account and test delivery. Check plan quotas, spike protection, retention, project membership and data-scrubbing settings. SDK setup does not itself prove email alerts are configured. Restrict access to authorised maintainers and review Sentry's data-processing/retention terms before enabling production reporting.

## Verification

For a real browser/HTTP check on this development computer, run `node scripts/sentry-web-smoke.mjs`. It starts and stops a separate loopback-only server, raises one synthetic exception through Laravel's real web error handler, checks the safe error page/reference, and verifies the SDK transport result is `SUCCESS`. It adds no route to the normal website and requires the local environment with a configured DSN. This sends one real Sentry event; automated PHPUnit tests below use an in-memory transport instead.

`php artisan test --filter=SentryReportingTest` verifies the real Laravel exception pipeline, reference correlation, payload scrubbing, rejection of raw capture paths, outage handling and the synthetic test command with no external requests. The full Laravel suite should also pass before deployment.

References: [Sentry Laravel setup](https://docs.sentry.io/platforms/php/guides/laravel/), [SDK options](https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/).
