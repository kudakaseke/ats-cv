<x-app-layout>
    <div class="dashboard-heading"><div><h1>Choose payment method</h1><p>{{ $plan->name }} plan - US${{ number_format($plan->price_cents / 100, 2) }} {{ $plan->billing_interval === 'annually' ? 'per year' : 'per month' }}.</p></div><a class="text-link" href="{{ route('subscription') }}">Back to plans</a></div>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    <div class="payment-method-grid">
        <article class="panel payment-method-card">
            <x-payment-provider-icon provider="paystack" />
            <h2>Paystack</h2>
            @if(config('paystack.enabled') && filled(config('paystack.secret_key')) && $plan->paystack_plan_code && $plan->paystack_amount_cents > 0 && $plan->paystack_mode === (str_starts_with((string) config('paystack.secret_key'), 'sk_live_') ? 'live' : 'test'))
                <p>{{ config('paystack.currency') }} {{ number_format($plan->paystack_amount_cents/100,2) }} {{ $plan->billing_interval==='annually' ? 'per year' : 'per month' }}. {{ config('ai_credits.plans.'.$plan->slug) }} credits released monthly during your paid term.</p>
                <form method="POST" action="{{ route('paystack.subscribe',$plan->slug) }}">@csrf<label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I authorise this recurring payment until cancelled and agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>.</label><button class="btn btn-primary">Subscribe with Paystack{{ $plan->paystack_mode==='test' ? ' (test)' : '' }}</button></form>
            @else
                <p>Paystack checkout is not available yet.</p>
                <button class="btn btn-outline" disabled>Paystack - unavailable</button>
            @endif
        </article>
                        @foreach(['paynow' => 'Paynow', 'paypal' => 'PayPal'] as $provider => $label)
                            <article class="panel payment-method-card">
                                <x-payment-provider-icon :provider="$provider" />
                                <h2>{{ $label }}</h2>
                                <p>{{ $provider === 'paypal' ? 'Automatic renewal in USD. Cancel future renewals from Subscription.' : 'One-time payment in USD. Renew manually when your access ends.' }}</p>
                            <form method="POST" action="{{ $provider === 'paypal' ? route('paypal.checkout') : route('billing.start', ['provider' => $provider, 'plan' => $plan->slug]) }}">
                                @csrf
                                @if($provider === 'paypal')<input type="hidden" name="plan" value="{{ $plan->slug }}">@endif
                                @php($credentialsPresent = $provider === 'paynow' ? (config('billing.paynow.integration_id') && config('billing.paynow.integration_key')) : (config('billing.paypal.client_id') && config('billing.paypal.client_secret')))
                                @if(config('billing.'.$provider.'.enabled') && $credentialsPresent)
                                    <label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to the <a href="{{ route('terms') }}">Terms</a> and <a href="{{ route('refund') }}">Refund Policy</a>. @if($provider === 'paypal') I authorise automatic payments of US${{ number_format($plan->price_cents / 100, 2) }} {{ $plan->billing_interval === 'annually' ? 'every year' : 'every month' }} until I cancel. @else This is a one-time payment with no automatic renewal. @endif</label>
                                    <button class="btn btn-outline" type="submit">{{ $provider === 'paypal' ? 'Subscribe with PayPal' : 'Pay with Paynow' }}{{ $provider === 'paypal' && config('billing.paypal.mode') !== 'live' ? ' (sandbox test)' : ($provider === 'paynow' && config('billing.paynow.mode') === 'test' ? ' (test)' : '') }}</button>
                                    @if($provider === 'paynow' && config('billing.paynow.mode') === 'test')<p>Testing only. Complete checkout using the Paynow merchant account. Test payments do not activate a paid plan.</p>@endif
                                @else
                                    <button class="btn btn-outline" type="button" disabled>{{ $label }} — {{ $credentialsPresent ? 'currently disabled' : 'not configured' }}</button>
                                @endif
                            </form>
                            </article>
                        @endforeach
    </div>
</x-app-layout>
