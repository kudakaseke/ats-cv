<x-app-layout>
    <div class="dashboard-heading"><div><h1>Paynow billing management</h1><p>Test payments are listed separately from live payments. Revenue below is gross verified payments, before fees.</p></div></div>
    <x-input-error :messages="$errors->get('billing')" class="my-4" />
    @if(session('billing_status'))<p role="status">{{ session('billing_status') }}</p>@endif
    <section class="panel credit-summary"><h2>Payment totals</h2>
        <p>Live revenue this month: USD {{ number_format($monthlyRevenue / 100, 2) }} · This year: USD {{ number_format($annualRevenue / 100, 2) }}</p>
        @foreach($totals as $total)<p>{{ ucfirst($total->mode) }} · {{ ucfirst($total->status) }}: {{ $total->total }} payments · USD {{ number_format($total->cents / 100, 2) }}</p>@endforeach
        <h2>Subscription totals</h2>@foreach($subscriptions as $total)<p>{{ ucfirst($total->status) }}: {{ $total->total }}</p>@endforeach
        <h2>Live paid plan distribution</h2>@foreach($plans as $plan)<p>{{ $plan->name }}: {{ $plan->total }} payments · USD {{ number_format($plan->cents / 100, 2) }} · {{ $plan->credits }} purchased credits (future periods allocate at their start date)</p>@endforeach
    </section>
    <section class="panel audit-panel"><h2>Payments</h2>
        <form method="GET" action="{{ route('admin.billing') }}"><label>Reference <input name="search" value="{{ request('search') }}" maxlength="100"></label>
            <label>Status <select name="status"><option value="">All statuses</option>@foreach(['pending','paid','test_paid','cancelled','failed','reversed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button class="btn btn-primary">Filter</button><button class="btn btn-outline" name="export" value="csv">Export CSV</button>
        </form>
        <div class="audit-table-scroll"><table class="audit-table"><thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @foreach($payments as $payment)<tr><td>{{ $payment->reference }}<br>Paynow: {{ $payment->provider_reference ?? 'Not received' }}<br>User {{ $payment->user_id }} · Plan {{ $payment->subscription_plan_id }} · Subscription {{ $payment->subscription_id ?? 'None' }}</td>
            <td>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}<br>{{ $payment->mode }}</td>
            <td>{{ $payment->status }}<br>{{ $payment->provider_status }}<br>Verified: {{ $payment->verified_at?->format('d M Y H:i') ?? 'Awaiting confirmation' }}</td>
            <td><form method="POST" action="{{ route('admin.billing.reconcile', $payment->reference) }}">@csrf<button class="btn btn-outline">Reconcile with Paynow</button></form>
                <form method="POST" action="{{ route('admin.billing.review', $payment->reference) }}">@csrf
                    <label>Review type <select name="type"><option value="refund_requested">Refund requested</option><option value="refund_recorded">Manual refund recorded</option><option value="dispute_review">Dispute review</option></select></label>
                    <label>Review notes <textarea name="notes" required minlength="10" maxlength="2000"></textarea></label><button class="btn btn-outline">Record review</button>
                </form>
            </td></tr>@endforeach
        </tbody></table></div>{{ $payments->links() }}
    </section>
    <section class="panel credit-summary"><h2>Unprocessed notifications</h2>@forelse($notifications as $notification)<p>#{{ $notification->id }} · Payment {{ $notification->billing_payment_id ?? 'Unknown' }} · {{ $notification->hash_valid ? 'Authenticated' : 'Invalid hash' }} · {{ $notification->error ?? 'Awaiting processing' }}</p>@empty<p>No unprocessed notifications.</p>@endforelse</section>
    <section class="panel credit-summary"><h2>Refund and dispute reviews</h2>@forelse($reviews as $review)<p>Payment {{ $review->billing_payment_id }} · {{ $review->type }} · {{ $review->notes }}</p>@empty<p>No reviews.</p>@endforelse</section>
</x-app-layout>
