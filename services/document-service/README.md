# Separate CV document service

This service lets Laravel stay on Hostinger Web/Business while a separate Linux container host runs Python and Chromium. It extracts PDF text and produces PDF/Word files using the application's existing renderer. Word imports still use Laravel's existing importer. It does not run AI, collect payments or connect to the application database.

**Status:** prepared for separate hosting, not deployed. No hosting account has been created, purchased or connected. Laravel defaults to `CV_DOCUMENT_DRIVER=local`, so current local imports/downloads continue working. Native integration tests do not validate the Linux Docker image or a hosting provider's sandbox support.

## Choose a suitable host before activation

Use a container host that supports non-root Chromium with its Linux sandbox enabled, outbound HTTPS from Hostinger, a valid HTTPS ingress, persistent private disk for nonce records, and CPU/memory/process limits. Start with one replica and concurrency 2; measure capacity before scaling. Plan at least 2 GB RAM initially and adjust from actual rendering measurements. This is a starting configuration, not a capacity guarantee.

The host must allow Chromium's required unprivileged user namespaces and an appropriate seccomp profile. The service deliberately enables the Chromium sandbox on Linux. If launch fails, configure the host correctly; do not add `--no-sandbox`, run as root or make the container privileged. Follow [Playwright's official container guidance](https://playwright.dev/docs/docker). If the chosen platform cannot meet this requirement, use a suitable VPS or another container provider.

Review the provider's region, data-processing agreement, private disk encryption, backup retention and access controls before transferring customer CVs. This service receives personal information in uploaded PDFs or generated CV HTML, including any referee details present. No particular external provider is selected here. PyMuPDF's AGPL/commercial licensing also needs review before commercial deployment; pinning a package does not grant a commercial licence.

## Build and run

From the repository root:

```sh
docker build -f services/document-service/Dockerfile -t atscv-documents:1 .
```

The Dockerfile-specific ignore file restricts the build context to the worker and shared rendering files. It excludes `.env`, personal documents, Laravel storage, Git metadata and API credentials. Inspect your build context before using a different build tool. Docker was unavailable on the development computer, so this image still requires a real Linux build and smoke test.

Generate a **separate** 32-byte random key, represented as 64 hexadecimal characters. Store it as `DOCUMENT_SERVICE_KEY` in the host's secret manager and in Laravel's private environment. Do not reuse an OpenAI, payment or application key. Do not put it in a URL, frontend JavaScript, Git, Docker build arguments or logs. For example, generate locally with `openssl rand -hex 32` and transfer privately. The protocol uses the literal hexadecimal string as the HMAC key on both sides.

The image listens on port 3100. Expose it only through the provider's HTTPS ingress. Mount private persistent storage at `/var/lib/document-service` writable by the image's `pwuser`; retain nonce records across restarts. Multiple replicas must share atomic nonce-file creation or replay protection would be limited to one replica. Do not scale replicas without verifying this shared-storage requirement.

Required production environment:

```dotenv
DOCUMENT_SERVICE_KEY=<private 64-character hexadecimal key>
DOCUMENT_MAX_CONCURRENT=2
DOCUMENT_BIND=0.0.0.0
PORT=3100
DOCUMENT_NONCE_DIR=/var/lib/document-service/nonces
DOCUMENT_TEMP_DIR=/var/lib/document-service/tmp
DOCUMENT_BROWSER_CHANNEL=chromium
```

Apply memory/CPU/PID limits, drop unnecessary capabilities, disable privilege escalation and restrict outbound network access. Chromium request routing blocks page network requests, but this is not an OS firewall or a guarantee against a browser vulnerability. Do not mount application secrets, host directories or the Docker socket. Keep the base image, Node, PHP, Chromium and PyMuPDF patched. The image installs only the shared document writer, not the Laravel application.

TLS ingress needs a body limit of at least 8 MiB and a request timeout of at least 90 seconds. Avoid proxy body logging, APM body capture and request-body disk buffering/backups. Protect ingress against unauthenticated flooding, preferably with an allowlist of confirmed application egress addresses or equivalent private access. Do not trust arbitrary forwarded IP headers. The worker bounds document concurrency but cannot absorb unlimited incoming connections at the host edge.

## Connect Laravel only after the service is ready

In Hostinger's private Laravel environment:

```dotenv
CV_DOCUMENT_DRIVER=remote
DOCUMENT_SERVICE_URL=https://documents.your-domain.example
DOCUMENT_SERVICE_KEY=<same separate private key>
DOCUMENT_SERVICE_ALLOW_LOCAL_HTTP=false
```

Use an HTTPS origin with no URL path, credentials, query or fragment. Redirects are refused and certificate verification stays enabled. Then run `php artisan config:cache` and `php artisan app:deployment-check`. The command checks authenticated service readiness instead of requiring Python/Chrome on Hostinger. Restart existing queue workers after environment changes. Keep the frontend asset build on the Laravel host; its generated self-contained HTML is sent to the service.

Before launch test a real text PDF import, each template's PDF and Word download, multi-page output, cancellation, invalid PDFs, service outage and concurrent requests through the actual Hostinger domain. Confirm private storage and TLS. The Linux image includes Liberation fonts; exact visual parity with a Windows machine requires matching legally licensed fonts or an approved font substitution and visual verification. Reusing the renderer preserves layout logic, but different fonts/browser versions can change line wrapping.

Remote mode fails with a temporary-unavailability response when the service is down. It does not silently fall back to unsupported local binaries. Do not change to remote mode until this setup passes. To roll back on the development computer, restore `CV_DOCUMENT_DRIVER=local` and clear/rebuild configuration; local fallback is only suitable where the local tools are installed.

## Protocol and operational limits

`GET /healthz` is a basic process check. Signed `POST /v1/health` verifies Python/PyMuPDF, PHP and Chromium readiness, cached for 60 seconds. Signed `POST /v1/extract` accepts `{ "pdf": "base64 PDF" }`. Signed `POST /v1/render` accepts `{ "html": "self-contained CV HTML", "format": "pdf or word" }`. No arbitrary file paths or executable commands are accepted.

Requests use HMAC-SHA256 over `POST`, exact path, Unix timestamp, random UUID nonce and SHA256 body hash, joined by newline. Timestamp tolerance is five minutes; clocks must be synchronised. Atomically stored nonce files reject duplicates, including after a restart. A nonce is consumed even if processing is busy or fails; retries must create a new signed request. Responses authenticate `response`, request nonce, HTTP status and response body hash, also joined by newline. Laravel verifies the response before writing or using its contents.

Limits: 8 MiB JSON request, 5 MiB decoded PDF, 100 pages/20,000 extracted characters, 3 MiB HTML, 20 MiB generated output, two concurrent requests by default, bounded tool execution and no automatic Laravel retries. Overload returns 503 with `Retry-After: 10`. Invalid or unreadable PDFs return 422. Keep upstream PHP/proxy timeouts above the configured service timeout; exports remain synchronous and consume a PHP worker while running. This architecture alone does not certify 1,000 concurrent users.

Temporary document directories are private and removed after each completed/failed request. A crash may leave files; a periodic sweep removes this service's directories older than one hour while the service is running. Nonce records expire after 15 minutes. Keep temporary storage out of backups and monitor disk usage/cleanup failures. Upstream proxy buffering, provider snapshots and backups require their own retention controls. There is no application-level document archive on the worker.

Only generic worker error messages are logged. Do not enable logging of CV bodies, headers containing signatures or secret environment variables. Laravel's private incident references support failure investigation without exposing CV text. Monitor liveness, signed readiness, 5xx rate, saturation, memory, disk and cleanup failures.

## Local verification

With the existing PHP, Python and Playwright/Chrome development tools installed, from the repository root:

```sh
node --test services/document-service/server.test.mjs
php artisan test --filter=RemoteDocumentServiceTest
node scripts/document-service-smoke.mjs
```

The smoke test launches a loopback-only service using an ephemeral key, exports a synthetic CV through both paths, compares extracted PDF text/page count and Word document XML, and verifies temporary-document cleanup. It does not change `.env`, charge a card, call OpenAI or contact an external document host. Production must use HTTPS; the explicit HTTP exception only works outside production for loopback addresses.
