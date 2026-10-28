# Paystack local callback recovery

The 29 October 2026 return failure was caused by a stale local address: the configured hosted test-page redirects used a previous Wi-Fi IPv4 address and port 8001, while only port 8000 was running. The current network address had changed. A successful hosted-page payment was therefore not returned to the app and had no local payment record.

The affected transaction was recovered with the existing `HostedPaystackPayments::reconcile` service, using the provider's verified transaction, the verified registered account, matching merchant page/plan, amount, currency and test mode. It created one receipt and the appropriate local test subscription. No new payment or customer charge was initiated. Transaction/account details remain outside the repository.

## Starting local Paystack testing

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-paystack-local.ps1
```

This launcher:

1. Detects the current IPv4 address and uses port 8001, unless an explicit `-BaseUrl`/`-Port` is supplied.
2. Requires the local environment with debug output disabled.
3. Updates only `APP_URL` in the private `.env`, preserving credentials and AI model settings.
4. Starts a hidden Laravel server listening on the local network and verifies the configured `/login` address is reachable.
5. Synchronises the three configured Paystack **test** payment-page callback URLs and reads them back to confirm.

Open the address printed by the launcher and sign in there before checking out. Browser sessions on localhost, an old IP address or another host do not automatically carry across to the new address. Run the launcher again after a Wi-Fi/IP change or restart. The server must remain running while paying.

Callback synchronisation can also be inspected or run separately:

```text
php artisan paystack:sync-callbacks --dry-run
php artisan paystack:sync-callbacks --url=http://YOUR_CURRENT_LOCAL_IP:8001/paystack/callback
```

The command refuses live keys, validates the whole configured test-page set before updating any page, changes no price/plan/charge fields, skips already-correct URLs and verifies each saved URL. It exits unsuccessfully if Paystack ignores an update. HTTPS is required outside local testing. It does not configure the merchant's webhook settings.

## Payment recovery and production operation

An authenticated account owner can revisit `/paystack/callback?reference=PAYSTACK_REFERENCE` on the working application host. The existing callback verifies the payment with Paystack before granting access. It does not trust success query parameters or grant another account's payment. Successful replays are idempotent; do not pay again to work around a missed redirect.

Local network addresses are suitable only for browser-return testing from that network. They cannot receive Paystack's Internet-originated webhooks. Production requires a stable publicly reachable application address and the merchant webhook URL `/webhooks/paystack`, with signature validation retained. Paystack recommends webhooks for fulfilment independently of browser callbacks: [official webhook documentation](https://paystack.com/docs/payments/webhooks/). Hosted payment-page redirects are merchant-side settings, separate from the application's callback route: [official callback documentation](https://support.paystack.com/en/articles/2129538).

Verification: six callback-configuration regressions cover saved-URL verification, unchanged URLs, live-key and invalid-URL rejection, dry run, unexpected pages and ignored updates. Existing hosted-payment and payment-return tests cover verified ownership, amount/mode validation, authenticated return after login and once-only credit/subscription fulfilment. No new paid transaction was needed for recovery or verification.
