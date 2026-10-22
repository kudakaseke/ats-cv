<x-app-layout>
    <div class="dashboard-heading"><div><h1>Subscription</h1><p>Your current plan is {{ $currentPlan->name }}. You have {{ $wallet['credits'] }} AI credits.</p></div></div>
    <nav class="subscription-sections" aria-label="Subscription sections">
        <a href="#active-plan">Active Plan</a><a href="#plans">Plans</a><a href="#cards">Cards</a><a href="#payment-history">Payment History</a>
    </nav>
    <section id="active-plan" class="subscription-section" aria-labelledby="active-plan-heading">
        <div class="panel credit-summary">
            <h2 id="active-plan-heading">Active Plan</h2>
            <h3>{{ $currentPlan->name }}</h3>
            @if($activeSubscription)
                <p>{{ $activeSubscription->status === 'test_active' ? 'Test subscription' : 'Active subscription' }}</p>
                <p>Started {{ $activeSubscription->starts_at->format('d M Y') }}@if($activeSubscription->ends_at) &middot; Paid access until {{ $activeSubscription->ends_at->format('d M Y') }}@endif.</p>
                <p>{{ config('ai_credits.plans.'.$currentPlan->slug) }} credits per monthly release.</p>
            @else
                <p>Free access with {{ count(config('cv_access.free_templates')) }} templates and one saved CV. Your 15 welcome credits are added once.</p>
            @endif
        </div>
    <x-payment-confirmation :current-plan="$currentPlan" />
    <section class="panel credit-summary"><h2>My AI Credits</h2><strong>{{ $wallet['credits'] }} credits</strong><p>{{ $wallet['subscription_credits'] }} subscription/welcome credits &middot; {{ $wallet['topup_credits'] }} purchased credits.</p>@if($wallet['credit_debt'])<p>{{ $wallet['credit_debt'] }} credits deducted from available balance for a refunded purchase already used.</p>@endif<p>Job-to-CV ATS Detector: 5 credits. Revamp: 10 credits. One complete workflow: 15 credits. Manual editing and downloads use no credits.</p><p>Unused subscription credits reset each month. Purchased credits remain in your balance. Free accounts receive 15 welcome credits once.</p></section>
    <div class="panel subscription-info"><x-icon name="info" /><p>Pay securely with Paystack. Subscriptions renew monthly or annually until cancelled. Credits are added after payment is verified, and you return to your dashboard. The checkout shows the exact amount and currency you will pay. @if(\App\Services\Billing\PaystackGateway::localTestCreditsEnabled()) Verified local test subscriptions include test credits. @else Test payments do not activate paid credits. @endif</p></div>
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
    @foreach($paystackSubscriptions as $agreement)
        <section class="panel credit-summary"><h2>Paystack automatic renewal</h2><p>{{ $agreement->currency }} {{ number_format($agreement->amount_cents/100,2) }} {{ $agreement->billing_interval==='annually' ? 'per year' : 'per month' }} &middot; {{ $agreement->recurring_status ?? 'Setup pending' }}</p><a class="text-link" href="{{ route('billing.show',$agreement->reference) }}">Check payment status</a>
            @unless(in_array($agreement->recurring_status,['CANCELLED','DISABLED','COMPLETE'],true))<form method="POST" action="{{ route('paystack.cancel',$agreement->reference) }}">@csrf<button class="btn btn-outline">Cancel automatic renewal</button></form>@endunless
        </section>
    @endforeach
    </section>
    <section id="plans" class="subscription-section" aria-labelledby="plans-heading">
    <h2 id="plans-heading" class="subscription-section-heading">Plans</h2>
    <div class="pricing-grid workspace-pricing">
        @foreach ($plans->where('slug', '!=', 'pro') as $basePlan)
            <article id="plan-{{ $basePlan->slug }}" @if($basePlan->slug === 'monthly') :id="pro ? 'plan-pro' : 'plan-monthly'" @endif class="pricing-card {{ $basePlan->slug === $currentPlan->slug || ($basePlan->slug === 'monthly' && $currentPlan->slug === 'pro') ? 'featured' : '' }}" x-data="{ pro: window.location.hash === '#plan-pro' }" @hashchange.window="if (window.location.hash === '#plan-pro') pro = true; if (window.location.hash === '#plan-monthly') pro = false">
                @if($basePlan->slug === 'monthly')<x-plan-pro-switch />@endif
                @foreach ($basePlan->slug === 'monthly' ? $plans->whereIn('slug', ['monthly', 'pro']) : collect([$basePlan]) as $plan)
                <div class="plan-variant" @if($basePlan->slug === 'monthly') x-show="{{ $plan->slug === 'pro' ? 'pro' : '!pro' }}" @if($plan->slug === 'pro') x-cloak @endif @endif>
                <div class="plan-label">{{ $plan->name }}</div>
                <div class="price">${{ $plan->displayPrice() }}<span>{{ $plan->price_cents === 0 ? 'Forever' : '/ month' }}</span></div>
                @if($plan->billing_interval === 'annually')<p class="plan-billing-note">US$47.40 billed once per year. 200 credits released each month.</p>@elseif($plan->price_cents > 0)<p class="plan-billing-note">US${{ number_format($plan->price_cents / 100, 2) }} billed monthly. {{ config('ai_credits.plans.'.$plan->slug) }} credits after each successful payment.</p>@endif
                @if($plan->paystack_amount_cents && $plan->paystack_mode === 'live')<p class="plan-billing-note">{{ config('paystack.currency') }} {{ number_format($plan->paystack_amount_cents / 100, 2) }} charged {{ $plan->billing_interval === 'annually' ? 'once per year' : 'each month' }}.</p>@endif
                <ul>@foreach($plan->features as $feature)<li><x-icon name="check" /> {{ str_replace(['One complete analysis and CV revamp', 'Analysis history'], ['One complete Job-to-CV ATS Detector check and CV revamp', 'Job-to-CV ATS Detector history'], $feature) }}</li>@endforeach</ul>
                @if(in_array($plan->slug, ['monthly', 'pro', 'annual'], true))
                    <a class="btn btn-primary" href="{{ route('subscription.payment-methods', $plan->slug) }}">Choose Payment Method</a>
                @else
                    <span class="pill">{{ $plan->slug === $currentPlan->slug ? 'Your current plan' : 'Free plan' }}</span>
                @endif
            </div>
                @endforeach
            </article>
        @endforeach
    </div>
    <section id="credit-topups" class="panel credit-summary">
        <h2>Buy more credits</h2><p>One-time purchases. Your subscription stays the same, and purchased credits carry over.</p>
        @php($packs = collect(config('ai_credits.topups'))->filter(fn($pack) => is_numeric($pack['price_cents']) && (int)$pack['price_cents'] > 0))
        @forelse($packs as $id => $pack)
            <article class="credit-pack"><h3>{{ $pack['credits'] }} credits &middot; US${{ number_format($pack['price_cents'] / 100, 2) }}</h3>
            @foreach(['paynow' => 'Paynow', 'paypal' => 'PayPal', 'paystack' => 'Paystack'] as $provider => $label)
                @if(($provider === 'paystack' && config('paystack.enabled') && filled(config('paystack.secret_key')) && (config('paystack.currency') === 'USD' || config('paystack.topup_amounts.'.$id))) || ($provider !== 'paystack' && config('billing.'.$provider.'.enabled')))
                <form method="POST" action="{{ route('credits.topup') }}">@csrf<input type="hidden" name="pack" value="{{ $id }}"><input type="hidden" name="provider" value="{{ $provider }}"><label class="billing-consent"><input type="checkbox" name="billing_consent" value="1" required> I agree to this one-time purchase and the <a href="{{ route('refund') }}">Refund Policy</a>.</label><button class="btn btn-outline">Buy with {{ $label }}@if($provider==='paystack' && config('paystack.currency')!=='USD') &middot; {{ config('paystack.currency') }} {{ number_format(config('paystack.topup_amounts.'.$id)/100,2) }}@endif</button></form>
                @endif
            @endforeach
            </article>
        @empty <p>Extra-credit packs will be available once their prices are configured.</p>
        @endforelse
    </section>
    </section>
    <section id="cards" class="panel credit-summary subscription-section" aria-labelledby="cards-heading">
        <h2 id="cards-heading">Cards</h2>
        <p>Your card is entered and managed securely through Paystack checkout. Choose a plan or credit pack above to pay with a card.</p>
        <p>Card details are not stored or displayed in your account here.</p>
        <a class="text-link" href="#plans">Choose a plan</a>
    </section>
    <section id="payment-history" class="panel audit-panel subscription-section" aria-labelledby="payment-history-heading">
        <h2 id="payment-history-heading">Payment History</h2>
        <div class="audit-table-scroll"><table class="audit-table">
            <thead><tr><th>Date</th><th>Purchase</th><th>Amount</th><th>Credits</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td>{{ ($payment->paid_at ?? $payment->created_at)->timezone('Africa/Johannesburg')->format('d M Y, H:i') }} SAST</td>
                    <td>{{ $payment->kind === 'topup' ? 'Credit top-up' : ($payment->plan_name ?? 'Subscription') }}<br>{{ ucfirst($payment->provider) }}@if($payment->mode === 'test') &middot; Test payment @endif</td>
                    <td>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</td>
                    <td>{{ $payment->credits }}</td>
                    <td>{{ $payment->status === 'test_paid' ? 'Test payment confirmed' : ucfirst(str_replace('_', ' ', $payment->status)) }}</td>
                    <td><a class="text-link" href="{{ route('billing.show', $payment->reference) }}">View payment</a></td>
                </tr>
            @empty
                <tr><td colspan="6">No payments yet. Your payment history will appear here after checkout.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $payments->withQueryString()->links() }}
    </section>
</x-app-layout>
