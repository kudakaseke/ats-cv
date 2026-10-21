@php
    $lastPayment = \App\Models\BillingPayment::where('user_id', auth()->id())->whereIn('status', ['paid', 'test_paid'])->latest('paid_at')->latest('id')->first();
    $livePlan = app(\App\Services\PlanService::class)->current(auth()->user());
@endphp
<section class="panel credit-summary" aria-label="Plan and latest payment">
    <h2>Current plan: {{ $livePlan->name }}@if(\App\Services\Billing\PaystackGateway::localTestCreditsEnabled() && $lastPayment?->status === 'test_paid' && $lastPayment?->subscription_id) (test)@endif</h2>
    @if($lastPayment)
        @php($purchasedPlan = \App\Models\SubscriptionPlan::find($lastPayment->subscription_plan_id))
        <p><strong>{{ $lastPayment->status === 'test_paid' ? 'Test payment confirmed' : 'Payment confirmed' }}</strong>
            &middot; {{ $lastPayment->kind === 'topup' ? 'Credit top-up' : $purchasedPlan?->name }}
            &middot; {{ $lastPayment->currency }} {{ number_format($lastPayment->amount_cents / 100, 2) }}</p>
        @if($lastPayment->status === 'test_paid')
            @if(\App\Services\Billing\PaystackGateway::localTestCreditsEnabled() && $lastPayment->subscription_id)
                <p>{{ $lastPayment->credits }} credits were allocated to your local test subscription. Your balance decreases as you use credits. This test subscription does not grant live paid access.</p>
            @else
                <p>{{ $lastPayment->credits }} credits belong to this test purchase. Test payments do not change your live plan or spendable credits. A verified live subscription activates the selected plan.</p>
            @endif
        @else
            <p>{{ $lastPayment->credits }} credits in this purchase. Your credit balance shows what remains available.</p>
        @endif
        <p>Payment reference: {{ $lastPayment->reference }}</p>
    @else
        <p>No confirmed payments yet.</p>
    @endif
    <a class="text-link" href="{{ route('account.activity') }}">View payments and audit trail</a>
</section>
