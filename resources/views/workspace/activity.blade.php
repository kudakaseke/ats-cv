<x-app-layout>
    <div class="dashboard-heading"><div><h1>Account audit trail</h1><p>Your payments, credit movements and account activity. Times are shown in UTC.</p></div></div>
    <section class="panel audit-panel"><h2>Payment history</h2>
        <div class="audit-table-scroll"><table class="audit-table"><thead><tr><th>Date (UTC)</th><th>Purchase</th><th>Amount</th><th>Mode</th><th>Status</th><th>Reference</th></tr></thead><tbody>
        @forelse($payments as $payment)
            <tr><td>{{ $payment->paid_at ?? $payment->created_at }}</td><td>{{ $payment->kind === 'topup' ? 'Credit top-up' : $payment->plan_name }}<br>{{ $payment->credits }} credits</td><td>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</td><td>{{ ucfirst($payment->mode) }}</td><td>{{ $payment->status === 'test_paid' ? 'Test payment confirmed' : ucfirst($payment->status) }}</td><td><a class="text-link" href="{{ route('billing.show', $payment->reference) }}">{{ $payment->reference }}</a></td></tr>
        @empty<tr><td colspan="6">No payments yet.</td></tr>@endforelse
        </tbody></table></div>{{ $payments->withQueryString()->links() }}
    </section>
    <section class="panel audit-panel"><h2>Credit ledger</h2><p>Positive values add credits; negative values use or remove credits. Debt reduces the available balance.</p>
        <div class="audit-table-scroll"><table class="audit-table"><thead><tr><th>Date (UTC)</th><th>Action</th><th>Subscription / welcome</th><th>Purchased top-ups</th><th>Debt</th><th>Reference</th></tr></thead><tbody>
        @forelse($credits as $credit)
            <tr><td>{{ $credit->created_at }}</td><td>{{ ucfirst(str_replace('_', ' ', $credit->type)) }}</td><td>{{ sprintf('%+d', $credit->subscription_delta) }}</td><td>{{ sprintf('%+d', $credit->topup_delta) }}</td><td>{{ sprintf('%+d', $credit->debt_delta) }}</td><td>{{ $credit->reference }}</td></tr>
        @empty<tr><td colspan="6">No credit movements yet.</td></tr>@endforelse
        </tbody></table></div>{{ $credits->withQueryString()->links() }}
    </section>
    <section class="panel audit-panel"><h2>Activity history</h2><p>Historical snapshots identify records that existed before audit recording was enabled.</p>
        <div class="audit-table-scroll"><table class="audit-table"><thead><tr><th>Date recorded (UTC)</th><th>Event</th><th>Source</th><th>Details</th></tr></thead><tbody>
        @forelse($events as $event)
            <tr><td>{{ $event->created_at }}</td><td>{{ ucfirst(str_replace(['.', '_'], ' ', $event->event)) }}</td><td>{{ $event->source }}</td><td><details><summary>{{ $event->subject_type }} #{{ $event->subject_id }}</summary><pre>{{ json_encode(json_decode($event->details), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details></td></tr>
        @empty<tr><td colspan="4">New account activity will appear here.</td></tr>@endforelse
        </tbody></table></div>{{ $events->withQueryString()->links() }}
    </section>
</x-app-layout>
