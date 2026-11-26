    <section class="admin-panel admin-data-panel">
        <header class="admin-panel-heading"><div><h2>All payment attempts</h2><p>Paid and verified times are shown only when recorded.</p></div></header>
        <div class="admin-table-wrap"><table class="admin-table admin-payment-table">
            <thead><tr><th>Reference</th><th>Receipt</th><th>Plan</th><th>Amount</th><th>Credits purchased</th><th>Status</th><th>Paynow status</th><th>Mode</th><th>Started</th><th>Paid</th><th>Last verified</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr><td>{{ $payment->reference }}</td><td>{{ $payment->receipt_number ?? '?' }}</td><td>{{ $payment->plan ?? '—' }}</td>
                        <td>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</td>
                        <td>{{ number_format($payment->credits) }}</td><td>{{ str_replace('_', ' ', ucfirst($payment->status)) }}@if($payment->failure_reason)<br>{{ $payment->failure_reason }}@endif</td><td>{{ $payment->provider_status ?: 'Not verified yet' }}</td><td>{{ ucfirst($payment->mode) }}</td>
                        @foreach(['created_at', 'paid_at', 'verified_at'] as $date)
                            <td>{{ $payment->{$date} ? \Carbon\CarbonImmutable::parse($payment->{$date}, config('app.timezone'))->format('d M Y H:i T') : '—' }}</td>
                        @endforeach
                        <td>@if($payment->provider === 'paynow')<form method="POST" action="{{ route('admin.billing.reconcile', $payment->reference) }}">@csrf<button type="submit" class="admin-primary-button">Check Paynow status</button></form>@else &mdash; @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="12">No payment attempts recorded for this user.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="admin-pagination">{{ $payments->links() }}</div>
    </section>
