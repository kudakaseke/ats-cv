# GPT-4.1 restoration ? 29 October 2026

Restored the application source from `79fd667`, the last saved commit before Moonshot/Kimi was introduced in `a3cc72e`. The pre-rollback code is retained at branch `backup/pre-gpt41-rollback-20261029` (`b3751b2`). Git history was preserved.

The original synchronous OpenAI Responses flow, prompts, scoring, industry selection, UI, credit handling and tests are restored. Sol-specific request settings, background AI jobs, Version 2 master-prompt additions, compact-report adapter and later wording/date scoring changes have been removed. The historical source also contains an optional Gemini integration; the active local provider is explicitly OpenAI, with no Gemini credentials configured.

The historical default was `gpt-4.1-mini`. This restoration explicitly selects **gpt-4.1**, as requested, in the local environment, configuration default, environment example and OpenAI request tests. It retains the historical 120-second provider timeout, strict JSON schema, store=false and no automatic paid retries. Documentation timeout/budget descriptions were corrected to match the restored code.

The only additional test safeguards are a synthetic forced API key/model/provider and prevention of unexpected outbound HTTP requests. These safeguards cannot affect production requests.

Database contents, payments, saved CVs, the existing OpenAI API key and the current local application URL were preserved. No migrations were rolled back. There were no reserved AI operations, queued AI jobs or running AI queue workers at restoration time. Private environment backups and diagnostics remain ignored by Git.

Verification:

- Full restored PHP suite: 335 passed, 1,910 assertions.
- Updated explicit GPT-4.1 rewrite/failure tests: 17 passed, 100 assertions.
- Builder/checker JavaScript regressions: 15 passed.
- Vite production asset build succeeded.
- Browser smoke passed: sign-in, pasted CV, mocked scan/rewrite, and real editor handoff. Both AI routes were intercepted; no provider generation was called.
- OpenAI read-only GET /v1/models/gpt-4.1: HTTP 200, id gpt-4.1, using the preserved local key. No generation requested and no billable generation test performed.

Model access verification establishes authentication and model visibility, not guaranteed completion or latency for future CV requests. Refresh the app before starting a fresh scan so the restored assets and scan flow are used.
