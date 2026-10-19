# Paystack plan setup

## Supplied hosted payment pages

Inspected on 16 October 2026 in a browser without submitting any payment information:

- Monthly: https://paystack.shop/pay/rehfdbdk33 — ATS CV Pro Monthly, ZAR 83 monthly.
- Annual: https://paystack.shop/pay/5l1ff9py-9 — ATS CV Pro Annual, ZAR 780 annually.

Both display: “This is a test page where you can see how payment pages work. Do not share with your customers.” They are therefore configured only as development-test links on the authenticated subscription page when APP_ENV is local. They are hidden in production and never advertised as live public checkout. These are existing hosted pages; no new remote plans have been created by this work.

R780 annually versus R83 × 12 = R996 saves R216, approximately 21.69%. Do not claim exactly 21% in ZAR. The 21% USD comparison is separate. The test pages alone do not verify a payment or activate an account subscription. Obtain live page URLs and implement authenticated transaction reconciliation, verified webhooks and lifecycle handling before customer launch.

The active catalogue is Free, Monthly (US$5 each month), and Annual (US$47.40 each year, displayed as US$3.95 per month). Annual saves US$12.60 / 21% against 12 monthly payments. Free is managed locally and never creates a Paystack plan.

USD prices are reference/display prices. Paystack plans are fixed ZAR charges, not dynamic USD conversions. Confirm the actual rand prices with the owner; no exchange rate has been assumed. Currency conversion and bank fees can affect the customer's actual USD cost. The 21% comparison is based on the displayed USD prices; set corresponding rand amounts if the same saving is to apply to ZAR billing.

In `.env`, configure:

```dotenv
PAYSTACK_SECRET_KEY=
PAYSTACK_MONTHLY_AMOUNT_ZAR_CENTS=
PAYSTACK_ANNUAL_AMOUNT_ZAR_CENTS=
```

The amounts are positive whole ZAR cents. Annual is the entire yearly charge, not the monthly equivalent. Do not put keys in frontend code or commit them.

```powershell
php artisan config:clear
php artisan paystack:sync-plans --dry-run
php artisan paystack:sync-plans
```

The sync command creates or reuses matching plans, stores plan codes and test/live mode, and refuses to alter an existing stored plan's amount or interval. After an interrupted request it looks up matching plans before creating another. Run one sync process at a time. Switching test/live modes replaces the locally stored codes with that mode's codes, without editing the other mode's remote plans.

This configures products, not a complete billing system. Checkout, signed and idempotent webhooks, payment verification, cancellation, renewals, payment failure handling, and entitlement enforcement must be implemented before enabling purchases. Creating a Paystack plan never grants a user paid access. No customers are subscribed or charged by this command.

Paid scan and saved-document limits are currently unspecified (`null`) and must be agreed before paid entitlement enforcement. Unlimited edits do not automatically mean unlimited AI calls. Free retains the existing three monthly analysis allowance and one saved CV in plan metadata; the current checker endpoints do not enforce those allowances. Priority support and advanced insights are advertised future paid offerings, not a claim that this change implements them. Legacy Basic/Pro records remain for existing references but are hidden from sale.

Documentation: https://paystack.com/docs/api/plan/ and https://paystack.com/docs/payments/subscriptions/
