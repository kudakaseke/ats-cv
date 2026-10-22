# Web app audit — 22 October 2026

Local application review covering public pages, authentication, dashboard, CV creation/import/editing/review/export, ATS tools, template access, account settings, subscriptions, payment history and billing safeguards.

## Changes and fixes

| Finding | Resolution |
| --- | --- |
| Provider logos were followed by duplicate Paystack/Paynow headings. | Kept the supplied images and made the headings screen-reader-only. Provider order remains Paystack, then Paynow. |
| The ATS results dialog contained an invalid JavaScript expression in its percentage display. | Corrected the score fallback expression and verified the results dialog without browser errors. |
| Account deletion treated disabled or completed Paystack renewals as still active. | Added those terminal statuses to the deletion guard and removed the misleading PayPal-only error wording. Active renewals still block deletion. Added regression tests for both outcomes. |
| Home-page FAQs used old tool names and described the older wording-based score. | Updated the Job-to-CV ATS Detector/ATS Checker descriptions and explained requirements matching. |
| Contact/privacy copy said paid checkout was disabled and that the builder required sign-in to start. | Aligned the copy with available checkout methods, payment records, guest drafts, verified saving/downloading and the separate basic/AI tools. |
| Older browser scripts assumed free access to paid department templates and old tool names. | Updated the journey to verify the pricing redirect, use an included Free template and exercise the current labels. |

## Verification

| Check | Result |
| --- | --- |
| Full PHP suite | **239 passed, 1,323 assertions** |
| Editor JavaScript regressions | **14 passed** |
| Final profile/workspace/billing checks after copy updates | **32 passed, 187 assertions** |
| Public/authenticated page checks | **66 passed** across 320px, 390px and 1440px widths; successful responses, images, favicon links and no horizontal overflow or JavaScript errors |
| CV review versus PDF page fitting | **94 short/long combinations passed**, covering all 47 templates |
| Titles, descriptions and education grade | **47 templates passed** in editing and review |
| Gallery page previews | **23 standard and 11 department previews passed** at 1900px, 1366px and 390px |
| End-to-end CV journey | Guest onboarding, paid-template lock, included template, inline edits, section titles, month/year dates, review, sign-in-to-save, reopening, PDF and Word downloads, and Word re-import passed |
| ATS results browser checks | Requirement score, experience shortfall warning, mobile/desktop result display and user-confirmed skill submission passed with mocked API responses |
| Basic ATS Checker | Text upload, results and responsive display passed without an AI request |
| Dependencies | `npm audit`: **0 reported vulnerabilities**; Composer audit: **0 advisories**, **0 abandoned packages**, **0 unreachable audit sources** |
| Build | Production Vite build and Blade view compilation passed |
| Credentials | `.env` remains ignored. Targeted application/config/view/route scan found no embedded credential patterns. No secret values were included in audit output. |

The page checks cover Home, About, Features, Pricing, ATS Explained, Contact, Privacy, Terms, Refund Policy, sign-in, registration, password recovery, dashboard, settings, subscription, both ATS tools, saved CVs, saved scans and Monthly/Pro/Annual payment selection.

Billing tests exercise provider verification, payment ownership, callbacks, repeated confirmations, credit allocation/deduction/refunds, paid periods and the distinction between test and live entitlements. They use mocked provider responses rather than charging cards. Existing ownership and authentication tests remain passing.

## Current product behaviour checked

- Free includes Double Column, Elegant, Graduate, Essential, Crest, Student and Portfolio; new accounts receive 15 welcome credits and may save one CV.
- Monthly is US$4.75 with 150 monthly credits. The switch selects Pro at US$6.25 with 225 monthly credits. Annual is US$47.40 with 200 credits released monthly.
- Locked designs open Pricing while preserving the current draft. Existing saved designs remain editable after a subscription ends.
- Create CV Back returns to Dashboard after saving the draft. Change Template is shown only in the editor/review step.
- Settings email is read-only and changing it through a direct update request is rejected.
- Payment selection shows Paystack first and Paynow second, with the supplied logos and no duplicate visible provider headings.
- Payment history is scoped to the signed-in user. The audit-trail links remain absent from navigation.
- Synthetic browser-account credit balances remained at 15; the checks did not deduct AI credits or charge a payment method.

## Limits of this audit

- This is a local application audit, not a certification that every possible defect or security issue has been eliminated.
- Paystack is currently configured in **test mode**. No real card payment or actual hosted-provider settlement was submitted during this review.
- OpenAI, Google sign-in, Paynow and SMTP configuration values are present. Presence does not establish that external credentials, delivery or account permissions are valid; no live AI generation, Google OAuth completion or SMTP delivery was requested during this audit.
- The Cards section explains provider-managed card entry. An in-app saved-card listing, replacement or removal feature is not implemented.
- Screenshots and browser developer tools cannot be reliably prevented by a website. Existing gallery copy/selection restrictions do not constitute access control; paid-template selection is additionally checked on the server.

Detailed local test output and synthetic PDF/Word artefacts are under `storage/app/full-audit-2026-10-22-*` and `storage/app/system-audit/`. They are not public application pages.
