# Payment providers

## Local Paynow testing update

Paynow is now enabled locally with PAYNOW_MODE=test. The existing integration ID/key remain in ignored .env. APP_DEBUG is false before exposing the app through Cloudflare. Test receipts are recorded as test_paid and never create live paid access. This application setting does not change the merchant's mode at Paynow: confirm Test Mode in the Paynow dashboard before completing checkout.

Cloudflared is installed under %LOCALAPPDATA%\atscvdetector\tools. With Laravel running on port 8000, start or replace a temporary tunnel using:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/start-paynow-tunnel.ps1
```

The script prints the temporary HTTPS URL and process ID, updates only PAYNOW_CALLBACK_URL in .env and clears Laravel's configuration cache. APP_URL and Google redirect settings stay unchanged. Open the printed HTTPS URL and sign in there before starting checkout; localhost and tunnel sessions are separate. Keep the tunnel and Laravel running until callbacks/checks finish. Restarting a Quick Tunnel changes its hostname; earlier checkout callback addresses then expire. Stop a specific tunnel using the printed Stop-Process command.

In Paynow test mode, log in to the Paynow merchant account and use its testing payment options. PAYNOW_TEST_EMAIL is optional: if supplied, it must be a login email for that merchant account; otherwise authemail is omitted so the merchant can log in at checkout. Never use a random customer's email to authenticate a test transaction. Sources: [Paynow Test Mode](https://developers.paynow.co.zw/docs/paynow/test_mode/), [Cloudflare Quick Tunnels](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

For live launch, confirm USD settlement, Paynow live approval and a stable HTTPS callback address, replace the disclosed integration key, then explicitly set PAYNOW_MODE=live. Local enablement does not certify those merchant prerequisites. The older implementation notes below describe the pre-testing baseline where both providers were disabled; this update supersedes that local Paynow status.

Paynow uses one-time USD payments: US$5 buys a calendar month; US$47.40 buys a calendar year. PayPal now uses automatic recurring subscriptions at the same intervals and full-period amounts. Existing one-time PayPal records remain supported for reconciliation; new PayPal checkouts use Subscriptions v1 through srmklive/paypal 3.2.0. The package configuration is published to config/paypal.php and supports shared PAYPAL_CLIENT_ID/PAYPAL_CLIENT_SECRET or mode-specific PAYPAL_SANDBOX_* and PAYPAL_LIVE_* credentials.

## PayPal setup

1. Add PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET to the ignored .env. Start with PAYPAL_MODE=sandbox. Never send secrets to frontend code.
2. Preview: `php artisan paypal:setup-plans --dry-run`.
3. Create/reuse plans: `php artisan paypal:setup-plans`. This creates a service product and plans without a trial, setup fee or expiry. An existing product can be reused with `--product=PROD-ID`. Configured plan IDs are verified instead of modified. Copy each returned PAYPAL_MONTHLY_PLAN_ID and PAYPAL_ANNUAL_PLAN_ID into .env. Save IDs immediately; PayPal idempotency retention is limited, so do not repeatedly recreate products/plans after that window.
4. Register the public HTTPS `/paypal/webhook` webhook (the previous `/billing/paypal/notify` endpoint remains compatible) and save its PAYPAL_WEBHOOK_ID. Subscribe to BILLING.SUBSCRIPTION.ACTIVATED, UPDATED, CANCELLED, SUSPENDED, EXPIRED, PAYMENT.FAILED and PAYMENT.SALE.COMPLETED, REFUNDED, REVERSED. Use event names exactly as in PayPal's dashboard.
5. Run `php artisan config:clear`, then set PAYPAL_ENABLED=true for sandbox testing. Sandbox receipts never grant live paid access.
6. Before going live, confirm the merchant account can receive USD recurring payments, use matching live credentials, live plan IDs and a live webhook registration, and set PAYPAL_MODE=live. Plan IDs are environment-specific. No remote PayPal product, plan, customer subscription or charge was created during implementation because credentials were unavailable.

PayPal checkout validates the plan's USD price, interval, perpetual regular cycle, and absence of trial/setup/tax additions. It snapshots the plan and application reference. Activation alone never grants access. Verification queries the actual subscription transactions; only matching completed amounts create paid terms. Unique receipts and database locks prevent replay from extending a term twice. Verified renewals create new access terms. Failure or suspension does not extend access. Cancellation stops future billing while existing paid terms expire naturally. Refunds/reversals detected in transaction history revoke the associated term; older refund notifications use the receipt date to re-query that period. Provider date handling is converted to the application timezone before database storage.

Duplicate/open PayPal agreements block another checkout, including cross-provider purchases, to avoid duplicate billing. A new PayPal agreement is blocked while existing paid access remains. Users can cancel renewal on Subscription. Account deletion is blocked until an open agreement is cancelled or expired, preventing deletion of the local mapping while recurring charges continue. Cancellation failures instruct the customer to manage renewal in PayPal or contact support. An initiation timeout with no stored provider ID requires support reconciliation; it is not blindly retried as a new subscription.

Run the Laravel scheduler (`php artisan schedule:run` every minute). `billing:reconcile` runs every five minutes and recovers missed notifications. Recurring agreements continue to be reconciled after their first year; one-time payments have a 400-day reconciliation window. Each recurring refresh checks the latest successful payment period and recent transactions; webhook refund events can also target older recorded receipts. Very high transaction-volume/paginated responses require support reconciliation rather than granting unverified access. Legacy unpaid approvals with no provider reference require support investigation.

## Paynow setup

The supplied Paynow ID and key are stored only in ignored .env. Company/payment link: CodeDev Solutions / atscvdetector. Replace a disclosed key before production. Keep PAYNOW_ENABLED=false until integration 25511 is confirmed to settle USD and public HTTPS return/result URLs are reachable. The merchant integration determines currency; no invented currency conversion parameter is sent. A localhost callback requires a public development tunnel and matching APP_URL. No live Paynow transaction was created during implementation.

Paynow initiation/status messages are SHA-512 authenticated. Notifications must have valid signatures; the server independently polls only the trusted URL saved at initiation. Reference and exact amount must match before access is granted. Returning from checkout is not proof of payment. A CSRF-protected status check, callback or scheduled reconciliation can grant a verified term. Refund/dispute detection revokes its associated term. A one-time same-plan renewal adds time after existing access.

## Launch limitations

Both provider enable flags remain false. Paid feature entitlements/limits, advanced insights and priority-support operations still need completion before advertising a complete paid service. The website policies currently state that purchases are unavailable while the flags are false; update launch-status wording when enabling live payments. Recurring cancellation and paid-refund requests are separate actions. Automated refund issuance is not implemented. Confirm production billing-record retention and provider privacy notices before launch.

Sources: https://developer.paypal.com/api/subscriptions/v1/ ; https://developer.paypal.com/subscriptions/webhooks ; https://developers.paynow.co.zw/docs/paynow/initiate_transaction/ ; https://developers.paynow.co.zw/docs/paynow/generating_hash/ ; https://developers.paynow.co.zw/docs/paynow/status_update/

## Controller routes

POST /paypal/checkout accepts plan=monthly or plan=annual and billing_consent=1 from the signed-in user. GET /paypal/success and GET /paypal/cancel require the stored local payment reference and enforce ownership. A success redirect queries the existing subscription and its transactions; arbitrary redirect tokens never grant access. Closing checkout does not cancel an already-approved recurring agreement. POST /paypal/webhook uses the verified handler for recurring events. Do not register both webhook aliases in PayPal, since they serve the same handler.
