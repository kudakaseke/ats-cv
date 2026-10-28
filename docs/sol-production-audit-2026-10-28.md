# OpenAI model integration and product-flow audit — 28 October 2026

Final result: the tested local product journey passes after the fixes below. This is an audit of the current model integration and its surrounding document, credit, report and export paths. It is not a certification of every possible CV or of an untested production hosting environment.

## Configuration verified

- Active provider and actual returned model: OpenAI / `gpt-6.1-sol`.
- One direct `/v1/responses` request per scan and per rewrite; OpenAI background/staged processing disabled.
- Reasoning effort `low`, text verbosity `low`, strict JSON Schema, `store=false`.
- Connection timeout 10 seconds; total HTTP timeout 120 seconds. PHP gets 150 seconds where necessary to allow the transport timeout to be caught and credits refunded.
- Completion ceilings remain 16,000 tokens for analysis and 18,000 for rewriting, including reasoning. These ceilings are not requested target lengths.
- No automatic generation retries, silent model substitutions, Moonshot fallback or running AI worker in the tested flow.
- The verbatim Version 2 master remains preserved. Its compact runtime implementation retains evidence, anti-fabrication, warnings, recommendations, supported keyword alignment and change tracking.
- Configuration defaults and `.env.example` now also select Sol; a fresh installation no longer silently defaults to GPT-4.1 Mini.

Sol supports the selected reasoning setting and structured output. Low verbosity reduces unnecessary output generation; it is separate from reasoning effort. See [official model documentation](https://developers.openai.com/api/docs/models/gpt-6.1-sol) and [the official deployment checklist](https://developers.openai.com/api/docs/guides/deployment-checklist).

## Problems found and corrected

| Finding | Correction and verification |
| --- | --- |
| Full master plus overlapping old policies made rewrite instructions 66,601 characters before documents/schema. | The prior fix reduced the core runtime instructions to 14,982 characters. This audit retained that compact policy and clarified the schema-specific audit contract. |
| Fix Resume generated the entire original assessment again after a successful scan. | A matching Version 2 baseline is reused on the server. The rewrite output omits that duplicate assessment and returns the CV, changes and coverage. Original requirements, evidence, genuine gaps and score remain preserved. No preliminary model request is made when a baseline is absent; that one response includes its assessment. |
| A real rewrite was rejected after OpenAI returned it: change evidence combined source excerpts/commentary rather than quoting one source passage. | Change fields now explicitly request one short contiguous original/final/source excerpt, with field-level schema descriptions. The local exact-quote checks remain active. |
| Another real response mixed non-blocking missing credentials into `issues` while declaring `approved=true`. | The contract explicitly requires `issues=[]` for approval. Missing employer requirements outside the CV belong in warnings/recommendations; unsupported claims inside the generated CV still block approval. No blanket bypass was added. |
| Identical documents submitted from two tabs with different operation UUIDs could reserve credits twice. | Pending input is checked under the existing user lock for direct requests too. The second request returns 409 before another reservation/provider call. Completed same-ID replays still return the saved result. |
| A current skill confirmed after the scan could be rejected against the original evidence snapshot. | Coverage can recognise an explicitly confirmed current skill without changing the original score. Confirmation cannot establish a qualification, certification or historical responsibility. |
| Terms added by the application were absent from the detailed AI change records. | Source-evidenced and explicitly confirmed additions now receive concrete change records; coverage is updated for those verified additions. |
| The incident log could not distinguish a slow response from a DNS/TLS/connect failure. | Added numeric cURL timings and safe request metrics: duration, instruction/input character counts, output budget, HTTP status and provider request ID. Documents, keys, headers, URLs and raw provider errors are excluded. |
| Diagnostic-cache failure could interrupt the refund path. | Refunds run before optional diagnostics. Failure-cache writes are best effort. A regression injects a cache outage and verifies the correct refund and timeout response. |
| Authentication, unavailable-model and exhausted-quota errors looked like a temporary failure resolved by retrying. | These failures are classified safely and direct the user to support. Rate limiting and timeouts remain distinct. Provider details stay private. |
| The checker advertised 5 MB DOCX uploads, but its shared importer enforced 2 MB. | The checker explicitly uses a 5 MB importer limit; the ordinary builder retains its 2 MB limit. Existing decompressed XML, entity/network and 20,000-character safeguards remain. A real browser DOCX upload over 2 MB passes. |
| The old download smoke script referenced controls removed from the inline editor. | The download entry point now uses the current editor/review/download journey and verifies generated document content. |

## Live API evidence

The final live browser audit used a disposable verified account, a **2,066-character fictional multi-section CV** and a **396-character job advert**. It exercised real browser submission, Laravel routes, database reservations, OpenAI generation, validation, saved-result replay, session handoff and the rendered builder.

| Final check | Result |
| --- | --- |
| Scan | HTTP 200, **41.98 seconds** |
| Fix Resume | HTTP 200, **33.27 seconds** |
| Actual model in both usage records | `gpt-6.1-sol` |
| Scan usage | 3,867 input / 3,165 output tokens |
| Rewrite usage | 6,516 input / 3,133 output tokens |
| Advert requirements | 14 |
| Detailed change records | 10 |
| Original score preserved | Passed |
| Missing AWS/Kubernetes requirements remain disclosed | Passed |
| Existing profession, employers, referees and absence of invented certifications | Passed |
| Builder report and source evidence visible | Passed |
| Replay scan and rewrite with same operation IDs | Saved results, no additional credit charge or provider usage row |
| Desktop and 390-pixel mobile layout | Passed; no horizontal overflow |
| Browser JavaScript errors | None |

Earlier audit attempts deliberately remain part of the record: one browser rewrite failed local evidence validation, a diagnostic rewrite failed the approval/issues contract, and another browser rewrite reached the 120-second timeout. The fixes were then tested in the final successful journey. Do not describe those earlier attempts as successful scans/rewrites.

For the timed-out audit request, DNS took about 0.064 seconds, TCP 0.077 seconds, TLS 0.552 seconds and the request was uploaded. The response body did not finish in 120 seconds. Thus that failure was not an absent API connection. A zero local token count after such a timeout means **usage was not received**, not proof that OpenAI did no work or charged nothing.

Seven live generation attempts were used during this audit, including diagnosis and retesting after changes. They used synthetic documents, not a customer's CV or purchased application credits. Provider generation charges can apply to locally rejected or timed-out work; refunding application credits does not reverse OpenAI billing. Further regressions use mocked provider responses rather than repeated paid calls.

Private evidence:

- `storage/app/private/sol-production-browser-audit.json` — final passing results and usage.
- `storage/app/private/sol-production-browser-audit-initial-failure.json` — initial evidence-validation failure.
- `storage/app/private/sol-production-browser-audit-timeout.json` — timeout before baseline reuse/low verbosity.
- `storage/app/previews/sol-production-desktop.png` and `sol-production-mobile.png` — final rendered report.

## Automated and non-generation checks

- Full PHP suite: **391 tests passed** after the final refund-path change.
- JavaScript regression suite: **18 tests passed**.
- Provider failure matrix covers invalid key, forbidden access, unavailable model, exhausted quota, rate limit, provider outage, malformed JSON, refusal, incomplete output and empty output. Failures refund once; replay does not call the provider again; private diagnostics are not exposed.
- Additional regressions cover strict Sol request parameters, 120/10-second timeout settings, actual response model logging, completion-budget bounds, one-call baseline reuse, missing evidence, false quotes, fabricated credentials, preserved score, confirmed skills, application-added terms, duplicate tabs and cache failure during refunds.
- Real browser uploads: **TXT, PDF and DOCX over 2 MB passed**, with no paid generation.
- Real browser downloads: **PDF and editable DOCX passed**; extracted document content contains the expected applicant and skills. The current download smoke entry point passes without paid generation.
- Real PDF extraction checks passed for normal documents, paths with spaces, blank/scanned-style documents, encrypted files and malformed files.
- **108 TF-IDF comparisons** match the reference scikit-learn calculation to `1e-8`.
- Credit concurrency smoke: **16 real MySQL processes**, 12 funded distinct operations, 4 insufficient-credit rejections, final balance zero; no provider calls. This tests wallet locking, not concurrent AI capacity.
- Production frontend build passed.
- Composer audit: **0 reported advisories**, 0 abandoned packages. npm audit: **0 reported vulnerabilities**.
- Final pending-operation check found no abandoned reserved operation.

Useful commands (only the first makes paid API requests):

```text
node scripts/sol-production-browser-audit.mjs --live
php artisan test --compact
node --test scripts/checker-operation.test.mjs scripts/builder-regressions.test.mjs
node scripts/checker-upload-audit.mjs
node scripts/download-smoke.mjs
node scripts/verify-ats.mjs
php scripts/credit-concurrency-smoke.php
npm.cmd run build
```

## Production limits and operational requirements

This workspace runs locally; no remote production deployment was performed or verified. Before serving production traffic, the hosting PHP/request/proxy limits must allow the application to handle its 120-second provider timeout and return a refund response. The Laravel scheduler must run the existing credit-maintenance command to recover process crashes and abandoned reservations. OpenAI access, quota and rate limits must be monitored on the actual production project.

One passing representative live journey is not a latency guarantee or a load test. The API can still queue, stall, refuse or fail; longer CVs and adverts can take more work. Inputs up to the application's 20,000-character limits were not all subjected to paid live generation. The fixed 120-second limit intentionally remains; the application must refund on failure instead of promising that every document finishes within it.

The factual audit is a model self-check plus local source, coverage and quote validation. It is not an independent second-model audit or external credential verification. Scores remain estimates computed by Laravel. Candidates must still review the final CV; 100% supported keyword coverage does not mean all employer requirements are satisfied.
