# ATSDetector master prompt Version 2.0

The supplied master policy remains verbatim in `resources/prompts/atsdetector-master-v2.txt`. `CvAiPrompt` sends the compact implementation in `atsdetector-v2-runtime.txt` plus the assessment and output contracts. It no longer sends the full master alongside duplicate older policies. This reduces rewrite instruction size from 66,601 to 14,982 characters before the CV, advert and schema. The runtime contract preserves the Version 2 accuracy, recommendations, warnings, coverage and change-tracking rules, with Laravel's deterministic scoring.

## Active workflow

The active provider is OpenAI, model `gpt-6.1-sol`, reasoning effort `low`, text verbosity `low`. `OPENAI_VALIDATE_REVAMP=false` is now the default and the local setting.

- ANALYSE makes one direct Responses API request. Laravel verifies source quotes, calculates employment duration and calculates the score.
- REVAMP makes one direct Responses API request, including factual self-check, keyword coverage and detailed before/after changes in the same response. With a matching Version 2 scan, the output schema omits the duplicate full assessment: Laravel carries forward the verified assessment and original score, checks coverage and resolves only supported low-severity presentation warnings. Without a baseline, that same single request includes the assessment; no preliminary API request is made.
- Laravel checks source evidence, actual change-report quotations, requirement coverage and preserved baseline scoring. Genuine missing credentials, skills and experience remain visible in recommendations and warnings. No independent second model review is claimed.

OpenAI actions return directly to the browser and do not require an AI queue worker. The request has a 10-second connection timeout and a 120-second overall HTTP timeout. PHP receives 150 seconds where necessary so transport failures can be caught and application credits refunded. There are no automatic paid generation retries or silent Moonshot fallbacks.

`OPENAI_VALIDATE_REVAMP=true` retains the optional historical staged workflow, including its queue and additional model calls. It is disabled in the active configuration. Kimi support remains separate and inactive.

## Evidence and reports

Missing evidence is distinguished from a confirmed gap. Unsupported qualifications, certifications, skills, employers, dates and achievements must never be inserted. Only source-evidenced or explicitly confirmed current skills can be added; confirmation cannot invent historical duties or credentials. Coverage measures supported requirements, not complete employer eligibility. Scores are calculated by Laravel, and wording cannot create qualification or employment-duration points.

The builder preserves the What AI Changed report, source evidence, remaining warnings, recommendations and keyword coverage. Application-added source-evidenced or confirmed terms also receive concrete change records. Existing request identifiers prevent repeated successful submissions from triggering another charge or model request; identical in-flight input from a second tab is rejected before a second reservation or provider call.

Missing employer requirements belong in warnings/recommendations, not the factual audit's blocking issues. Each before/after/source field quotes one contiguous excerpt. `approved=true` requires `issues=[]`; unsupported claims still block a draft. Configuration, model access and exhausted quota errors direct the user to support rather than promising that waiting a minute will resolve them. Local incident diagnostics retain numeric transport timings, payload sizes and the provider request ID, without documents, secrets or raw error messages.

## Verification

`php artisan test --compact` covers direct Sol scans, a single-call Version 2 rewrite without a prior scan, report handoff into the builder, duplicate request replay, failure refunds, evidence checks and the optional staged workflow. Provider responses in these tests are mocked: they incur no model generation charges. The historical `master-v2-browser-smoke.mjs` targets the optional staged configuration and is not the default direct-flow check.
