# OpenAI CV rewriting

The scanner accepts a job description and uploaded/pasted CV. Choosing Fix Resume sends the extracted CV text, the job description and the existing comparison suggestions to OpenAI. The Responses API returns named CV fields through a strict JSON schema; the application validates them, calculates the revised match score itself and opens the existing CV builder for review, editing and download. It does not ask the model to produce the page layout or an ATS score.

Configure the server's private `.env`:

```dotenv
CV_AI_PROVIDER=openai
OPENAI_API_KEY=your_private_api_key
OPENAI_MODEL=gpt-4.1-mini
```

Then run `php artisan config:clear`. Obtain a project API key and enable API billing in the OpenAI Platform. Do not put the key in browser code, Git, screenshots or chat. The model setting is configurable; the supplied default supports structured outputs. ChatGPT subscription access does not automatically enable ordinary API billing.

The request uses `store: false`, a 60-second timeout and a 12,000-output-token limit. No automatic retries or silent switch to another provider occur. Missing credentials, refusals, incomplete output, invalid fields and provider failures leave the existing CV untouched. Existing Gemini integration remains available only when explicitly selected with `CV_AI_PROVIDER=gemini` and its own credentials.

The prompt preserves actual experience, qualifications, contacts, dates and achievements. It improves wording, summary, supported skills and responsibility bullets using relevant job terminology. Unsupported job requirements remain separate; they are not invented as applicant experience. Applicant and job text are treated as untrusted input. A higher score or successful ATS screening is not guaranteed.

References: [Responses API quickstart](https://developers.openai.com/api/docs/quickstart), [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses), [configured model capabilities](https://developers.openai.com/api/docs/models/gpt-4.1-mini).

Verification: `php vendor/phpunit/phpunit/phpunit --filter=CvAiRewriteTest`. Tests mock the providers and cover the OpenAI request contract, schema conversion, server-calculated scores, builder handoff and failure handling. A live request requires valid API credentials and funded API access.
