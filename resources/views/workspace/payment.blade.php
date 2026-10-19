<x-app-layout>
    <div class="dashboard-heading"><div><h1>Payment status</h1><p>Reference: {{ $payment->reference }}</p></div></div>
    <section class="panel workspace-settings-panel">
        <x-input-error :messages="$errors->get('billing')" class="mb-4" />
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <h2>{{ ucfirst($payment->provider) }} · US${{ number_format($payment->amount_cents / 100, 2) }}</h2>
        <p>Status: <strong>{{ ucfirst($payment->status) }}</strong>
            @if($payment->mode === 'sandbox')
                — PayPal sandbox test payment
            @elseif($payment->mode === 'test')
                — Paynow test payment
            @endif
        </p>
        @if($payment->status === 'test_paid')
            <p>Test payment verified. No live paid access has been granted.</p>
        @elseif($payment->status === 'paid')
            <p>Payment verified. Your access period has been added to your account. {{ $payment->recurring ? 'PayPal renews automatically until cancelled. Manage renewal on the Subscription page.' : 'This payment does not renew automatically.' }}</p>
        @elseif($payment->status === 'pending')
            <p>Returning from checkout does not confirm payment. After approving payment at the provider, check its status below. Paid access starts after payment is verified.</p>
        @else
            <p>This payment is {{ $payment->status }}. Contact support if you need assistance.</p>
        @endif
        @if($payment->recurring)<p>Automatic renewal status: {{ $payment->recurring_status ?? 'Awaiting setup' }}. Renewals are US${{ number_format($payment->amount_cents / 100, 2) }} {{ $payment->billing_interval === 'annually' ? 'every year' : 'every month' }} until cancelled.</p>@endif
        <form method="POST" action="{{ route('billing.check', $payment->reference) }}">@csrf<button class="btn btn-primary mt-4" type="submit">Check payment status</button></form>
        <p class="mt-4"><a class="text-link" href="{{ route('subscription') }}">Back to subscription</a></p>
    </section>
</x-app-layout>
