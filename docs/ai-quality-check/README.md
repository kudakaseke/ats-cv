# Fix my CV quality audit — 11 November 2026

OpenAI is being called. Runtime configuration selects OpenAI gpt-4.1. The latest stored real scan/rewrite usage records both have non-zero input and output tokens. This confirms calls were made; it does not prove the quality of any particular user's result.

The live audit used a synthetic CV and vacancy, not a real applicant. It called the application service directly without spending any user's application credits. OpenAI API usage still applies. The final rewrite used 8,652 input tokens and 2,776 output tokens, with the saved synthetic scan as its baseline. The earlier live scan used 5,298 input and 2,761 output tokens.

## What is actually sent

The application extracts readable text from PDF/DOCX/TXT uploads. It sends that full accepted text as original_resume, not the PDF's visual layout. Inputs exceeding 20,000 characters are rejected rather than silently truncated. The job description is a separate job_description field.

The request uses https://api.openai.com/v1/responses with instructions, a JSON-encoded user input and a strict JSON output schema. store is false. No API keys or authorization headers are included in these audit files. Request format was checked against the official [Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses).

The instruction text is assembled from CvAiPrompt, the competency vocabulary, resources/prompts/industry-relevance.txt, implementation rules and the rewrite task. The user input contains selected_industry, job_description, original_resume, confirmed_keywords, current_date, deterministic analysis hints and, when available, baseline_assessment. The actual source documents remain present even when a baseline is supplied.

- fix-my-cv-instructions.txt: complete, expanded rewrite instructions.
- scan-cv-instructions.txt: complete, expanded scan instructions.
- fix-my-cv-complete-prompt.md: readable rewrite instructions and exact synthetic user input.
- rewrite-request.json and scan-request.json: captured live JSON request bodies, including output schemas.
- synthetic-input.json: deliberately weak source CV and job description.
- synthetic-rewrite.json: final returned rewrite and assessment.
- synthetic-rewrite-before-quality-fix.json: first live rewrite, retained to make the quality findings reviewable.
- live-quality-report.json: final targeted checks and token usage.

Instruction exports were compared byte for byte with the instructions in the captured request bodies.

## Findings and corrections

The initial live call rewrote the summary and duties, but it also inferred per-tool experience durations and the application added duplicate plain skill names beside those annotated versions. A subsequent manual review caught wording that overstated using MySQL as designing/managing data storage, and using Git as collaborative code development.

Corrections:

1. Strengthened the source-fidelity instructions, including a final explicit check against upgrading responsibility levels or inventing per-tool proficiency/durations.
2. Removed generated skill-duration and proficiency annotations when not explicitly supplied with that skill in the source.
3. Deduplicated annotated and plain versions of the same skill while retaining explicit source qualifications such as Sage Intacct (Advanced).
4. Added regression coverage for retaining supplied annotations and rejecting inferred ones.

The agreed CV structure is unchanged. These fixes do not fabricate new experience or raise scores merely because wording improved.

## Validation and limits

- Live scan and rewrite completed against OpenAI gpt-4.1.
- Final rewrite passed 12 targeted checks, followed by manual review of the summary, duties and grouped skills.
- 175 relevant PHP tests passed, with 1,001 assertions.
- 7 JavaScript retry/operation-identity tests passed.
- Contract tests verify that the complete submitted CV and job description reach OpenAI, malformed/incomplete/refused results do not replace the CV, and failed requests refund application credits.
- Retries of the same operation intentionally reuse its completed result to prevent duplicate billing. They are not new AI calls. Changed inputs are tied to a different request identity.

A successful API response or valid JSON is not proof that every generated statement is correct. The targeted checks and synthetic fixture do not establish factual accuracy across every profession or every CV. No specific failing user operation could be reproduced from the supplied screenshot alone. Review factual claims before using a generated CV. An unchanged score can be correct when the same facts already satisfy the same requirements despite better wording.

Re-export current prompts without making paid calls:

    php scripts/audit-cv-ai.php

Explicitly rerun the synthetic live audit (incurs provider API usage):

    php scripts/audit-cv-ai.php --live
