# GPT-6.1 Sol integration audit — 28 October 2026

Result: passed for the tested AI scan workflow.

## Active model

- Provider: `openai`.
- Configured model: `gpt-6.1-sol`.
- Model returned by OpenAI in the live response: `gpt-6.1-sol`.
- Reasoning effort: `low`; existing prompts and strict JSON Schema retained.
- GPT-4.1 remains supported for switching back. No Kimi worker was running at the final check.

Usage logging now records OpenAI's returned model ID, with the configured ID as
a fallback when a response omits it. A targeted regression verifies this behavior.

## Live browser verification

One billable analysis request used a disposable test account and fictional CV
and job description. No customer account, CV or purchased credits were changed.

| Check | Result |
| --- | --- |
| Paste job description and CV through the real browser interface | Passed |
| Live analysis response | HTTP 200 in 31.26 seconds |
| Returned assessment, summary and job-specific findings | Passed |
| Results dialog displayed automatically | Passed |
| Application credits | Test account 15 → 10 |
| Replay the same operation | Same saved analysis, balance remained 10 |
| Provider usage records after replay | Exactly one completed call |
| Provider-reported model | `gpt-6.1-sol` |
| Input/output tokens | 4,551 / 1,961 |
| JavaScript errors during the live flow | None |
| Mobile width, 390px | No horizontal overflow |
| Unresolved credit reservations at final check | None |

The disposable account was removed after verification. Private machine-readable
evidence is in `storage/app/private/gpt-sol-live-audit.json`. Screenshots of the
fictional result are in `storage/app/previews/gpt-sol-live-desktop.png` and
`storage/app/previews/gpt-sol-live-mobile.png`; both were visually inspected.

## Automated checks

- Full PHP suite: **355 tests, 2,012 assertions passed**.
- After the returned-model logging change: **3 targeted model tests passed**,
  including a new test of provider-reported model logging.
- JavaScript checker and builder regressions: **18 tests passed**.
- Production frontend build: passed.
- Changed PHP syntax and whitespace checks: passed.

The full suite includes mocked analysis/rewrite contracts, refunds, ownership,
credit accounting, billing and other existing application regressions. Live
rewriting, payments and external callbacks were not exercised in this audit.
The single live sample establishes a working integration; it does not guarantee
all future provider requests or complex CVs will complete within the same time.

Repeat the one-call browser audit only when another billable test is intended:

```powershell
node scripts/gpt-sol-live-audit.mjs --live
```

Official model documentation: https://developers.openai.com/api/docs/models/gpt-6.1-sol
