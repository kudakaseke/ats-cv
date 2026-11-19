<x-admin-layout>
    @php
        $dailyFinancialMax = max(1, (float) collect($dailyChart)->flatMap(fn ($point) => [$point['revenue'], $point['cost']])->max());
        $pointCount = max(1, count($dailyChart));
        $revenueLinePoints = collect($dailyChart)->values()->map(function ($point, $index) use ($pointCount, $dailyFinancialMax) {
            $x = $pointCount > 1 ? 60 + ($index * 900 / ($pointCount - 1)) : 510;
            $y = 220 - ($point['revenue'] / $dailyFinancialMax * 200);

            return number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
        });
        $costLinePoints = collect($dailyChart)->values()->map(function ($point, $index) use ($pointCount, $dailyFinancialMax) {
            $x = $pointCount > 1 ? 60 + ($index * 900 / ($pointCount - 1)) : 510;
            $y = 220 - ($point['cost'] / $dailyFinancialMax * 200);

            return number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
        });
        $dailyFinancialAxisTicks = collect(range(4, 0))->map(fn ($tick) => $dailyFinancialMax * $tick / 4);
        $maxValue = max(1, $chartMax);
    @endphp

    <section class="admin-page-heading admin-overview-heading">
        <div><h1>Overview</h1></div>
        <form method="GET" action="{{ route('admin.dashboard') }}" class="admin-date-filter">
            <label>From <input type="date" name="from" value="{{ $period['from'] }}" required></label>
            <label>To <input type="date" name="to" value="{{ $period['to'] }}" required></label>
            <button type="submit">Apply</button><a class="admin-date-today" href="{{ route('admin.dashboard') }}">Today</a>
        </form>
    </section>

    <section class="admin-overview-hero">
        <div class="admin-overview-chart-card">
            <header class="admin-overview-chart-heading">
                <div><span>Verified revenue</span><strong>US${{ number_format($stats['revenue_cents'] / 100, 2) }}</strong>
                    <small>Live paid transactions · {{ \Carbon\Carbon::parse($period['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($period['to'])->format('d M Y') }}</small></div>
                <a href="{{ route('admin.section', 'revenue') }}">View revenue <x-icon name="arrow-up-right" /></a>
            </header>
            <div class="admin-line-chart-scroll">
                <div class="admin-line-chart-content" style="min-width:{{ max(795, 55 + $pointCount * 24) }}px">
                <div class="admin-line-chart" role="img" aria-label="Daily verified revenue and known AI costs from {{ $period['from'] }} to {{ $period['to'] }}">
                    <div class="admin-line-y-axis" aria-hidden="true">@foreach($dailyFinancialAxisTicks as $tick)<span>${{ number_format($tick, $tick < 1 ? 4 : 2) }}</span>@endforeach</div>
                    <svg class="admin-line-svg" viewBox="0 0 1000 250" preserveAspectRatio="none" aria-hidden="true">
                        <defs><linearGradient id="adminRevenueFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#635bff" stop-opacity=".22"/><stop offset="100%" stop-color="#635bff" stop-opacity=".015"/></linearGradient></defs>
                        @foreach(range(0, 4) as $gridline)<line x1="60" y1="{{ 20 + ($gridline * 50) }}" x2="960" y2="{{ 20 + ($gridline * 50) }}" class="admin-line-grid" />@endforeach
                        @if(count($dailyChart) > 1)<polygon points="{{ implode(' ', $revenueLinePoints->all()) }} 960,220 60,220" fill="url(#adminRevenueFill)" />@endif
                        @if(count($dailyChart) > 0)<polyline points="{{ implode(' ', $revenueLinePoints->all()) }}" class="admin-line-revenue" />
                            <polyline points="{{ implode(' ', $costLinePoints->all()) }}" class="admin-line-cost" />
                            @foreach($dailyChart as $index => $point)
                                @php
                                    $x = $pointCount > 1 ? 60 + ($index * 900 / ($pointCount - 1)) : 510;
                                    $revenueY = 220 - ($point['revenue'] / $dailyFinancialMax * 200);
                                @endphp
                                <circle cx="{{ $x }}" cy="{{ $revenueY }}" r="3" class="admin-line-dot"><title>{{ $point['date'] }}: revenue US${{ number_format($point['revenue'], 2) }}, known AI cost US${{ number_format($point['cost'], 4) }}</title></circle>
                            @endforeach
                            @foreach($dailyChart as $index => $point)
                                @php
                                    $step = $pointCount > 1 ? 900 / ($pointCount - 1) : 900;
                                    $left = $pointCount > 1 ? ($index === 0 ? 0 : 60 + ($index - 0.5) * $step) : 0;
                                    $right = $pointCount > 1 ? ($index === $pointCount - 1 ? 1000 : 60 + ($index + 0.5) * $step) : 1000;
                                @endphp
                                <rect x="{{ $left }}" y="0" width="{{ $right - $left }}" height="250" fill="transparent"><title>{{ $point['date'] }}: revenue US${{ number_format($point['revenue'], 2) }}, known AI cost US${{ number_format($point['cost'], 4) }}</title></rect>
                            @endforeach
                        @endif
                    </svg>
                </div>
                <div class="admin-line-x-axis" aria-hidden="true">@foreach($dailyChart as $point)<span>@if(count($dailyChart) <= 14 || $loop->first || $loop->last || $loop->iteration % 7 === 0){{ $point['label'] }}@endif</span>@endforeach</div>
                </div>
            </div>
            <div class="admin-chart-legend"><span><i class="revenue"></i>Verified revenue</span><span><i class="cost"></i>Known AI cost</span></div>
        </div>
        <aside class="admin-overview-side">
            <section class="admin-subscription-summary"><div class="admin-summary-top"><span>Subscriptions</span><x-icon name="credit-card" /></div><strong>{{ number_format($stats['active_subscriptions']) }}</strong><p>Active subscriptions</p><a href="{{ route('admin.section', 'revenue') }}">Manage subscriptions <x-icon name="arrow-right" /></a></section>
            <section class="admin-side-summary"><span>New users</span><strong>{{ number_format($stats['new_users']) }}</strong><a href="{{ route('admin.section', 'users') }}">View users <x-icon name="arrow-up-right" /></a></section>
        </aside>
    </section>

    <section class="admin-overview-metrics" aria-labelledby="admin-overview-metrics-title">
        <header class="admin-overview-section-heading"><div><h2 id="admin-overview-metrics-title">Your overview</h2></div><span>Selected period</span></header>
        <div class="admin-kpi-grid">
            <article class="admin-kpi-card"><span>Total users</span><strong>{{ number_format($stats['users']) }}</strong><small>{{ number_format($stats['new_users']) }} new in this period</small></article>
            <article class="admin-kpi-card"><span>Verified revenue</span><strong>US${{ number_format($stats['revenue_cents'] / 100, 2) }}</strong><small>Verified payments</small></article>
            <article class="admin-kpi-card"><span>CV scans</span><strong>{{ number_format($stats['scans']) }}</strong><small>Compared with {{ number_format($stats['previous_scans']) }} in prior period</small></article>
            <article class="admin-kpi-card"><span>AI costs</span>
                <strong>{{ $stats['cost_micros'] === null ? 'Not available' : 'US$'.number_format($stats['cost_micros'] / 1_000_000, 4) }}</strong>
                <small>@if($stats['cost_micros'] === null)Cost data is not priced for requests in this period.@else @if($stats['revenue_cents'] > 0){{ number_format(($stats['cost_micros'] / 1_000_000) / ($stats['revenue_cents'] / 100) * 100, 1) }}% of revenue known cost · @endif @if($stats['unpriced_requests'] > 0){{ number_format($stats['unpriced_requests']) }} request(s) lack a configured price.@else{{ number_format($stats['priced_requests']) }} requests priced from their saved model rate.@endif @endif</small>
            </article>
            <article class="admin-kpi-card"><span>Revenue after AI costs</span><strong>{{ $stats['gross_difference_cents'] === null ? 'Not available' : 'US$'.number_format($stats['gross_difference_cents'] / 100, 2) }}</strong><small>Before fees and other costs</small></article>
        </div>
    </section>

    <section class="admin-panel admin-revenue-panel admin-monthly-comparison"><header class="admin-panel-heading"><div><h2>Revenue and AI costs by month</h2><p>Verified revenue and recorded AI costs, in USD.</p></div><span class="admin-period-label">Selected period</span></header>
        <div class="admin-chart-list" role="img" aria-label="Monthly comparison of verified revenue and recorded AI costs">
            @foreach($chart as $point)
                <div class="admin-chart-row"><span class="admin-chart-month">{{ $point['label'] }}</span><div class="admin-chart-track">
                    <div class="admin-chart-bar revenue" style="width: {{ min(100, max($point['revenue_cents'] > 0 ? 1 : 0, ($point['revenue_cents'] / 100) / $maxValue * 100)) }}%"><span>Revenue {{ $point['revenue_cents'] > 0 ? 'US$'.number_format($point['revenue_cents'] / 100, 2) : '—' }}</span></div>
                    <div class="admin-chart-bar cost" style="width: {{ min(100, max(($point['cost_micros'] ?? 0) > 0 ? 1 : 0, (($point['cost_micros'] ?? 0) / 1_000_000) / $maxValue * 100)) }}%"><span>AI cost {{ $point['cost_micros'] === null ? 'not priced' : 'US$'.number_format($point['cost_micros'] / 1_000_000, 4) }}</span></div>
                </div></div>
            @endforeach
        </div>
        <div class="admin-chart-legend"><span><i class="revenue"></i>Revenue</span><span><i class="cost"></i>Known AI cost</span></div>
    </section>
    <div class="admin-daily-accessible"><table><caption>Daily verified revenue and known AI costs</caption><thead><tr><th>Date</th><th>Revenue USD</th><th>AI cost USD</th></tr></thead><tbody>@foreach($dailyChart as $point)<tr><td>{{ $point['date'] }}</td><td>{{ number_format($point['revenue'], 2) }}</td><td>{{ number_format($point['cost'], 4) }}</td></tr>@endforeach</tbody></table></div>
    <p class="admin-updated">Range: {{ \Carbon\Carbon::parse($period['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($period['to'])->format('d M Y') }} · Updated {{ $lastUpdated->format('d M Y H:i T') }}</p>
</x-admin-layout>
