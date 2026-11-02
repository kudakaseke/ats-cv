# Experience flags and compatibility scoring ? 30 October 2026

Two defects were corrected without changing the active GPT-4.1 model or adding AI calls.

## Experience

The scan interface previously styled every advertised experience requirement as an orange warning, including fulfilled requirements. It now uses explicit positive, shortfall and unconfirmed states. Confirmed relevant experience meeting or exceeding the advertised minimum is an Experience strength. Total tenure alone does not establish role relevance. The locally detected job minimum is retained when the model leaves its required-years value empty.

The analysis/rewrite prompt explicitly treats extra relevant years as a strength. Server normalisation removes experience warnings solely about excess years or overqualification when the assessed relevant duration meets the minimum and is consistent with total tenure. Warnings about unrelated duties, missing credentials, unclear dates or explicit employer maximums remain. The policy applies to current and cached/saved assessments. Review strengths include the experience result.

## Compatibility

The active industry-aware score path previously returned the evidence score directly and ignored wording. It now includes measured wording and readable-text structure:

compatibility = evidence * (0.8 + 0.15 * wording/100 + 0.05 * structure/100)

This keeps current relevance-aware compatibility at or below its factual role-evidence score. Better presentation cannot create missing professional evidence or eliminate eligibility warnings. With evidence fixed at 40 and structure at 50, wording 20 -> 80 raises compatibility 34.20 -> 37.80. With evidence fixed at 60 and wording at 50, structure 0 -> 100 raises compatibility 52.50 -> 55.50. Identical inputs retain identical scores; zero relevant evidence cannot be raised by presentation alone.

Structure uses the existing basic readable-text, contact, section-heading and date checks on the actual original and rewritten text. This is not an inspection of the original PDF/Word visual layout. A purely cosmetic change or an already-readable CV does not automatically earn extra points.

Historical assessments without role-relevance classifications retain their legacy additive interpretation. When the original text/structure metadata is unavailable, no structure score is invented; the available presentation weighting uses wording only. New scans persist structure metadata, rewrite responses include both stages, and the review recomputes from the same original/revised text. Cached results and saved views use the same scoring service. The scoring/comparison versions were advanced to refresh existing review calculations without another AI request.

The wording-contribution explanation now uses the actual calculation with original evidence held fixed rather than a hard-coded gain multiplier. Existing qualification, certification and experience evidence scores remain unchanged by wording alone.

Validation: full PHP suite 343 passed / 1,970 assertions. Regressions cover above/equal/below-minimum relevant experience, unrelated total tenure, benign vs genuine warnings, saved positive rendering, monotonic wording/structure improvement, zero-evidence protection, scan/rewrite/review/cached consistency, preserved gaps and the requested six-section review layout. Browser smoke checks use mocked provider responses; no billable generation requests were made.
