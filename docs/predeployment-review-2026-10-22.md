# Predeployment test results — 22 October 2026

Tested the running local application at `http://127.0.0.1:8000`. These results are local verification, not a production deployment or a 1,000-user capacity certificate.

## Results

| Check | Result |
| --- | --- |
| Full Laravel suite | 270 passed; 1,482 assertions. Covers application behaviour including mocked payment/AI paths, credits, security and Sentry filtering. |
| Public and authenticated pages | 66 page/viewport checks passed at desktop/mobile widths; images, payment choices, read-only account email and welcome balance verified; no browser JavaScript errors in these checks. |
| CV user journey | Passed guest onboarding, locked-template pricing redirect, free-template choice, inline edits, dates/headings, review, sign-in to save, reopening, PDF/Word downloads and Word import. |
| ATS Checker | Passed text upload, readiness results, mobile layout and navigation to job matching. |
| Job-matching/revamp interface | Passed score explanation, experience warning, missing-skill confirmation and safe handling of a mocked revamp error. Actual OpenAI endpoint was not called. |
| Malicious CV fields | 141 template/editor/review combinations escaped injected markup; no injected code executed. |
| Editor regressions | 14 tests passed. |
| Live Sentry web error | Controlled HTTP 500 through the actual Laravel web middleware, exception handler and incident reporter; safe page/reference verified; Sentry transport returned `SUCCESS`. |

Synthetic browser accounts were removed by the test scripts. No payment was charged and no paid OpenAI request was made. Automated payment/AI tests do not prove live provider callbacks, renewal delivery or provider availability on a future hosting server.

## Sentry result

The controlled web error was sent at **18:50 on 22 October 2026, Africa/Johannesburg**:

- Event: `47d913d8e83d4d10951aa4e1128d260a`
- User-visible reference: `118fa13a-005c-477c-b320-ac72ec2d16ee`
- Route tag: `diagnostics.sentry.web`
- Exception class: `App\Exceptions\SentrySetupTest`
- Message: `Application failure`

[Find this event in Sentry](https://code-dev-solutions.sentry.io/issues/?query=47d913d8e83d4d10951aa4e1128d260a). Select the correct project, all/local environments and the last 24 hours. The SDK transport confirmed successful submission; the issue has not been independently inspected through Sentry's authenticated issue API in this session. Its title/class/reference identify the deliberately generated test, not a failure found in a customer workflow.

The smoke test uses a temporary loopback-only PHP server and random probe URL, then shuts it down. No diagnostic route is added to `routes/web.php` or left running in the normal website. To repeat locally, run `node scripts/sentry-web-smoke.mjs`; it submits one synthetic Sentry event. It refuses to generate the diagnostic error outside the local environment. The raw result metadata is saved privately in `storage/app/private/sentry-web-result.json`.

Successful user actions normally do not create Sentry issues. This setup monitors backend incidents, not browser JavaScript errors, session replay or every click. Lack of issues by itself is not proof that all workflows work; use the test results alongside monitoring.

## Production setup still required

`php artisan app:deployment-check` correctly fails seven production-only checks on this development computer. Do not change the local HTTP environment to production just to make this command green. Configure and verify these on Hostinger:

1. `APP_ENV=production` with debug still disabled.
2. Canonical application URL and real traffic served over HTTPS.
3. Secure/HttpOnly session cookies working over HTTPS.
4. A durable database/Redis background connection and required queue schema.
5. Queued payment polling enabled.
6. A working scheduled bounded queue worker, confirmed by its heartbeat and completed jobs.
7. A dedicated restricted database account instead of the local root identity.

The chosen Hostinger Web/Business setup also needs the **separately hosted document service** before PDF imports and downloads can work there. No separate host exists yet. Local Python/Chrome checks passing here do not prove those tools are available on shared hosting. Follow [the document-service guide](../services/document-service/README.md), then activate remote mode and rerun the actual document flows through the hosted domain.

On the deployed host, set the Sentry production environment and exact release, rebuild configuration, verify provider callbacks and email, and repeat the browser/document/Sentry smoke checks. Verify the public document root, TLS, provider keys, backups and retention. Perform capacity testing on authorised staging infrastructure before making concurrency claims. Deployment and these production-only checks were not performed by this local test run.

## Local evidence

Logs under private application storage: `predeploy-tests.txt`, `predeploy-pages.txt`, `predeploy-journey.txt`, `predeploy-detect.txt`, `predeploy-scan-results.txt`, `predeploy-security.txt`, `predeploy-editor.txt`, `predeploy-readiness.txt`. Do not publish application storage or incident logs through the web server.
