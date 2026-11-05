@props(['currentPlan' => null])
@php
    $lastPayment = \App\Models\BillingPayment::leftJoin('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
        ->where('billing_payments.user_id', auth()->id())->whereIn('billing_payments.status', ['paid', 'test_paid'])
        ->select('billing_payments.*', 'subscription_plans.name as plan_name')->latest('billing_payments.paid_at')->latest('billing_payments.id')->first();
    $livePlan = $currentPlan ?? app(\App\Services\PlanService::class)->current(auth()->user());
@endphp
<section class="panel credit-summary" aria-label="Plan and latest payment">
    <h2>Current plan: {{ $livePlan->name }}</h2>
    @if($lastPayment)
        <p><strong>{{ $lastPayment->status === 'test_paid' ? 'Test payment confirmed' : 'Payment confirmed' }}</strong>
            &middot; {{ $lastPayment->kind === 'topup' ? 'Credit top-up' : $lastPayment->plan_name }}
            &middot; {{ $lastPayment->currency }} {{ number_format($lastPayment->amount_cents / 100, 2) }}</p>
        @if($lastPayment->status === 'test_paid')
            <p>{{ $lastPayment->credits }} credits belong to this test purchase. Test payments do not change your live plan or spendable credits. A verified live subscription activates the selected plan.</p>
        @else
            <p>{{ $lastPayment->credits }} credits in this purchase. Your credit balance shows what remains available.</p>
        @endif
        <p>Payment reference: {{ $lastPayment->reference }}</p>
    @else
        <p>No confirmed payments yet.</p>
    @endif
    <a class="text-link" href="{{ route('subscription') }}#payment-history">View payment history</a>
</section>
