# AI credits and verified billing

Paynow is the supported payment provider. Subscription purchases and credit top-ups are one-time payments in USD; customers renew manually. Prices and credit quantities come from the server-side plan catalogue. See README-PAYNOW.md for configuration, callback requirements and operational guidance.

Paynow notifications and status responses must be verified before a payment can grant access or credits. A browser return alone is not proof of payment. Test payments do not grant live access. Local test subscription credits are available only when explicitly enabled for the local environment.

AI credits are tracked in a wallet and ledger. Usage is reserved before an AI request, with failed requests refunded under the application's credit rules. The scheduler should run every minute for payment reconciliation and credit maintenance.

Automated billing tests use simulated provider responses; they do not make real charges. Live checkout requires verified Paynow merchant configuration and an end-to-end test.
