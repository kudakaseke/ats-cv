# Subscription credit check — 20 October 2026

| Plan | Paystack test amount | Credits after simulated verified success | After analysis + rewrite |
| --- | --- | --- | --- |
| Monthly | R77.95 | 150 | 135 |
| Pro | R102.56 | 225 | 210 |
| Annual | R777.83 | 150 | 135 |

`SubscriptionCreditDisplayTest` exercises checkout initiation, pending balance, successful provider verification, dashboard, credit-circle data, Subscription, audit history, repeat confirmations and usage deductions for all three plans. Provider responses in these isolated application tests are simulated. Annual releases 150 credits for the current month rather than 1,800 credits upfront.

Real Paystack test checkout sessions were also initialized from the application for all three plans using disposable accounts. Paystack accepted the plan codes, amounts and checkout initialization. Its hosted checkout displayed Cloudflare security verification, preventing automated completion. No new successful provider payment or recurring subscription was claimed from those attempts, and no real money was charged. Pending payments correctly left purchased credits at zero.

The owner's existing provider-confirmed Monthly test subscription was checked separately and still showed 150 purchased credits. No credits were spent from that account.

Paystack rejects the reserved `.invalid` domain used by ordinary browser test accounts. Payment-only browser fixtures therefore use randomized addresses at the reserved `example.com` domain. They remain disposable and separate from customer accounts.

To verify a new hosted payment fully, complete the Paystack security verification and test checkout in a normal user-controlled browser, then recheck the returned dashboard and audit history. Never grant credits from an email, pending checkout or unverified callback alone.
