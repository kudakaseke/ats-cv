    <section class="admin-panel admin-data-panel">
        <header class="admin-panel-heading"><div><h2>Credit activity</h2><p>Ledger entries, including grants, usage and reversals.</p></div></header>
        <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Activity</th><th>Subscription change</th><th>Top-up change</th><th>Debt change</th><th>Reference and reason</th></tr></thead><tbody>
            @forelse($creditTransactions as $transaction)
                <tr><td>{{ \Carbon\CarbonImmutable::parse($transaction->created_at, config('app.timezone'))->format('d M Y H:i T') }}</td><td>{{ str_replace('_', ' ', $transaction->type) }}</td><td>{{ $transaction->subscription_delta }}</td><td>{{ $transaction->topup_delta }}</td><td>{{ $transaction->debt_delta }}</td><td>{{ $transaction->reference }}@if($grantDetails->has($transaction->reference))<br>{{ $grantDetails[$transaction->reference]['reason'] }}@if(!empty($grantDetails[$transaction->reference]['payment_reference']))<br>Payment: {{ $grantDetails[$transaction->reference]['payment_reference'] }}@endif @endif</td></tr>
            @empty<tr><td colspan="6">No credit activity recorded.</td></tr>@endforelse
        </tbody></table></div>{{ $creditTransactions->links() }}
    </section>
