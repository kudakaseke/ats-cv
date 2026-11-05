# AI credits and verified billing

Current catalogue:

| Offer | USD price | Credits |
| --- | --- | --- |
| Free | 0 | 15 once per account |
| Monthly | 4.75 per paid month | 150 |
| Pro | 6.50 per paid month | 225 |
| Annual | 47.40 per paid year | 2,400 upfront |
| One-time top-up | 4.75 | 150 purchased credits |

Analysis costs 5 credits; a revamp costs 10. One complete workflow costs 15 credits. Manual editing and downloads use no credits. API tokens and application credits are separate: actual model usage is recorded privately in `ai_usage_logs`, while customer credit prices remain fixed.

Paynow is the only payment provider. Payments are one-time USD purchases with manual renewal. See [Paynow billing](../README-PAYNOW.md) for setup and deployment.

## Wallet and payment rules

Wallet mutations lock the user row. The welcome grant is issued once. Monthly and Pro allowances expire with their paid period; annual credits are allocated upfront and expire at the paid year's end. A prepaid renewal starts after existing paid time, and its allowance becomes available when that period starts. Purchased top-ups remain separate and are spent after subscription credits.

An operation reserves credits before requesting AI. Operation IDs and input hashes prevent duplicate charges. Completed results are encrypted and replayable; failures refund the original buckets exactly once. Expired subscription refunds do not become permanent credits. Maintenance recovers abandoned reservations after ten minutes. Actual model and token counts are recorded when the provider supplies them; crashed requests may have incomplete usage.

Payment amounts and credit counts come from server configuration. A signed callback must match the reference, expected amount and provider identity, followed by independent polling. Duplicate notifications and repeated status checks cannot refill spent credits. Test payments never grant live access or paid credits. Verified refunds revoke the associated paid term or reverse purchased credits; already-spent refunded top-ups create debt rather than reusable credit.

## Operations and audit

Run migrations on deployment, clear configuration caches, build frontend assets, run the scheduler every minute and process the configured queues. The scheduler performs payment reconciliation, credit maintenance and billing maintenance every five minutes. Do not enable live payments until merchant settings and public HTTPS callbacks have been verified.

The private `/account/activity` page shows owner-scoped payments, credit ledger and account events. Audit logging excludes passwords, API keys and CV/job contents. Historical migration definitions and payment records remain for accounting and upgrade compatibility. Provider integrations removed from the application cannot reconcile or cancel external legacy agreements.

Automated PHP and browser checks use mocked AI/provider responses and isolated test accounts. Live merchant delivery and external AI availability require separate operational verification.
