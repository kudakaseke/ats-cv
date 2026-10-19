<x-app-layout>
    <div class="dashboard-heading"><div><h1>Subscription</h1><p>Your current plan is {{ $currentPlan->name }}.@if($remaining !== null) {{ $remaining }} analyses remain this month.@endif</p></div><span class="pill">{{ (config('billing.paynow.enabled') && config('billing.paynow.mode') === 'live') || (config('billing.paypal.enabled') && config('billing.paypal.mode') === 'live') ? 'Payment options' : 'No live payments' }}</span></div>
    <div class="panel subscription-info"><x-icon name="info" /><p>Paynow purchases are one-time payments. PayPal subscriptions renew automatically each month or year until cancelled. Prices for these methods are in USD. A method becomes available when its merchant setup is complete. Choose a payment method from your preferred plan. Paystack test checkout uses ZAR and does not activate access.</p></div>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    @if(session('billing_status'))<p class="my-4" role="status">{{ session('billing_status') }}</p>@endif
    @foreach($paypalSubscriptions as $agreement)
        <section class="panel workspace-settings-panel my-4">
            <h2>PayPal automatic renewal @if($agreement->mode === 'sandbox')(sandbox test)@endif</h2>
            <p>US${{ number_format($agreement->amount_cents / 100, 2) }} {{ $agreement->billing_interval === 'annually' ? 'per year' : 'per month' }} · {{ $agreement->recurring_status ?? 'Setup pending' }}</p>
            <p><a class="text-link" href="{{ route('billing.show', $agreement->reference) }}">Check subscription status</a></p>
            @unless(in_array($agreement->recurring_status, ['CANCELLED', 'EXPIRED', 'SETUP_FAILED'], true))
                <form method="POST" action="{{ route('billing.paypal.cancel', $agreement->reference) }}">@csrf<button class="btn btn-outline mt-4" type="submit">Cancel automatic renewal</button></form>
                <p class="mt-3">Cancellation stops future renewals. Paid access continues until the end of the period already purchased.</p>
            @endunless
        </section>
    @endforeach
    <div class="pricing-grid workspace-pricing">
        @foreach($plans as $plan)
            <article id="plan-{{ $plan->slug }}" class="pricing-card {{ $plan->slug === $currentPlan->slug ? 'featured' : '' }}">
                <div class="plan-label">{{ $plan->name }}</div>
                <div class="price">${{ $plan->displayPrice() }}<span>{{ $plan->price_cents === 0 ? 'Forever' : '/ month' }}</span></div>
                @if($plan->billing_interval === 'annually')<p class="plan-billing-note">Billed annually: US$47.40 per year.<br>Save US$12.60 (21%) compared with monthly billing.</p>@elseif($plan->price_cents > 0)<p class="plan-billing-note">Billed monthly. US$60 over 12 months.</p>@endif
                @if($plan->paystack_amount_cents && $plan->paystack_mode === 'live')<p class="plan-billing-note">ZAR {{ number_format($plan->paystack_amount_cents / 100, 2) }} charged {{ $plan->billing_interval === 'annually' ? 'once per year' : 'each month' }}.</p>@endif
                <ul>@foreach($plan->features as $feature)<li><x-icon name="check" /> {{ $feature }}</li>@endforeach</ul>
                @if(in_array($plan->slug, ['monthly', 'annual'], true))
                    <a class="btn btn-primary" href="{{ route('subscription.payment-methods', $plan->slug) }}">Choose payment method <x-icon name="arrow-right" /></a>
                @else
                    <span class="pill">{{ $plan->slug === $currentPlan->slug ? 'Your current plan' : 'Free plan' }}</span>
                @endif
            </article>
        @endforeach
    </div>
</x-app-layout>
