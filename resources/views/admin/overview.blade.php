<x-admin-layout>
    <section class="admin-page-heading"><div><span class="admin-kicker">PLATFORM HEALTH</span><h1>Overview</h1><p>Live figures from verified payments, completed CV analyses and recorded AI usage.</p></div>
        <form method="GET" action="{{ route('admin.dashboard') }}" class="admin-date-filter"><label>From <input type="date" name="from" value="{{ $period['from'] }}" required></label><label>To <input type="date" name="to" value="{{ $period['to'] }}" required></label><button type="submit">Apply</button></form>
    </section>
    <div class="admin-kpi-grid">
        <article class="admin-kpi-card"><span>Total users</span><strong>{{ number_format($stats['users']) }}</strong><small>{{ number_format($stats['new_users']) }} new in this period</small></article>
        <article class="admin-kpi-card"><span>Active subscriptions</span><strong>{{ number_format($stats['active_subscriptions']) }}</strong><small>Currently active subscription records</small></article>
        <article class="admin-kpi-card"><span>Verified revenue</span><strong>US${{ number_format($stats['revenue_cents'] / 100, 2) }}</strong><small>Live paid transactions only</small></article>
        <article class="admin-kpi-card"><span>Completed CV scans</span><strong>{{ number_format($stats['scans']) }}</strong><small>Compared with {{ number_format($stats['previous_scans']) }} in prior period</small></article>
        <article class="admin-kpi-card"><span>Recorded AI API costs</span>
            <strong>{{ $stats['cost_micros'] === null ? 'Not available' : 'US$'.number_format($stats['cost_micros'] / 1_000_000, 4) }}</strong>
            <small>
                @if($stats['cost_micros'] === null)Cost data is not priced for requests in this period.
                @else
                    @if($stats['revenue_cents'] > 0){{ number_format(($stats['cost_micros'] / 1_000_000) / ($stats['revenue_cents'] / 100) * 100, 1) }}% of revenue known cost · @endif
                    @if($stats['unpriced_requests'] > 0){{ number_format($stats['unpriced_requests']) }} request(s) lack a configured price.
                    @else{{ number_format($stats['priced_requests']) }} requests priced from their saved model rate. @endif
                @endif
            </small>
        </article>
        <article class="admin-kpi-card"><span>Revenue less known AI costs</span><strong>{{ $stats['gross_difference_cents'] === null ? 'Not available' : 'US$'.number_format($stats['gross_difference_cents'] / 100, 2) }}</strong><small>Before payment fees and other operating costs</small></article>
    </div>
    @php($dailyFinancialMax = max(1, (float) collect($dailyChart)->flatMap(fn($point) => [$point['revenue'], $point['cost']])->max()))
    <section class="admin-panel admin-data-panel admin-daily-panel">
        <header class="admin-panel-heading"><div><h2>Revenue and AI costs by day</h2><p>Verified receipts and recorded AI costs for the selected dates, shown oldest to newest.</p></div></header>
        <div class="admin-daily-scroll"><div class="admin-daily-content" style="min-width:{{ max(1, count($dailyChart)) * 32 }}px">
            <div class="admin-daily-graph" role="img" aria-label="Daily revenue and AI costs from {{ $period['from'] }} to {{ $period['to'] }}">
                @foreach($dailyChart as $point)
                    <div class="admin-daily-point" title="{{ $point['date'] }}: revenue US${{ number_format($point['revenue'],2) }}, known AI cost US${{ number_format($point['cost'],4) }}"><div class="admin-daily-pair"><span class="admin-daily-bar revenue" style="height:{{ $point['revenue'] > 0 ? max(4,$point['revenue'] / $dailyFinancialMax * 100) : 0 }}%"></span><span class="admin-daily-bar cost" style="height:{{ $point['cost'] > 0 ? max(4,$point['cost'] / $dailyFinancialMax * 100) : 0 }}%"></span></div></div>
                @endforeach
            </div>
            <div class="admin-daily-axis" aria-hidden="true">
                @foreach($dailyChart as $point)
                    <div class="admin-daily-axis-point">@if(count($dailyChart) <= 14 || $loop->first || $loop->last || $loop->iteration % 7 === 0)<small>{{ $point['label'] }}</small>@endif</div>
                @endforeach
            </div>
        </div></div>
        <div class="admin-chart-legend"><span><i class="revenue"></i>Revenue</span><span><i class="cost"></i>AI cost</span></div>
        <div class="admin-daily-accessible"><table><caption>Daily verified revenue and known AI costs</caption><thead><tr><th>Date</th><th>Revenue USD</th><th>AI cost USD</th></tr></thead><tbody>@foreach($dailyChart as $point)<tr><td>{{ $point['date'] }}</td><td>{{ number_format($point['revenue'],2) }}</td><td>{{ number_format($point['cost'],4) }}</td></tr>@endforeach</tbody></table></div>
    </section>
    <section class="admin-panel admin-revenue-panel"><header class="admin-panel-heading"><div><h2>Revenue and AI costs by month</h2><p>Monthly totals within the selected date range. USD from verified live receipts and recorded AI token costs.</p></div><span class="admin-period-label">Selected period</span></header>
        @php($maxValue = max(1, $chartMax))
        <div class="admin-chart-list" role="img" aria-label="Monthly comparison of verified revenue and recorded AI costs">
            @foreach($chart as $point)
                <div class="admin-chart-row"><span class="admin-chart-month">{{ $point['label'] }}</span><div class="admin-chart-track">
                    <div class="admin-chart-bar revenue" style="width: {{ min(100, max($point['revenue_cents'] > 0 ? 1 : 0, ($point['revenue_cents'] / 100) / $maxValue * 100)) }}%"><span>Revenue {{ $point['revenue_cents'] > 0 ? 'US$'.number_format($point['revenue_cents'] / 100, 2) : '—' }}</span></div>
                    <div class="admin-chart-bar cost" style="width: {{ min(100, max(($point['cost_micros'] ?? 0) > 0 ? 1 : 0, (($point['cost_micros'] ?? 0) / 1_000_000) / $maxValue * 100)) }}%"><span>AI cost {{ $point['cost_micros'] === null ? 'not priced' : 'US$'.number_format($point['cost_micros'] / 1_000_000, 4) }}</span></div>
                </div></div>
            @endforeach
        </div>
        <div class="admin-chart-legend"><span><i class="revenue"></i>Revenue</span><span><i class="cost"></i>AI cost</span></div>
    </section>
    <p class="admin-updated">Range: {{ \Carbon\Carbon::parse($period['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($period['to'])->format('d M Y') }} · Updated {{ $lastUpdated->format('d M Y H:i T') }}</p>
</x-admin-layout>
