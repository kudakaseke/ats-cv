# Kimi K3.1 Pro and GPT comparison

Both CV analysis and rewriting support `CV_AI_PROVIDER=kimi` or `openai`.
The existing GPT model setting is retained. Kimi uses the direct Moonshot API;
get a key from https://platform.kimi.ai and place it privately in `.env`:

```dotenv
CV_AI_PROVIDER=kimi
MOONSHOT_API_KEY=your_private_key
KIMI_MODEL=kimi-k3.1-pro
```

Run `php artisan config:clear` after changing these settings. Until the key is
configured, leave `CV_AI_PROVIDER=openai` to keep the app operational.
Switch back with `CV_AI_PROVIDER=openai` and clear the configuration again.

## Browser scans and rewrites

Kimi browser requests run in an encrypted database queue named `ai`. The web
request returns immediately with a private status URL; the browser polls until
the result is ready, then opens the existing results or CV builder. A pending
submission with the same user and input reuses its operation and credit
reservation. Lost polling connections resume the same operation. Kimi remains
the provider throughout; there is no automatic switch to GPT.

Keep one worker running:

```powershell
php artisan queue:work ai --queue=ai --sleep=1 --timeout=360 --tries=1
```

For hidden local Windows startup, run `scripts/start-ai-worker.ps1` using
PowerShell. Start it again after rebooting. On hosting, supervise the command
with a process manager. The worker must be restarted after code or environment
changes (`php artisan queue:restart` under a process manager). Database queue
tables must be migrated. The dedicated queue lease is 420 seconds, longer than
the 360-second worker timeout. A shared lock permits one Kimi request at a time.

Workers allow up to 300 seconds across provider attempts, within PHP's
350-second execution limit. Jobs do not automatically repeat timed-out or
otherwise ambiguous paid requests. HTTP 429 retries wait at least 61 seconds and are limited to one retry.
Jobs waiting too long in the queue fail before starting another paid request.
The scheduler or status polling recovers abandoned reservations after ten
minutes. No provider-call success or latency is guaranteed by this design.

Verification without provider spend:

```powershell
php vendor/phpunit/phpunit/phpunit --filter=BackgroundCvAiTest
node --test scripts/checker-operation.test.mjs
node scripts/background-ai-browser-smoke.mjs
```

The browser smoke uses a disposable account and completes its queued job with
mocked HTTP responses. Run that check before starting a real AI worker; no
worker should consume the fixture job before the mock completion script does.

Kimi uses strict JSON Schema, the same application prompts and validation,
and low reasoning effort to limit latency. Thinking stays enabled; the
completion budget includes 8,000 additional tokens for reasoning. Only final
content is parsed. Incomplete output and provider errors fail normally;
there is no silent fallback that could obscure comparison results.
The project permits one concurrent request and three requests per minute.
Requests share a pacing gate with at least 21 seconds between reserved starts.
HTTP 429 responses receive at most one retry after a full 61-second rate window
(or a longer numeric Retry-After). Daily-limit and quota errors are not retried.
Timeouts also establish a 61-second cooldown without replaying the request.
Safe error categories distinguish concurrency, RPM, TPM, TPD, quota and overload
in private status responses; provider messages and account identifiers stay private. Persistent rate limits return a clear message and follow
the normal credit-refund path. Provider account limits still apply.
Kimi requests share a total time budget of up to 150 seconds across retries.
When available, PHP's execution limit is extended to 180 seconds so slow
responses can finish and timeout failures can refund credits. If the limit
cannot be extended, the provider budget stays 20 seconds below that limit.

Compare identical UTF-8 text files from the command line:

```powershell
php artisan cv:compare-ai job.txt resume.txt
php artisan cv:compare-ai job.txt resume.txt --live --rewrite
```

Without `--live`, this checks configuration and inputs without making API calls.
The live command sends two analysis requests; `--rewrite` adds two rewrite
requests using each model's own baseline analysis. It uses provider billing,
does not deduct customer credits and does not create or update saved CVs.
Reports are saved under `storage/app/private/ai-comparisons/`, with validated
results, elapsed seconds and provider token usage. Reports contain CV data;
keep them private. The command does not change the app's configured provider.

Compare source accuracy (especially employers, dates and qualifications),
unsupported claims, relevance, useful recommendations, rewrite readability,
latency and token usage. A valid JSON result alone does not prove quality.
Review several representative CV/job pairs before choosing a default. Token
usage is reported; dollar costs are not estimated because cache and reasoning
billing can differ. No live comparison results are claimed by automated tests.

Official API contract: https://platform.kimi.ai/docs/guide/kimi-k3-1-pro-quickstart
