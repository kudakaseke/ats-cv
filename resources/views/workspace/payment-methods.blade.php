<x-app-layout>
    <div class="dashboard-heading"><div><h1>Choose Payment Method</h1><p>{{ $plan->name }} plan - US${{ number_format($plan->price_cents / 100, 2) }} {{ $plan->billing_interval === 'annually' ? 'per year' : 'per month' }}.</p></div><a class="text-link" href="{{ route('subscription') }}">Back to plans</a></div>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    <div class="payment-method-grid">
        <article class="panel payment-method-card">
            <div class="payment-method-heading"><x-payment-provider-icon provider="paynow" /><h2 class="sr-only">Paynow</h2></div>
            <p>One-time payment in USD. Renew manually when your access ends.</p>
            @if(config('billing.paynow.plan_change_policy') === 'end_of_cycle')<p>If you already have paid access, this purchase starts after your existing paid period. Plan changes take effect then, with no proration or immediate replacement of your current plan.</p>@endif
            <form method="POST" action="{{ route('billing.start', ['provider' => 'paynow', 'plan' => $plan->slug]) }}">
                @csrf
                @php($credentialsPresent = config('billing.paynow.integration_id') && config('billing.paynow.integration_key'))
                @if(app(\App\Http\Controllers\BillingController::class)->available('paynow'))
                    <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. This is a one-time payment with no automatic renewal.</label>
                    <button class="btn btn-outline" type="submit">Pay with Paynow{{ config('billing.paynow.mode') === 'test' ? ' (test)' : '' }}</button>
                    @if(config('billing.paynow.mode') === 'test')<p>Testing only. Complete checkout using the Paynow merchant account. Test payments do not activate a paid plan.</p>@endif
                @else
                    <button class="btn btn-outline" type="button" disabled>Paynow — {{ $credentialsPresent ? 'currently disabled' : 'not configured' }}</button>
                @endif
            </form>
        </article>
    </div>
</x-app-layout>
