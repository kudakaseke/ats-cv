<x-app-layout>
    <div class="dashboard-heading"><div><h1>Subscription</h1><p>Your current plan is {{ $currentPlan->name }}. You have {{ $wallet['credits'] }} AI credits.</p></div></div>
    <nav class="subscription-sections" aria-label="Subscription sections">
        <a href="#active-plan">Active Plan</a><a href="#plans">Plans</a><a href="#payment-history">Payment History</a>
    </nav>
    <section id="active-plan" class="subscription-section" aria-labelledby="active-plan-heading">
        <div class="panel credit-summary">
            <h2 id="active-plan-heading">Active Plan</h2>
            <h3>{{ $currentPlan->name }}</h3>
            @if($activeSubscription)
                <p>Billing cycle: {{ $currentPlan->billing_interval === 'annually' ? 'Annual' : 'Monthly' }}. Paid access expires {{ $activeSubscription->ends_at?->format('d M Y') }}.</p>
                @if(!$activeSubscription->auto_renew && \App\Models\BillingPayment::where('subscription_id', $activeSubscription->id)->where('provider', 'paynow')->exists())
                    <p>Renew manually. No automatic Paynow deductions.</p>
                    <a class="btn btn-outline" href="{{ route('subscription.payment-methods', $currentPlan->slug) }}">Renew subscription</a>
                    @if(!$activeSubscription->cancelled_at)<form method="POST" action="{{ route('billing.cancel-renewal', $activeSubscription) }}">@csrf<button class="btn btn-outline">Cancel renewal reminders</button></form>@endif
                @endif
                <p>Active subscription</p>
                <p>Started {{ $activeSubscription->starts_at->format('d M Y') }}@if($activeSubscription->ends_at) &middot; Paid access until {{ $activeSubscription->ends_at->format('d M Y') }}@endif.</p>
                <p>{{ config('ai_credits.plans.'.$currentPlan->slug) }} credits per {{ $currentPlan->slug === 'annual' ? 'paid year' : 'monthly release' }}.</p>
            @else
                <p>Free access with {{ count(config('cv_access.free_templates')) }} templates and one saved CV. Your 15 welcome credits are added once.</p>
            @endif
        </div>
    <x-payment-confirmation :current-plan="$currentPlan" />
    @foreach($scheduledSubscriptions as $scheduled)
        <section class="panel credit-summary"><h2>Next paid period: {{ $scheduled->plan->name }}</h2><p>Starts {{ $scheduled->starts_at->format('d M Y') }} and ends {{ $scheduled->ends_at->format('d M Y') }}. {{ $scheduled->credits_granted_upfront ? 'Credits from this purchase have already been added to your available balance.' : 'The new credit allowance becomes available when this period starts.' }}</p></section>
    @endforeach
    <section class="panel credit-summary"><h2>My AI Credits</h2><strong>{{ $wallet['credits'] }} credits</strong><p>{{ $wallet['subscription_credits'] }} subscription/welcome credits &middot; {{ $wallet['topup_credits'] }} purchased credits.</p>@if($wallet['credit_debt'])<p>{{ $wallet['credit_debt'] }} credits deducted from available balance for a refunded purchase already used.</p>@endif<p>Job-to-CV ATS Detector: 5 credits. Revamp: 10 credits. One complete workflow: 15 credits. Manual editing and downloads use no credits.</p><p>Subscription credits expire at the end of the paid term. Early renewal adds credits immediately and keeps the remaining subscription balance available until the extended term ends. Purchased credits remain in your balance. Free accounts receive 15 welcome credits once.</p></section>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    @if(session('billing_status'))<p class="my-4" role="status">{{ session('billing_status') }}</p>@endif
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
                @if($plan->billing_interval === 'annually')<p class="plan-billing-note">US$47.40 billed yearly.</p>@endif
                @if(in_array($plan->slug, ['monthly', 'pro', 'annual'], true))
                    <a class="btn btn-primary" href="{{ route('subscription.payment-methods', $plan->slug) }}">Choose plan</a>
                @else
                    <span class="pill">{{ $plan->slug === $currentPlan->slug ? 'Your current plan' : 'Free plan' }}</span>
                @endif
                <x-plan-features :plan="$plan" />
            </div>
                @endforeach
            </article>
        @endforeach
    </div>
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
                    <td><a class="text-link" href="{{ route('billing.show', $payment->reference) }}">View payment</a>
                        @if($payment->provider === 'paynow' && $payment->paid_at)<br><a class="text-link" href="{{ route('billing.receipt', $payment->reference) }}">Download receipt</a>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No payments yet. Your payment history will appear here after checkout.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $payments->withQueryString()->links() }}
    </section>
</x-app-layout>
