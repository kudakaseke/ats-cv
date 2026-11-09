# Payment provider

Paynow is the only supported checkout and verification provider. See [Paynow billing](../README-PAYNOW.md) for configuration, callbacks, deployment, reconciliation, receipts and manual renewals.

All prices are USD: Monthly $4.75, Pro $6.50, Annual $47.40, and a one-time 150-credit top-up $4.75. Test payments never activate a paid plan or spendable credits.

The public signed callback is `POST /billing/paynow/notify/{reference}`. Customer checkout and status checks require authentication and ownership. A browser return never proves payment; fulfillment requires verified provider status and matching payment details.

Historical migration definitions remain for upgrade compatibility; the November 8 cleanup migrations archive old provider data in encrypted generic accounting records and drop their dedicated tables and unused gateway columns. Removed providers have no checkout, callback, cancellation, SDK or configuration paths in the running application. Removing local integrations does not cancel any agreement already held by an external provider; outstanding legacy agreements require merchant review.

Run the scheduler every minute and process the payments and billing queues. Live checkout still requires confirmed USD settlement, working public HTTPS callbacks and merchant testing. Automated checks make no real charges.
