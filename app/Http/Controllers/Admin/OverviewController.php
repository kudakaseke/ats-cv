<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OverviewRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __invoke(OverviewRequest $request): View
    {
        $timezone = config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $request->filled('from') ? CarbonImmutable::parse($request->validated('from'), $timezone)->startOfDay() : $today->subDays(29);
        $end = $request->filled('to') ? CarbonImmutable::parse($request->validated('to'), $timezone)->startOfDay() : $today;
        $days = (int) $start->diffInDays($end) + 1;
        $previousStart = $start->subDays($days);
        $previousEnd = $start;
        $rangeStart = $start;
        $rangeEnd = $end->addDay();
        $previousStartRange = $previousStart;
        $previousEndRange = $previousEnd;

        $users = DB::table('users')->where('role', 'user');
        $revenueBase = DB::table('billing_payments')->where('status', 'paid')->where('mode', 'live')
            ->whereNotNull('paid_at')->whereNotNull('verified_at')->where('currency', 'USD');
        $scansBase = DB::table('cv_analyses')->where('status', 'completed');
        $usageBase = DB::table('ai_usage_logs');

        $totalUsers = (clone $users)->count();
        $activeSubscriptions = DB::table('subscriptions')->where('status', 'active')->count();
        $newUsers = (clone $users)->where('created_at', '>=', $rangeStart)->where('created_at', '<', $rangeEnd)->count();
        $previousUsers = (clone $users)->where('created_at', '>=', $previousStartRange)->where('created_at', '<', $previousEndRange)->count();
        $revenueCents = (clone $revenueBase)->where('paid_at', '>=', $rangeStart)->where('paid_at', '<', $rangeEnd)->sum('amount_cents');
        $previousRevenueCents = (clone $revenueBase)->where('paid_at', '>=', $previousStartRange)->where('paid_at', '<', $previousEndRange)->sum('amount_cents');
        $scanCount = (clone $scansBase)->where('created_at', '>=', $rangeStart)->where('created_at', '<', $rangeEnd)->count();
        $previousScans = (clone $scansBase)->where('created_at', '>=', $previousStartRange)->where('created_at', '<', $previousEndRange)->count();
        $usage = (clone $usageBase)->where('created_at', '>=', $rangeStart)->where('created_at', '<', $rangeEnd);
        $pricedRequests = (clone $usage)->whereNotNull('total_cost_micros')->count();
        $unpricedRequests = (clone $usage)->whereNull('total_cost_micros')->count();
        $costMicros = $pricedRequests > 0 ? (clone $usage)->sum('total_cost_micros') : null;
        $previousUsage = (clone $usageBase)->where('created_at', '>=', $previousStartRange)->where('created_at', '<', $previousEndRange);
        $previousCostMicros = (clone $previousUsage)->whereNotNull('total_cost_micros')->sum('total_cost_micros');
        $previousCostKnown = (clone $previousUsage)->whereNotNull('total_cost_micros')->exists();

        $chart = [];
        $month = $start->startOfMonth();
        $chartMax = 0;
        while ($month <= $end) {
            $bucketStart = $month < $start ? $start : $month;
            $nextMonth = $month->addMonth()->startOfMonth();
            $periodEnd = $end->addDay();
            $bucketEnd = $nextMonth < $periodEnd ? $nextMonth : $periodEnd;
            $monthStart = $bucketStart;
            $monthEnd = $bucketEnd;
            $rev = (int) (clone $revenueBase)->where('paid_at', '>=', $monthStart)->where('paid_at', '<', $monthEnd)->sum('amount_cents');
            $monthUsage = (clone $usageBase)->where('created_at', '>=', $monthStart)->where('created_at', '<', $monthEnd);
            $known = (clone $monthUsage)->whereNotNull('total_cost_micros')->exists();
            $cost = $known ? (int) (clone $monthUsage)->sum('total_cost_micros') : null;
            $chart[] = ['label' => $month->format('M Y'), 'revenue_cents' => $rev, 'cost_micros' => $cost];
            $chartMax = max($chartMax, $rev / 100, ($cost ?? 0) / 1_000_000);
            $month = $nextMonth;
        }

        $dailyRevenueRows = DB::table('billing_payments')->select('paid_at','amount_cents')
            ->where('status', 'paid')->where('mode', 'live')->whereNotNull('verified_at')->where('currency', 'USD')
            ->where('paid_at', '>=', $rangeStart)->where('paid_at', '<', $rangeEnd)->cursor();
        $dailyRevenue = [];
        foreach ($dailyRevenueRows as $payment) {
            $localDate = CarbonImmutable::parse($payment->paid_at, $timezone)->toDateString();
            $dailyRevenue[$localDate] = ($dailyRevenue[$localDate] ?? 0) + (int) $payment->amount_cents;
        }
        $dailyCostRows = DB::table('ai_usage_logs')->select('created_at','total_cost_micros')
            ->whereNotNull('total_cost_micros')->where('created_at', '>=', $rangeStart)->where('created_at', '<', $rangeEnd)
            ->cursor();
        $dailyCosts = [];
        foreach ($dailyCostRows as $usage) {
            $localDate = CarbonImmutable::parse($usage->created_at, $timezone)->toDateString();
            $dailyCosts[$localDate] = ($dailyCosts[$localDate] ?? 0) + (int) $usage->total_cost_micros;
        }
        $dailyChart = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $date = $day->toDateString();
            $dailyChart[] = ['date' => $date, 'label' => $day->format('d M'),
                'revenue' => (int) ($dailyRevenue[$date] ?? 0) / 100,
                'cost' => (int) ($dailyCosts[$date] ?? 0) / 1_000_000];
        }

        return view('admin.overview', [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'stats' => [
                'users' => $totalUsers, 'new_users' => $newUsers, 'previous_users' => $previousUsers,
                'active_subscriptions' => $activeSubscriptions,
                'revenue_cents' => (int) $revenueCents, 'previous_revenue_cents' => (int) $previousRevenueCents,
                'scans' => $scanCount, 'previous_scans' => $previousScans,
                'cost_micros' => $costMicros === null ? null : (int) $costMicros,
                'previous_cost_micros' => $previousCostKnown ? (int) $previousCostMicros : null,
                'priced_requests' => $pricedRequests, 'unpriced_requests' => $unpricedRequests,
                'gross_difference_cents' => $costMicros === null ? null : (int) $revenueCents - (int) round($costMicros / 10_000),
            ],
            'chart' => $chart, 'chartMax' => max($chartMax, 1), 'dailyChart' => $dailyChart,
            'lastUpdated' => now($timezone),
        ]);
    }
}
