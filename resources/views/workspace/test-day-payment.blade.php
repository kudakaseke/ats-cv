<x-app-layout>
    <div class="dashboard-heading"><div><h1>Checkout</h1><p>One-day test plan</p></div><a class="text-link" href="{{ route('subscription') }}#plans">Back to plans</a></div>
    <div class="day-checkout">
        <section class="panel day-checkout-summary" aria-labelledby="order-summary-heading">
            <p class="day-checkout-eyebrow">ORDER SUMMARY</p>
            <h2 id="order-summary-heading">One-day test plan</h2>
            <p>One day of access and 15 AI credits</p>
            <div class="day-checkout-total"><span>Total due</span><strong>US$0.10</strong></div>
            <p>One-time payment. No automatic renewal.</p>
        </section>
        <section class="panel day-checkout-payment" aria-labelledby="payment-heading">
            <h2 id="payment-heading">Payment method</h2>
            <p>Choose your payment method on Paynow's secure checkout.</p>
            <div class="day-checkout-methods" aria-label="Supported payment methods">
                <div class="day-checkout-method"><span>Card</span><span class="day-checkout-brands"><span class="day-checkout-visa">VISA</span><span class="day-checkout-mastercard"><span aria-hidden="true"><i></i><i></i></span> Mastercard</span></span></div>
                <div class="day-checkout-method"><span>Mobile money</span><strong class="day-checkout-ecocash">EcoCash</strong></div>
            </div>
            <p>Card details and mobile-money authorization are handled by Paynow. Available methods depend on your Paynow configuration.</p>
            <x-input-error :messages="$errors->get('billing')" class="my-4" />
            @if(config('billing.paynow.enabled') && config('billing.paynow.mode') === 'test' && config('billing.paynow.day_site.integration_id') && config('billing.paynow.day_site.integration_key') && config('billing.paynow.day_site.test_email') && config('billing.paynow.local_test_credits'))
                <form method="POST" action="{{ route('billing.start', ['provider' => 'paynow', 'plan' => 'day-test']) }}">
                    @csrf
                    <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>.</label>
                    <button class="btn btn-primary day-checkout-submit" type="submit">Continue to Paynow &middot; US$0.10</button>
                </form>
                <p>Test mode: no real payment is made. Credits are added only after Paynow confirms the test payment.</p>
            @else
                <p role="status">The one-day checkout is unavailable until the Paynow test integration ID, key and merchant test email are configured and local test credits are enabled. No payment or credits are created.</p>
            @endif
            <p>If no payment is made, no credits are added.</p>
        </section>
    </div>
    <style>
        .day-checkout{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);gap:24px;max-width:1050px;margin:24px auto}.day-checkout .panel{padding:28px}.day-checkout-eyebrow{font-size:12px;font-weight:700;letter-spacing:.1em}.day-checkout-total{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:20px 0;margin:24px 0}.day-checkout-total strong{font-size:24px}.day-checkout-methods{display:grid;gap:10px;margin:20px 0}.day-checkout-method{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #d8e0eb;border-radius:10px;padding:16px}.day-checkout-brands{display:flex;align-items:center;gap:12px;font-size:13px}.day-checkout-visa{color:#1434cb;font-weight:900;font-style:italic;font-size:18px}.day-checkout-mastercard{display:flex;align-items:center;gap:5px;font-weight:600}.day-checkout-mastercard i{display:inline-block;width:16px;height:16px;background:#eb001b;border-radius:50%}.day-checkout-mastercard i+i{background:#f79e1b;margin-left:-6px;opacity:.85}.day-checkout-ecocash{color:#0067ad}.day-checkout-submit{display:block;width:100%;margin-top:18px}.day-checkout-payment p{line-height:1.6}@media(max-width:700px){.day-checkout{grid-template-columns:1fr}.day-checkout .panel{padding:20px}}
    </style>
</x-app-layout>
