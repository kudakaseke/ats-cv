# OpenAI CV rewriting

The local app is configured for `OPENAI_MODEL=gpt-6.1-sol` with
`CV_AI_PROVIDER=openai`. For this model, requests use `reasoning.effort=low`
and the existing strict JSON Schema output. GPT-4.1 remains supported: set
`OPENAI_MODEL=gpt-4.1`, clear the configuration cache, and restart the local
development server to switch back. The existing API key and prompts are retained.
The latest integration audit includes real browser scan and rewrite requests,
with the returned model ID and token usage verified. Automated tests separately
mock provider responses. See `docs/sol-production-audit-2026-10-28.md`.

Official model documentation: https://developers.openai.com/api/docs/models/gpt-6.1-sol

The scanner accepts a job description and uploaded/pasted CV. Choosing Fix Resume sends the extracted CV text, the job description and the existing comparison suggestions to OpenAI. The Responses API returns named CV fields through a strict JSON schema; the application validates them, calculates the revised match score itself and opens the existing CV builder for review, editing and download. It does not ask the model to produce the page layout or an ATS score.

Configure the server's private `.env`:

```dotenv
CV_AI_PROVIDER=openai
OPENAI_API_KEY=your_private_api_key
OPENAI_MODEL=gpt-6.1-sol
OPENAI_VALIDATE_REVAMP=false
```

Then run `php artisan config:clear`. Obtain a project API key and enable API billing in the OpenAI Platform. Do not put the key in browser code, Git, screenshots or chat. The model setting is configurable; the supplied default supports structured outputs. ChatGPT subscription access does not automatically enable ordinary API billing.

Each action makes one direct Responses API request using `store: false`, a 120-second HTTP timeout and low reasoning effort. Output ceilings are 16,000 tokens for analysis and 18,000 for rewriting; these are ceilings, not targets. Version 2 source checks, warnings, recommendations and detailed change tracking remain active. The rewrite includes its self-check in the same response, followed by local evidence checks. No automatic retries or silent switch to another provider occur. Missing credentials, refusals, incomplete output, invalid fields and provider failures leave the existing CV untouched and refund application credits. Gemini and Kimi remain inactive unless explicitly selected.

The prompt preserves actual experience, qualifications, contacts, dates and achievements. It improves wording, summary, supported skills and responsibility bullets using relevant job terminology. Unsupported job requirements remain separate; they are not invented as applicant experience. Applicant and job text are treated as untrusted input. A higher score or successful ATS screening is not guaranteed.

References: [Responses API quickstart](https://developers.openai.com/api/docs/quickstart), [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses), [configured model capabilities](https://developers.openai.com/api/docs/models/gpt-4.1-mini).

Verification: `php vendor/phpunit/phpunit/phpunit --filter=CvAiRewriteTest`. Tests mock the providers and cover the OpenAI request contract, schema conversion, server-calculated scores, builder handoff and failure handling. A live request requires valid API credentials and funded API access.
