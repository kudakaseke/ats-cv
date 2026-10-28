# OpenAI repair verification — 29 October 2026

The active CV provider is fixed to OpenAI in config/services.php. The configured local model was checked as gpt-6.1-sol. Gemini and Moonshot request implementations, settings, comparison command and provider-specific fixtures were removed. Historical migration/audit documents describe earlier configurations and are not current setup instructions.

The direct Responses API flow keeps the 10-second connection timeout and 120-second request timeout, with no automatic paid retries. The normal configuration makes one provider request per scan/rewrite; optional staged OpenAI validation remains available but is disabled locally. A successful historical request does not guarantee that every future request will complete within the timeout.

## Corrections

- Employment dates are authoritative over model guesses. Overlaps count once. Day/month formats and PDF text joining a title to its date are recognised.
- Year-only employment dates expose minimum/maximum calendar bounds instead of asserting exact duration. Freelance and attachment periods are identified separately. Education, projects and in-progress certification dates do not count as employment.
- Year-only dates cannot confirm exact relevant experience or fully satisfy a duration threshold when the lower bound does not establish it. Calendar tenure is not full-time equivalent experience.
- The compact scan schema requests each requirement once. Laravel reconstructs profile/status/category fields and calculates scores and ordinary gap recommendations. It preserves the master prompt's factuality rules, warnings and genuine gaps.
- Wording scores compare explicit employer terminology against the same verified requirement set before and after rewriting. Unsupported credentials cannot earn wording points simply by appearing in generated text. Compatibility includes this presentation component while retaining factual evidence limits. Existing scores can change under the new scoring version.
- A completed identical scan can be reused for the same user, input hash, configured model, policy version and calendar date within 30 minutes. Reuse spends no additional credits and reports credits_used=0. Changed input is a new scan. Pending-operation protection remains in place.
- All PHP tests prevent unexpected outbound HTTP requests. Test API keys are synthetic.

## Verification and limits

No billable OpenAI calls were made for this repair verification. The supplied employment-date pattern was covered with anonymised regression fixtures. A private offline replay of the existing saved report retained all 44 requirements, rejected an exact relevant-years assertion from year-only dates and detected a small wording improvement in the previously generated CV. Private CV text and replay artifacts are excluded from Git.

The final full PHP regression suite passed 387 tests / 2,236 assertions. Builder JavaScript regression tests passed 14 tests. Configuration and compiled view caches were cleared; the local application remains listening on port 8001.

Latency improvements have not been timed with a new paid provider request. Reducing duplicated fields reduces requested work; it does not establish a guaranteed response time. Exact employment months and working hours remain necessary for an exact relevant/full-time duration.
