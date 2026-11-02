<x-app-layout>
    <div class="dashboard-heading"><div><h1>Payment status</h1><p>Reference: {{ $payment->reference }}</p></div></div>
    <section class="panel workspace-settings-panel"
        @if($payment->status === 'pending')
            x-data="{ checking: false, attempts: 0, timer: null,
                async checkPayment() {
                    if (this.checking || this.attempts >= 10) return;
                    this.checking = true; this.attempts++;
                    try {
                        const result = await fetch(@js(route('billing.check', $payment->reference)), {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }
                        });
                        if (result.ok) {
                            const data = await result.json();
                            if (data.redirect_url) { window.location.assign(data.redirect_url); return; }
                            if (data.status !== 'pending') { clearInterval(this.timer); window.location.reload(); }
                        } else if ([401, 403, 419].includes(result.status)) { clearInterval(this.timer); }
                    } catch (error) { /* Retry when the connection returns. */ }
                    finally { this.checking = false; }
                    if (this.attempts >= 10) clearInterval(this.timer);
                }, destroy() { clearInterval(this.timer); }
            }"
            x-init="timer = setInterval(() => checkPayment(), 6000)"
        @endif
    >
        <x-input-error :messages="$errors->get('billing')" class="mb-4" />
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        <h2>{{ ucfirst($payment->provider) }} · {{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</h2>
        <p>Status: <strong>{{ ucfirst($payment->status) }}</strong>
            @if($payment->mode === 'sandbox')
                — PayPal sandbox test payment
            @elseif($payment->mode === 'test')
                — {{ ucfirst($payment->provider) }} test payment
            @endif
        </p>
        @if($payment->status === 'test_paid')
            <p>Test payment verified. No live paid access has been granted.</p>
        @elseif($payment->status === 'paid')
            <p>Payment verified. Your purchase has been added to your account. Credits are available on your Subscription page. {{ $payment->recurring ? 'This subscription renews automatically until cancelled. Manage renewal on the Subscription page.' : 'This payment does not renew automatically.' }}</p>
        @elseif($payment->status === 'pending')
            @if($payment->provider === 'paynow' && $payment->checkout_url && app(\App\Services\Billing\PaynowGateway::class)->safeUrl($payment->checkout_url))
                <p><a class="btn btn-primary mt-4" href="{{ $payment->checkout_url }}">Continue to Paynow checkout</a></p>
            @endif
            @if($payment->provider === 'paystack' && $payment->checkout_url && parse_url($payment->checkout_url, PHP_URL_SCHEME) === 'https' && parse_url($payment->checkout_url, PHP_URL_HOST) === 'checkout.paystack.com')
                <p><a class="btn btn-primary mt-4" href="{{ $payment->checkout_url }}">Continue to Paystack checkout</a></p>
            @endif
            <p>Confirming your payment automatically. Once confirmed, you will go to your dashboard with your updated credits. You can also check the status below.</p>
            <p>Automatic checks stop after one minute. A pending payment does not mean it failed. If checkout could not open or you were charged, contact support with the reference before paying again.</p>
        @elseif($payment->status === 'cancelled')
            <p>Payment cancelled. This checkout cannot be resumed. No access or credits were added by this attempt.</p>
            <a class="btn btn-outline" href="{{ route('subscription') }}">Retry payment</a>
        @elseif($payment->status === 'reversed')
            <p>Paynow reports a refund or dispute. Access associated with this payment has been revoked. Contact support for a review.</p>
        @elseif($payment->status === 'failed')
            <p>Payment could not be completed. No credits were added. You can retry or contact support with your reference.</p>
            <a class="btn btn-outline" href="{{ route('subscription') }}">Retry payment</a>
        @elseif($payment->status === 'abandoned')
            <p>Payment was not completed. No credits were added.</p>
        @else
            <p>This payment is {{ $payment->status }}. Contact support if you need assistance.</p>
        @endif
        @if($payment->recurring)<p>Automatic renewal status: {{ $payment->recurring_status ?? 'Awaiting setup' }}. Renewals are {{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }} {{ $payment->billing_interval === 'annually' ? 'every year' : 'every month' }} until cancelled.</p>@endif
        <form method="POST" action="{{ route('billing.check', $payment->reference) }}">@csrf<button class="btn btn-primary mt-4" type="submit">Check payment status</button></form>
        <p class="mt-4"><a class="text-link" href="{{ route('subscription') }}">Back to subscription</a></p>
    </section>
</x-app-layout>
