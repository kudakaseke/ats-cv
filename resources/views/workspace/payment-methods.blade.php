<x-app-layout>
    <div class="checkout-page">
        <a class="checkout-back" href="{{ route('subscription') }}#plans"><span aria-hidden="true">&larr;</span> Back to plans</a>
        <x-input-error :messages="$errors->get('billing')" class="my-4" />
        <div class="checkout-layout">
            <section class="checkout-summary" aria-labelledby="checkout-summary-heading">
                <p class="checkout-kicker">ATS CV DETECTOR</p>
                <h1 id="checkout-summary-heading">Your {{ $plan->name }} plan</h1>
                <div class="checkout-price">${{ number_format($plan->price_cents / 100, 2) }} <span>USD</span></div>
                <p class="checkout-price-note">One-time payment &middot; {{ $plan->billing_interval === 'annually' ? '1 year' : '1 month' }} of access</p>
                <div class="checkout-order-line"><div><strong>{{ $plan->name }} plan</strong><small>{{ number_format(config('ai_credits.plans.'.$plan->slug)) }} AI credits</small></div><strong>${{ number_format($plan->price_cents / 100, 2) }}</strong></div>
                <div class="checkout-total"><span>Total due</span><strong>US${{ number_format($plan->price_cents / 100, 2) }}</strong></div>
                @if(config('billing.paynow.plan_change_policy') === 'end_of_cycle')<p class="checkout-detail">If you already have paid access, this purchase starts after your existing paid period. Repeat-purchase credits are added immediately and remain valid until the extended paid term ends.</p>@endif
            </section>
            <section class="checkout-payment" aria-labelledby="checkout-payment-heading">
                <p class="checkout-kicker">PAYMENT</p>
                <h2 id="checkout-payment-heading">Choose Payment Method</h2>
                <p class="checkout-intro">Pay securely with Paynow. Renew manually whenever you need more access.</p>
                <div class="checkout-provider"><div><strong>Paynow</strong><span>Secure payment</span></div><h2 class="sr-only">Paynow</h2><img class="checkout-paynow-logo payment-provider-paynow" src="{{ asset('images/payment-providers/paynow.svg') }}" alt="" width="88" height="19"></div>
                <form method="POST" action="{{ route('billing.start', ['provider' => 'paynow', 'plan' => $plan->slug]) }}">
                    @csrf
                    @php($credentialsPresent = config('billing.paynow.integration_id') && config('billing.paynow.integration_key'))
                    @if(app(\App\Http\Controllers\BillingController::class)->available('paynow'))
                        @if(in_array($plan->slug, ['monthly', 'pro', 'annual'], true))
                            <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. This is a one-time payment with no automatic renewal.</label>
                            <div class="checkout-paynow-button">
                                <button type="submit" style="padding:0;border:0;background:transparent;cursor:pointer"><img src="https://www.paynow.co.zw/Content/Buttons/Medium_buttons/button_pay-now_medium.png" alt="Pay US${{ number_format($plan->price_cents / 100, 2) }} with Paynow" style="border:0"></button>
                            </div>
                            <p class="checkout-notice">Credits are added after Paynow confirms your payment. If you cancel, no credits are added.</p>
                        @else
                            <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. This is a one-time payment with no automatic renewal.</label>
                            <button class="btn btn-outline" type="submit">Pay with Paynow{{ config('billing.paynow.mode') === 'test' ? ' (test)' : '' }}</button>
                        @endif
                    @else
                        <button class="btn btn-outline" type="button" disabled>Paynow — {{ $credentialsPresent ? 'currently disabled' : 'not configured' }}</button>
                    @endif
                </form>
                <p class="checkout-footer">Payments are processed by Paynow in USD.</p>
            </section>
        </div>
    </div>
    <style>
        .checkout-page{max-width:1050px;margin:12px auto 64px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#30313d}.checkout-page *{box-sizing:border-box}.checkout-back{display:inline-flex;gap:9px;align-items:center;color:#697386;font-size:14px;font-weight:500;text-decoration:none;margin-bottom:30px}.checkout-back:hover{color:#30313d}.checkout-layout{display:grid;grid-template-columns:1fr 1fr;gap:70px;align-items:start}.checkout-summary,.checkout-payment{min-width:0}.checkout-kicker{font-size:11px;letter-spacing:.1em;font-weight:700;color:#697386;margin:0 0 16px}.checkout-summary h1,.checkout-payment h2{font-size:24px;line-height:1.3;letter-spacing:-.025em;margin:0 0 14px;font-weight:650;color:#1a1f36}.checkout-price{font-size:38px;line-height:1.15;font-weight:700;letter-spacing:-.035em;color:#1a1f36}.checkout-price span{font-size:15px;font-weight:500;letter-spacing:0;color:#697386}.checkout-price-note,.checkout-intro{font-size:14px;color:#697386;line-height:1.6;margin:10px 0 30px}.checkout-order-line,.checkout-total{display:flex;justify-content:space-between;gap:20px;padding:20px 0;font-size:14px}.checkout-order-line{border-top:1px solid #e6ebf1;border-bottom:1px solid #e6ebf1}.checkout-order-line small{display:block;margin-top:6px;color:#697386;font-size:13px}.checkout-total{font-weight:600}.checkout-detail,.checkout-terms,.checkout-footer{font-size:13px;line-height:1.6;color:#697386}.checkout-payment{border:1px solid #e6ebf1;border-radius:12px;padding:32px;background:#fff;box-shadow:0 8px 28px rgba(50,50,93,.06),0 2px 5px rgba(0,0,0,.04)}.checkout-payment h2{font-size:20px}.checkout-intro{margin-bottom:22px}.checkout-provider{display:flex;align-items:center;justify-content:space-between;border:1px solid #dce2eb;border-radius:8px;padding:16px;margin-bottom:22px}.checkout-provider strong,.checkout-provider span{display:block;font-size:14px}.checkout-provider span{color:#697386;font-size:12px;margin-top:4px}.checkout-provider img,.checkout-provider svg{max-width:100px;max-height:32px}.checkout-terms a{color:#635bff;text-decoration:none}.checkout-terms a:hover{text-decoration:underline}.checkout-paynow-button{margin:22px 0}.checkout-paynow-button a{display:inline-block;line-height:0}.checkout-paynow-button a:focus-visible{outline:3px solid #635bff;outline-offset:4px}.checkout-notice{font-size:13px;line-height:1.6;color:#725b2b;background:#fff9ea;border:1px solid #f4e5bc;border-radius:8px;padding:13px 15px;margin:0}.checkout-footer{border-top:1px solid #e6ebf1;padding-top:18px;margin:22px 0 0}@media(max-width:800px){.checkout-layout{grid-template-columns:1fr;gap:30px}.checkout-page{margin-top:0}.checkout-payment{padding:24px}.checkout-price{font-size:32px}}
        body[data-workspace] .checkout-page { font-family: var(--font); }
        body[data-workspace] .checkout-page h1, body[data-workspace] .checkout-page h2, body[data-workspace] .checkout-page button { font-family: inherit; }
        body[data-workspace] .checkout-page .checkout-detail, body[data-workspace] .checkout-page .checkout-terms, body[data-workspace] .checkout-page .checkout-notice, body[data-workspace] .checkout-page .checkout-footer { font-size: 14px; line-height: 1.7; }
        body[data-workspace] .workspace-content:has(.checkout-page) { padding-top: 20px; padding-bottom: 24px; }
        .workspace-content:has(.checkout-page) > .workspace-back-row { display: none; }
        .checkout-page { margin-top: 0; margin-bottom: 24px; }
        .checkout-back { margin-bottom: 18px; }
        .checkout-payment { padding: 24px; }
        .checkout-kicker { margin-bottom: 10px; }
        .checkout-price-note, .checkout-intro { margin-bottom: 18px; }
        .checkout-order-line, .checkout-total { padding: 14px 0; }
        .checkout-provider { padding: 12px 16px; margin-bottom: 16px; }
        .checkout-paynow-button { margin: 14px 0; }
        .checkout-footer { margin-top: 14px; padding-top: 12px; }
    </style>
</x-app-layout>
