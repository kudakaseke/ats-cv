<x-app-layout>
    <div class="dashboard-heading"><div><h1>Choose Payment Method</h1><p>{{ $plan->name }} plan - US${{ number_format($plan->price_cents / 100, 2) }} {{ $plan->billing_interval === 'annually' ? 'per year' : 'per month' }}.</p></div><a class="text-link" href="{{ route('subscription') }}">Back to plans</a></div>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    <div class="payment-method-grid">
        <article class="panel payment-method-card">
            <div class="payment-method-heading"><x-payment-provider-icon provider="paynow" /><h2 class="sr-only">Paynow</h2></div>
            <p>One-time payment in USD. Renew manually when your access ends.</p>
            <p><strong>{{ $plan->name }}: US${{ number_format($plan->price_cents / 100, 2) }} for {{ number_format(config('ai_credits.plans.'.$plan->slug)) }} credits per {{ $plan->billing_interval === 'annually' ? 'paid year' : 'paid month' }}.</strong></p>
            @if(config('billing.paynow.plan_change_policy') === 'end_of_cycle')<p>If you already have paid access, this purchase starts after your existing paid period. Plan changes take effect then. Repeat-purchase credits are added immediately and remain valid until the extended paid term ends; they are not granted again when that period starts.</p>@endif
            <form method="POST" action="{{ route('billing.start', ['provider' => 'paynow', 'plan' => $plan->slug]) }}">
                @csrf
                @php($credentialsPresent = config('billing.paynow.integration_id') && config('billing.paynow.integration_key'))
                @if(app(\App\Http\Controllers\BillingController::class)->available('paynow'))
                    @if($plan->slug === 'monthly')
                        <p>Review the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. This is a one-time payment with no automatic renewal.</p>
                        <a href="https://www.paynow.co.zw/Payment/BillPaymentLink/?q=aWQ9MjgyODQmYW1vdW50PTQuNzUmYW1vdW50X3F1YW50aXR5PTAuMDAmbD0x" target="_blank" rel="noopener noreferrer"><img src="https://www.paynow.co.zw/Content/Buttons/Medium_buttons/button_pay-now_medium.png" alt="Pay US$4.75 with Paynow" style="border:0"></a>
                        <p>This payment is not linked to your account automatically. Contact support with your payment reference to arrange access and credits.</p>
                    @else
                        <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. This is a one-time payment with no automatic renewal.</label>
                        <button class="btn btn-outline" type="submit">Pay with Paynow{{ config('billing.paynow.mode') === 'test' ? ' (test)' : '' }}</button>
                    @endif
                    @if(config('billing.paynow.mode') === 'test' && $plan->slug !== 'monthly')<p>Testing only. Complete checkout using the Paynow merchant account. {{ \App\Models\Subscription::localTestCreditsEnabled() ? 'Verified test subscriptions activate a local test plan and credits only.' : 'Test payments do not activate a paid plan.' }}</p>@endif
                @else
                    <button class="btn btn-outline" type="button" disabled>Paynow — {{ $credentialsPresent ? 'currently disabled' : 'not configured' }}</button>
                @endif
            </form>
        </article>
    </div>
</x-app-layout>
