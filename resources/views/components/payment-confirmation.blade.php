@props(['currentPlan' => null])
@php
    $lastPayment = \App\Models\BillingPayment::leftJoin('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
        ->where('billing_payments.user_id', auth()->id())->whereIn('billing_payments.status', ['paid', 'test_paid'])
        ->select('billing_payments.*', 'subscription_plans.name as plan_name')->latest('billing_payments.paid_at')->latest('billing_payments.id')->first();
    $livePlan = $currentPlan ?? app(\App\Services\PlanService::class)->current(auth()->user());
    $latestPeriod = $lastPayment?->subscription_id ? \App\Models\Subscription::find($lastPayment->subscription_id) : null;
@endphp
<section class="panel credit-summary" aria-label="Plan and latest payment">
    <h2>Current plan: {{ $livePlan->name }}</h2>
    @if($lastPayment)
        <p><strong>{{ $lastPayment->status === 'test_paid' ? 'Test payment confirmed' : 'Payment confirmed' }}</strong>
            &middot; {{ $lastPayment->kind === 'topup' ? 'Credit top-up' : $lastPayment->plan_name }}
            &middot; {{ $lastPayment->currency }} {{ number_format($lastPayment->amount_cents / 100, 2) }}</p>
        @if($lastPayment->status === 'test_paid' && $lastPayment->subscription_id && \App\Models\Subscription::localTestCreditsEnabled())
            <p>{{ $lastPayment->credits }} credits belong to this local test subscription. Your balance shows the credits available in the current paid period. No live payment was recorded.</p>
        @elseif($lastPayment->status === 'test_paid')
            <p>{{ $lastPayment->credits }} credits belong to this test purchase. Test payments do not change your live plan or spendable credits. A verified live subscription activates the selected plan.</p>
        @else
            <p>{{ $lastPayment->credits }} credits in this purchase. Your credit balance shows what remains available.</p>
        @endif
        <p>Payment reference: {{ $lastPayment->reference }}</p>
        @if($latestPeriod && in_array($latestPeriod->status, \App\Models\Subscription::availableStatuses(), true) && $latestPeriod->starts_at?->isFuture())
            <p><strong>Scheduled: {{ $lastPayment->plan_name }} starts {{ $latestPeriod->starts_at->timezone('Africa/Johannesburg')->format('d M Y') }}.</strong> @if($latestPeriod->credits_granted_upfront)Its {{ $lastPayment->credits }} credits were added immediately and will not be granted again on that date.@else Its {{ $lastPayment->credits }} credits become available then. Your current period is unchanged.@endif</p>
        @endif
    @else
        <p>No confirmed payments yet.</p>
    @endif
    <a class="text-link" href="{{ route('subscription') }}#payment-history">View payment history</a>
</section>
