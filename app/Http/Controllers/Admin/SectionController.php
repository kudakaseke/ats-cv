<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SectionController extends Controller
{
    private const SECTIONS = [
        'users' => ['User Analytics', 'Total customers by current plan, new registrations, active users, returning users and user growth.'],
        'revenue' => ['Subscriptions & Revenue', 'Free, Monthly, Pro and Annual plans, successful and failed payments, renewals and churn.'],
        'cv' => ['CV Analytics', 'CV uploads, ATS scans, CV revamps, CV creations and average ATS scores.'],
        'ai' => ['AI Usage & Costs', 'AI requests, input/output tokens, model usage, API spending, cost per CV scan and estimated profit.'],
        'credits' => ['Credit Analytics', 'Credits allocated and consumed in the selected period, live balances by plan and transaction history.'],
        'accounts' => ['Users Management', 'Search accounts, see plan status, credit balances, activity and suspend accounts when needed.'],
        'reviews' => ['Reviews & Feedback', 'Customer ratings, written reviews, complaints, suggestions and feedback moderation.'],
        'system' => ['System Monitoring', 'Application errors, payment notifications, AI failures and admin audit events.'],
    ];

    public function show(string $section, Request $request): \Symfony\Component\HttpFoundation\Response|View
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        [$title, $description] = self::SECTIONS[$section];
        $stats = [];
        $planBreakdown = [];
        $creditPlanBreakdown = [];
        $totalUsers = 0;
        $rows = collect();
        $columns = [];
        $search = trim((string) $request->query('search', ''));
        $filters = [];
        $incidents = ['count' => 0, 'entries' => []];
        $range = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $timezone = config('app.timezone', 'UTC');
        $to = isset($range['to']) ? CarbonImmutable::parse($range['to'], $timezone)->startOfDay() : CarbonImmutable::now($timezone)->startOfDay();
        $from = isset($range['from']) ? CarbonImmutable::parse($range['from'], $timezone)->startOfDay() : $to;
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            abort(422, 'Choose a date range of one year or less.');
        }
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $dailyChart = $this->dailyChart($section, $from, $to);

        switch ($section) {
            case 'users':
                $totalUsers = DB::table('users')->where('role', 'user')->count();
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $activeSessions = DB::table('account_sessions')
                    ->join('users', 'users.id', '=', 'account_sessions.user_id')
                    ->where('users.role', 'user')
                    ->where('account_sessions.last_activity', '>=', $periodStart->timestamp)
                    ->where('account_sessions.last_activity', '<', $periodEnd->timestamp)
                    ->select('account_sessions.user_id');
                $activeEvents = DB::table('audit_events')
                    ->join('users', 'users.id', '=', 'audit_events.user_id')
                    ->where('users.role', 'user')
                    ->where('audit_events.created_at', '>=', $periodStart)
                    ->where('audit_events.created_at', '<', $periodEnd)
                    ->select('audit_events.user_id');
                $activeUsersInPeriod = DB::query()
                    ->fromSub($activeSessions->union($activeEvents), 'period_activity')
                    ->distinct()
                    ->count('user_id');
                $returningSessions = DB::table('account_sessions')
                    ->join('users', 'users.id', '=', 'account_sessions.user_id')
                    ->where('users.role', 'user')
                    ->where('users.created_at', '<', $periodStart)
                    ->where('account_sessions.last_activity', '>=', $periodStart->timestamp)
                    ->where('account_sessions.last_activity', '<', $periodEnd->timestamp)
                    ->select('account_sessions.user_id');
                $returningEvents = DB::table('audit_events')
                    ->join('users', 'users.id', '=', 'audit_events.user_id')
                    ->where('users.role', 'user')
                    ->where('users.created_at', '<', $periodStart)
                    ->where('audit_events.created_at', '>=', $periodStart)
                    ->where('audit_events.created_at', '<', $periodEnd)
                    ->select('audit_events.user_id');
                $returningUsersInPeriod = DB::query()
                    ->fromSub($returningSessions->union($returningEvents), 'period_returning_activity')
                    ->distinct()
                    ->count('user_id');
                $validStatuses = Subscription::availableStatuses();
                $asOf = now();
                $applyCurrentSubscriptionFilters = static function ($query, string $alias) use ($validStatuses, $asOf): void {
                    $query->whereIn($alias.'.status', $validStatuses)
                        ->where($alias.'.starts_at', '<=', $asOf)
                        ->where(fn ($active) => $active->whereNull($alias.'.ends_at')->orWhere($alias.'.ends_at', '>', $asOf));
                };
                $currentPlanCounts = DB::table('subscriptions as current_subscription')
                    ->join('users', 'users.id', '=', 'current_subscription.user_id')
                    ->join('subscription_plans', 'subscription_plans.id', '=', 'current_subscription.subscription_plan_id')
                    ->where('users.role', 'user')
                    ->whereIn('current_subscription.status', $validStatuses)
                    ->where('current_subscription.starts_at', '<=', $asOf)
                    ->where(fn ($active) => $active->whereNull('current_subscription.ends_at')->orWhere('current_subscription.ends_at', '>', $asOf))
                    ->whereNotExists(function ($newer) use ($applyCurrentSubscriptionFilters) {
                        $newer->selectRaw('1')->from('subscriptions as newer_subscription')
                            ->whereColumn('newer_subscription.user_id', 'current_subscription.user_id');
                        $applyCurrentSubscriptionFilters($newer, 'newer_subscription');
                        $newer->where(function ($ordered) {
                            $ordered->whereColumn('newer_subscription.created_at', '>', 'current_subscription.created_at')
                                ->orWhere(fn ($sameTime) => $sameTime
                                    ->whereColumn('newer_subscription.created_at', 'current_subscription.created_at')
                                    ->whereColumn('newer_subscription.id', '>', 'current_subscription.id'));
                        });
                    })
                    ->select('subscription_plans.slug', DB::raw('count(distinct current_subscription.user_id) as users'))
                    ->groupBy('subscription_plans.slug')
                    ->pluck('users', 'slug');
                $plans = DB::table('subscription_plans')->whereIn('slug', ['monthly', 'pro', 'annual'])->get()->keyBy('slug');
                $formatPlanPrice = static function (?object $plan, string $interval = ''): string {
                    if (! $plan) {
                        return 'price unavailable';
                    }
                    $currency = $plan->currency === 'USD' ? '$' : $plan->currency.' ';

                    $price = $currency.number_format($plan->price_cents / 100, 2);

                    return $interval === '' ? $price : $price.'/'.$interval;
                };
                $paidSlugs = ['monthly', 'pro', 'annual'];
                $paidUsers = collect($currentPlanCounts)->except('free')->sum();
                $otherPlanUsers = collect($currentPlanCounts)->except([...$paidSlugs, 'free'])->sum();
                $stats = [
                    'All-time users' => $totalUsers,
                    'New users in selected period' => DB::table('users')->where('role', 'user')->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd)->count(),
                    'Active users in selected period' => $activeUsersInPeriod,
                    'Returning users in selected period' => $returningUsersInPeriod,
                ];
                $planBreakdown = [
                    ['name' => 'Free', 'price' => $formatPlanPrice(DB::table('subscription_plans')->where('slug', 'free')->first()), 'users' => max(0, $totalUsers - $paidUsers)],
                    ['name' => 'Basic', 'price' => $formatPlanPrice($plans->get('monthly'), 'month'), 'users' => (int) ($currentPlanCounts['monthly'] ?? 0)],
                    ['name' => 'Pro', 'price' => $formatPlanPrice($plans->get('pro'), 'month'), 'users' => (int) ($currentPlanCounts['pro'] ?? 0)],
                    ['name' => 'Annual', 'price' => $formatPlanPrice($plans->get('annual'), 'year'), 'users' => (int) ($currentPlanCounts['annual'] ?? 0)],
                ];
                if ($otherPlanUsers > 0) {
                    $planBreakdown[] = ['name' => 'Other / legacy', 'price' => 'Retired or unlisted plans', 'users' => $otherPlanUsers];
                }
                $rows = DB::table('users')->where('role', 'user')->where('created_at','>=',$from)->where('created_at','<',$to->addDay())->select('id', 'name', 'email', 'created_at', 'account_status')->latest()->paginate(50)->withQueryString();
                $columns = ['name' => 'Name', 'email' => 'Email', 'account_status' => 'Status', 'created_at' => 'Registered'];
                break;
            case 'revenue':
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $asOf = now($timezone);
                $verifiedPayments = DB::table('billing_payments')->where('status','paid')->where('mode','live')
                    ->whereNotNull('verified_at')->where('currency','USD')->where('paid_at','>=',$periodStart)->where('paid_at','<',$periodEnd);
                $failedPayments = DB::table('billing_payments')->where('status','failed')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd);
                $stats = [
                    'Verified live revenue in selected period' => 'US$'.number_format((clone $verifiedPayments)->sum('amount_cents') / 100, 2),
                    'Successful payments in selected period' => (clone $verifiedPayments)->count(),
                    'Failed payments in selected period' => (clone $failedPayments)->count(),
                    'Currently active subscriptions' => DB::table('subscriptions')->join('users', 'users.id', '=', 'subscriptions.user_id')
                        ->where('users.role', 'user')->whereIn('subscriptions.status', Subscription::availableStatuses())
                        ->where('subscriptions.starts_at', '<=', $asOf)
                        ->where(fn ($active) => $active->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', $asOf))->count(),
                    'Renewal payments in selected period' => (clone $verifiedPayments)->where('kind','renewal')->count(),
                    'Cancellations in selected period' => DB::table('subscriptions')->whereNotNull('cancelled_at')->where('cancelled_at','>=',$periodStart)->where('cancelled_at','<',$periodEnd)->count(),
                ];
                $rows = DB::table('billing_payments')->join('users','billing_payments.user_id','=','users.id')->leftJoin('subscription_plans','billing_payments.subscription_plan_id','=','subscription_plans.id')
                    ->where(fn ($periodQuery) => $periodQuery
                        ->where(fn ($created) => $created->where('billing_payments.created_at','>=',$periodStart)->where('billing_payments.created_at','<',$periodEnd))
                        ->orWhere(fn ($paid) => $paid->whereNotNull('billing_payments.paid_at')->where('billing_payments.paid_at','>=',$periodStart)->where('billing_payments.paid_at','<',$periodEnd)))
                    ->select('billing_payments.reference','users.email','subscription_plans.name as plan','billing_payments.status','billing_payments.amount_cents','billing_payments.currency','billing_payments.created_at')->latest('billing_payments.created_at')->paginate(50)->withQueryString()->through(function ($payment) {
                    $payment->amount = $payment->currency.' '.number_format($payment->amount_cents / 100, 2);
                    return $payment;
                });
                $columns = ['reference' => 'Payment reference', 'email' => 'Customer', 'plan' => 'Plan', 'status' => 'Status', 'amount' => 'Amount', 'created_at' => 'Created'];
                break;
            case 'cv':
                $periodStart = $from;
                $periodEnd = $to->addDay();
                // Uploads are parsed without necessarily creating a saved resume.
                // Count successful import requests, not saved resume records.
                $uploadsInPeriod = DB::table('audit_events')->join('users', 'users.id', '=', 'audit_events.user_id')
                    ->where('users.role', 'user')->where('audit_events.event', 'account.request')
                    ->whereIn('audit_events.source', ['checker.upload', 'builder.import'])
                    ->where('audit_events.details->http_status', 200)
                    ->where('audit_events.created_at', '>=', $periodStart)->where('audit_events.created_at', '<', $periodEnd);
                $failedImports = DB::table('audit_events')->join('users', 'users.id', '=', 'audit_events.user_id')
                    ->where('users.role', 'user')->where('audit_events.event', 'account.request')
                    ->whereIn('audit_events.source', ['checker.upload', 'builder.import'])
                    ->where('audit_events.details->http_status', '>=', 400)
                    ->where('audit_events.created_at', '>=', $periodStart)->where('audit_events.created_at', '<', $periodEnd);
                $failedScans = DB::table('ai_operations')->where('feature', 'analyse')->where('status', 'failed')
                    ->where('updated_at', '>=', $periodStart)->where('updated_at', '<', $periodEnd);
                $failedRevamps = DB::table('ai_operations')->where('feature', 'revamp')->where('status', 'failed')
                    ->where('updated_at', '>=', $periodStart)->where('updated_at', '<', $periodEnd);
                $scansInPeriod = DB::table('cv_analyses')->where('status', 'completed')
                    ->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd);
                $revampsInPeriod = DB::table('ai_operations')->where('feature', 'revamp')->where('status', 'completed')
                    ->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd);
                $creationsInPeriod = DB::table('resumes')->where('source', 'created')
                    ->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd);
                $stats = [
                    'Successful signed-in CV imports in selected period' => (clone $uploadsInPeriod)->count(),
                    'Failed signed-in CV imports in selected period' => (clone $failedImports)->count(),
                    'Failed ATS scan operations in selected period' => (clone $failedScans)->count(),
                    'Failed CV revamp operations in selected period' => (clone $failedRevamps)->count(),
                    'Completed ATS scans in selected period' => (clone $scansInPeriod)->count(),
                    'CV revamps in selected period' => (clone $revampsInPeriod)->count(),
                    'CV creations in selected period' => (clone $creationsInPeriod)->count(),
                    'Average recorded Wording Match in selected period' => round((float) (clone $scansInPeriod)->whereNotNull('score')->avg('score'), 1),
                ];
                $rows = DB::table('cv_analyses')->join('users', 'cv_analyses.user_id', '=', 'users.id')
                    ->leftJoin('resumes', 'cv_analyses.resume_id', '=', 'resumes.id')
                    ->where('cv_analyses.status','completed')->where('cv_analyses.created_at','>=',$periodStart)
                    ->where('cv_analyses.created_at','<',$periodEnd)
                    ->select('users.name as customer_name','users.email as customer_email',DB::raw("coalesce(resumes.name, 'CV text scan') as cv_name"),'cv_analyses.job_title','cv_analyses.score','cv_analyses.created_at')
                    ->latest('cv_analyses.created_at')->paginate(50)->withQueryString();
                $columns = ['customer_name' => 'Customer', 'customer_email' => 'Email', 'cv_name' => 'CV', 'job_title' => 'Job title', 'score' => 'Recorded Wording Match', 'created_at' => 'Scanned'];
                break;
            case 'ai':
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $usageInPeriod = DB::table('ai_usage_logs')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd);
                $knownCost = (int) (clone $usageInPeriod)->whereNotNull('total_cost_micros')->sum('total_cost_micros');
                $knownCount = (clone $usageInPeriod)->whereNotNull('total_cost_micros')->count();
                $verifiedRevenue = (int) DB::table('billing_payments')->where('status','paid')->where('mode','live')->whereNotNull('verified_at')->where('currency','USD')->where('paid_at','>=',$periodStart)->where('paid_at','<',$periodEnd)->sum('amount_cents');
                $grossDifference = $verifiedRevenue / 100 - $knownCost / 1_000_000;
                $stats = [
                    'AI requests in selected period' => (clone $usageInPeriod)->count(),
                    'Requests without known cost' => (clone $usageInPeriod)->whereNull('total_cost_micros')->count(),
                    'Input tokens in selected period' => number_format((clone $usageInPeriod)->sum('input_tokens')),
                    'Output tokens in selected period' => number_format((clone $usageInPeriod)->sum('output_tokens')),
                    'Known AI cost in selected period' => 'US$'.number_format($knownCost / 1_000_000, 4),
                    'Average cost per priced request' => $knownCount ? 'US$'.number_format($knownCost / $knownCount / 1_000_000, 5) : 'No priced requests in period',
                    'Revenue less known AI costs in selected period' => 'US$'.number_format($grossDifference, 2),
                ];
                $rows = DB::table('ai_usage_logs')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd)->select('feature','provider','model',DB::raw('count(*) as requests'),DB::raw('sum(input_tokens) as input_tokens'),DB::raw('sum(output_tokens) as output_tokens'),DB::raw('sum(total_cost_micros) as cost_micros'), DB::raw('sum(case when total_cost_micros is null then 1 else 0 end) as unpriced_requests'))->groupBy('feature','provider','model')->orderByDesc('requests')->paginate(30)->withQueryString()->through(function ($row) {
                    if ($row->cost_micros === null) {
                        $row->cost_usd = 'Not priced';
                    } else {
                        $amount = (int) $row->cost_micros / 1_000_000;
                        $row->cost_usd = '$'.($amount > 0 && $amount < 0.01 ? rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.') : number_format($amount, 2));
                        if ($row->unpriced_requests > 0) $row->cost_usd .= ' ('.number_format($row->unpriced_requests).' unpriced)';
                    }
                    return $row;
                });
                $columns = ['feature' => 'Feature', 'provider' => 'Provider', 'model' => 'Model', 'requests' => 'Requests', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'cost_usd' => 'Cost (USD)'];
                break;
            case 'credits':
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $customerCreditMovements = DB::table('ai_credit_transactions')->join('users','ai_credit_transactions.user_id','=','users.id')
                    ->where('users.role','user')->where('ai_credit_transactions.created_at','>=',$periodStart)->where('ai_credit_transactions.created_at','<',$periodEnd);
                $creditMovements = (clone $customerCreditMovements)
                    ->selectRaw('coalesce(sum(case when subscription_delta > 0 then subscription_delta else 0 end + case when topup_delta > 0 then topup_delta else 0 end),0) as allocated, coalesce(sum(case when subscription_delta < 0 then -subscription_delta else 0 end + case when topup_delta < 0 then -topup_delta else 0 end),0) as consumed')->first();
                $creditRows = clone $customerCreditMovements;
                $validStatuses = Subscription::availableStatuses();
                $asOf = now();
                $currentCreditPlans = DB::table('subscriptions as current_credit_subscription')
                    ->join('users as customer', 'customer.id', '=', 'current_credit_subscription.user_id')
                    ->where('customer.role','user')->whereIn('current_credit_subscription.status',$validStatuses)
                    ->where('current_credit_subscription.starts_at','<=',$asOf)
                    ->where(fn ($active) => $active->whereNull('current_credit_subscription.ends_at')->orWhere('current_credit_subscription.ends_at','>',$asOf))
                    ->whereNotExists(function ($newer) use ($validStatuses, $asOf) {
                        $newer->selectRaw('1')->from('subscriptions as newer_credit_subscription')
                            ->whereColumn('newer_credit_subscription.user_id','current_credit_subscription.user_id')
                            ->whereIn('newer_credit_subscription.status',$validStatuses)
                            ->where('newer_credit_subscription.starts_at','<=',$asOf)
                            ->where(fn ($active) => $active->whereNull('newer_credit_subscription.ends_at')->orWhere('newer_credit_subscription.ends_at','>',$asOf))
                            ->where(fn ($ordered) => $ordered->whereColumn('newer_credit_subscription.created_at','>','current_credit_subscription.created_at')
                                ->orWhere(fn ($sameTime) => $sameTime->whereColumn('newer_credit_subscription.created_at','current_credit_subscription.created_at')->whereColumn('newer_credit_subscription.id','>','current_credit_subscription.id')));
                    })
                    ->select('current_credit_subscription.user_id','current_credit_subscription.subscription_plan_id');
                $creditPlanBreakdown = DB::table('users as customer')->where('customer.role','user')
                    ->leftJoinSub($currentCreditPlans,'current_credit_plan','current_credit_plan.user_id','=','customer.id')
                    ->leftJoin('subscription_plans','subscription_plans.id','=','current_credit_plan.subscription_plan_id')
                    ->leftJoin('ai_wallets','ai_wallets.user_id','=','customer.id')
                    ->selectRaw("coalesce(subscription_plans.name, 'Free') as plan_name, count(distinct customer.id) as customers, coalesce(sum(ai_wallets.subscription_credits),0) as subscription_credits, coalesce(sum(ai_wallets.topup_credits),0) as topup_credits")
                    ->groupBy('subscription_plans.name')->orderBy('plan_name')->get();
                $stats = [
                    'Credits added in selected period' => number_format((int) $creditMovements->allocated),
                    'Credits deducted in selected period' => number_format((int) $creditMovements->consumed),
                    'Credit transactions in selected period' => (clone $creditRows)->count(),
                    'Credits remaining now' => number_format((int) DB::table('ai_wallets')->join('users','ai_wallets.user_id','=','users.id')->where('users.role','user')->sum(DB::raw('ai_wallets.subscription_credits + ai_wallets.topup_credits'))),
                    'Outstanding debt now' => number_format((int) DB::table('ai_wallets')->join('users','ai_wallets.user_id','=','users.id')->where('users.role','user')->sum('ai_wallets.credit_debt')),
                    'Expired allocations in selected period' => (clone $creditRows)->where('ai_credit_transactions.type','like','%expir%')->count(),
                ];
                $rows = $creditRows->select('users.name','users.email','ai_credit_transactions.type','ai_credit_transactions.subscription_delta','ai_credit_transactions.topup_delta','ai_credit_transactions.debt_delta','ai_credit_transactions.reference','ai_credit_transactions.created_at')->latest('ai_credit_transactions.created_at')->paginate(30)->withQueryString();
                $columns = ['name' => 'Customer', 'email' => 'Email', 'type' => 'Transaction', 'subscription_delta' => 'Subscription credits', 'topup_delta' => 'Top-up credits', 'debt_delta' => 'Debt change', 'reference' => 'Reference', 'created_at' => 'Recorded'];
                break;
            case 'accounts':
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $validStatuses = Subscription::availableStatuses();
                $asOf = now();
                $currentAccountPlan = DB::table('subscriptions as current_account_subscription')
                    ->join('subscription_plans as current_account_plan','current_account_plan.id','=','current_account_subscription.subscription_plan_id')
                    ->whereIn('current_account_subscription.status',$validStatuses)
                    ->where('current_account_subscription.starts_at','<=',$asOf)
                    ->where(fn ($active) => $active->whereNull('current_account_subscription.ends_at')->orWhere('current_account_subscription.ends_at','>',$asOf))
                    ->whereNotExists(function ($newer) use ($validStatuses, $asOf) {
                        $newer->selectRaw('1')->from('subscriptions as newer_account_subscription')
                            ->whereColumn('newer_account_subscription.user_id','current_account_subscription.user_id')
                            ->whereIn('newer_account_subscription.status',$validStatuses)
                            ->where('newer_account_subscription.starts_at','<=',$asOf)
                            ->where(fn ($active) => $active->whereNull('newer_account_subscription.ends_at')->orWhere('newer_account_subscription.ends_at','>',$asOf))
                            ->where(fn ($ordered) => $ordered->whereColumn('newer_account_subscription.created_at','>','current_account_subscription.created_at')
                                ->orWhere(fn ($sameTime) => $sameTime->whereColumn('newer_account_subscription.created_at','current_account_subscription.created_at')->whereColumn('newer_account_subscription.id','>','current_account_subscription.id')));
                    })->select('current_account_subscription.user_id','current_account_plan.name');
                $q = DB::table('users')->where('role','user')
                    ->leftJoin('ai_wallets','users.id','=','ai_wallets.user_id')
                    ->leftJoinSub($currentAccountPlan,'current_account_plan','current_account_plan.user_id','=','users.id')
                    ->select('users.id','users.name','users.email','users.account_status','users.created_at','current_account_plan.name as plan','ai_wallets.subscription_credits','ai_wallets.topup_credits')
                    ->selectSub(DB::table('account_sessions')->selectRaw('max(last_activity)')->whereColumn('account_sessions.user_id','users.id'),'last_activity')->distinct();
                if ($search !== '') {
                    $q->where(fn ($x) => $x->where('users.name','like','%'.$search.'%')->orWhere('users.email','like','%'.$search.'%'));
                }
                $accountFilters = $request->validate(['account_status' => 'nullable|in:active,suspended', 'export' => 'nullable|in:csv']);
                $filters['account_status'] = $accountFilters['account_status'] ?? null;
                if (! empty($accountFilters['account_status'])) $q->where('users.account_status', $accountFilters['account_status']);
                if (($accountFilters['export'] ?? null) === 'csv') {
                    $exportCount = (clone $q)->count();
                    app(\App\Services\Admin\AdminAuditTrail::class)->record('users.exported', 'UserList', null, 'Customer list exported (maximum 1000 records).', null, ['search' => $search, 'account_status' => $accountFilters['account_status'] ?? null, 'matching_count' => $exportCount, 'exported_count' => min(1000, $exportCount)]);
                    return response()->streamDownload(function () use ($q) {
                        $file = fopen('php://output', 'w');
                        fputcsv($file, ['User ID', 'Name', 'Email', 'Status', 'Registered']);
                        foreach ($q->orderBy('users.id')->limit(1000)->get() as $row) {
                            fputcsv($file, array_map(fn ($value) => preg_match('/^[\s]*[=+@\-\t\r\n]/u', (string) $value) ? "'".$value : $value,
                                [$row->id, $row->name, $row->email, $row->account_status, $row->created_at]));
                        }
                        fclose($file);
                    }, 'users.csv');
                }
                $stats = [
                    'Customer accounts created in selected period' => DB::table('users')->where('role','user')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd)->count(),
                    'Customer activity events in selected period' => DB::table('audit_events')->join('users','audit_events.user_id','=','users.id')->where('users.role','user')->where('audit_events.created_at','>=',$periodStart)->where('audit_events.created_at','<',$periodEnd)->count(),
                    'Accounts with activity in selected period' => DB::table('audit_events')->join('users','audit_events.user_id','=','users.id')->where('users.role','user')->where('audit_events.created_at','>=',$periodStart)->where('audit_events.created_at','<',$periodEnd)->distinct('audit_events.user_id')->count('audit_events.user_id'),
                ];
                $rows = $q->orderByDesc('users.created_at')->paginate(25)->withQueryString();
                $columns = ['name' => 'Name', 'email' => 'Email', 'plan' => 'Plan', 'account_status' => 'Status', 'subscription_credits' => 'Subscription credits', 'topup_credits' => 'Top-up credits', 'last_activity' => 'Last activity'];
                break;
            case 'reviews':
                $filters = $request->validate(['rating' => 'nullable|integer|min:1|max:5', 'category' => 'nullable|in:cv_analysis,account,billing,complaint,suggestion,other', 'status' => 'nullable|in:pending,approved,rejected', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
                $filters['from'] = $filters['from'] ?? $period['from'];
                $filters['to'] = $filters['to'] ?? $period['to'];
                $reviewStart = CarbonImmutable::parse($filters['from'], $timezone)->startOfDay();
                $reviewEnd = CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay();
                $reviewQuery = DB::table('customer_feedback')->join('users','customer_feedback.user_id','=','users.id')->select('customer_feedback.*','users.name','users.email');
                foreach (['rating','category','status'] as $filter) if (! empty($filters[$filter])) $reviewQuery->where('customer_feedback.'.$filter,$filters[$filter]);
                $reviewQuery->where('customer_feedback.created_at','>=',$reviewStart)->where('customer_feedback.created_at','<',$reviewEnd);
                $rows = $reviewQuery->latest('customer_feedback.created_at')->paginate(25)->withQueryString();
                $columns = ['name' => 'Customer', 'category' => 'Category', 'rating' => 'Rating', 'body' => 'Feedback', 'status' => 'Status', 'created_at' => 'Received'];
                $feedbackInPeriod = DB::table('customer_feedback')->where('created_at','>=',$reviewStart)->where('created_at','<',$reviewEnd);
                $stats = ['Reviews' => (clone $feedbackInPeriod)->count(), 'Average rating' => round((float) (clone $feedbackInPeriod)->whereNotNull('rating')->avg('rating'), 1).'/5', 'Pending' => (clone $feedbackInPeriod)->where('status','pending')->count(), 'Complaints' => (clone $feedbackInPeriod)->where('category','complaint')->count()];
                break;
            case 'system':
                $incidents = $this->recentIncidents($from, $to);
                $periodStart = $from;
                $periodEnd = $to->addDay();
                $notificationsInPeriod = DB::table('payment_notifications')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd);
                $stats = [
                    'Errors found in recent logs' => $incidents['count'],
                    'Payment notifications in selected period' => (clone $notificationsInPeriod)->count(),
                    'Unprocessed notifications in selected period' => (clone $notificationsInPeriod)->whereNull('processed_at')->count(),
                    'AI/API failures in selected period' => DB::table('ai_usage_logs')->where('status','failed')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd)->count(),
                    'Admin audit events in selected period' => DB::table('admin_audit_logs')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd)->count(),
                ];
                $rows = DB::table('admin_audit_logs')->where('created_at','>=',$periodStart)->where('created_at','<',$periodEnd)->latest('created_at')->paginate(50)->withQueryString();
                $columns = ['actor_id' => 'Admin user ID', 'action' => 'Action', 'subject_type' => 'Record type', 'subject_id' => 'Record ID', 'description' => 'Details', 'created_at' => 'Time'];
                break;
        }
        return view('admin.section', compact('section','title','description','stats','planBreakdown','creditPlanBreakdown','totalUsers','rows','columns','search','filters','incidents','period','dailyChart'));
    }

    private function dailyChart(string $section, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$table, $dateColumn, $valueColumn, $condition, $label, $unit] = match ($section) {
            'users' => ['users', 'created_at', null, fn ($q) => $q->where('role', 'user'), 'New registrations per day', 'users'],
            'revenue' => ['billing_payments', 'paid_at', 'amount_cents', fn ($q) => $q->where('status', 'paid')->where('mode', 'live')->whereNotNull('verified_at')->where('currency', 'USD'), 'Verified revenue per day', 'USD'],
            'cv' => ['cv_analyses', 'created_at', null, fn ($q) => $q->where('status', 'completed'), 'Completed ATS scans per day', 'scans'],
            'ai' => ['ai_usage_logs', 'created_at', 'total_cost_micros', fn ($q) => $q->whereNotNull('total_cost_micros'), 'Known AI cost per day', 'USD'],
            'credits' => ['ai_credit_transactions', 'created_at', null, fn ($q) => $q->whereExists(fn ($users) => $users->selectRaw('1')->from('users')->whereColumn('users.id','ai_credit_transactions.user_id')->where('users.role','user')), 'Credit transactions per day', 'transactions'],
            'accounts' => ['audit_events', 'created_at', null, fn ($q) => $q->whereExists(fn ($users) => $users->selectRaw('1')->from('users')->whereColumn('users.id', 'audit_events.user_id')->where('users.role', 'user')), 'Customer activity events per day', 'events'],
            'reviews' => ['customer_feedback', 'created_at', null, fn ($q) => $q, 'Feedback received per day', 'reviews'],
            'system' => ['admin_audit_logs', 'created_at', null, fn ($q) => $q, 'Admin audit events per day', 'events'],
        };

        $query = DB::table($table)->where($dateColumn, '>=', $from)->where($dateColumn, '<', $to->addDay());
        $condition($query);
        $values = [];
        $selectedColumns = $valueColumn ? [$dateColumn, $valueColumn] : [$dateColumn];
        foreach ($query->select($selectedColumns)->cursor() as $record) {
            $localDate = CarbonImmutable::parse($record->{$dateColumn}, config('app.timezone', 'UTC'))->toDateString();
            $values[$localDate] = ($values[$localDate] ?? 0) + ($valueColumn ? (float) $record->{$valueColumn} : 1);
        }
        $points = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $raw = (float) ($values[$date] ?? 0);
            $points[] = ['date' => $date, 'label' => $day->format('d M'), 'value' => $unit === 'USD' ? $raw / ($section === 'revenue' ? 100 : 1_000_000) : $raw];
        }
        return ['title' => $label, 'unit' => $unit, 'points' => $points];
    }

    private function recentIncidents(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $files = glob(storage_path('logs/incidents-*.log')) ?: [];
        rsort($files, SORT_STRING);
        $entries = [];
        foreach ($files as $filePath) {
            if (! is_file($filePath) || ! is_readable($filePath)) continue;
            $handle = fopen($filePath, 'rb');
            if (! $handle) continue;
            try {
                $size = filesize($filePath);
                fseek($handle, max(0, $size - 1048576));
                if ($size > 1048576) fgets($handle);
                while (($line = fgets($handle)) !== false) {
                    $entry = json_decode($line, true);
                    if (! is_array($entry)) continue;
                    $context = $entry['context'] ?? [];
                    $time = $entry['datetime'] ?? '';
                    try { $eventTime = CarbonImmutable::parse($time, config('app.timezone')); }
                    catch (\Throwable) { continue; }
                    if ($eventTime < $from || $eventTime >= $to->addDay()) continue;
                    $entries[] = ['time' => $time, 'reference' => $context['reference'] ?? '',
                        'type' => $context['type'] ?? '', 'location' => ($context['file'] ?? '').':'.($context['line'] ?? ''),
                        'route' => $context['route'] ?? 'background/console'];
                }
            } finally { fclose($handle); }
        }
        usort($entries, static fn ($left, $right) => strtotime($right['time']) <=> strtotime($left['time']));

        return ['count' => count($entries), 'entries' => array_slice($entries, 0, 30)];
    }

    public function paymentHistory(int $user): View
    {
        $customer = DB::table('users')->where('role', 'user')->where('id', $user)->first(['id', 'name', 'email']);
        abort_unless($customer, 404);

        if (request()->routeIs('admin.users.payments')) {
            app(\App\Services\Admin\AdminAuditTrail::class)->record('user.payments_viewed', 'User', $customer->id, 'User payment history viewed.');
        }

        $payments = DB::table('billing_payments')
            ->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
            ->leftJoin('billing_receipts', 'billing_receipts.billing_payment_id', '=', 'billing_payments.id')
            ->where('billing_payments.user_id', $customer->id)
            ->select('billing_payments.reference', 'billing_payments.status', 'billing_payments.mode',
                'billing_payments.amount_cents', 'billing_payments.currency', 'billing_payments.credits', 'billing_payments.created_at',
                'billing_payments.paid_at', 'billing_payments.verified_at', 'subscription_plans.name as plan', 'billing_receipts.number as receipt_number')
            ->orderByDesc('billing_payments.created_at')->orderByDesc('billing_payments.id')
            ->paginate(25)->withQueryString();

        $wallet = (object) app(\App\Services\AiCreditService::class)->wallet(\App\Models\User::findOrFail($customer->id));
        $creditTransactions = DB::table('ai_credit_transactions')->where('user_id', $customer->id)
            ->orderByDesc('id')->paginate(25, ['*'], 'credits_page')->withQueryString();

        $grantDetails = DB::table('admin_audit_logs')->where('action', 'credits.admin_grant')
            ->where('subject_type', 'User')->where('subject_id', (string) $customer->id)
            ->orderByDesc('id')->get(['new_values'])->mapWithKeys(function ($entry) {
                $details = json_decode($entry->new_values ?? '{}', true);
                return ! empty($details['credit_reference']) ? [$details['credit_reference'] => $details] : [];
            });

        return view('admin.payment-history', compact('customer', 'payments', 'wallet', 'creditTransactions', 'grantDetails'));
    }

    public function grantCredits(Request $request, int $user)
    {
        $customer = \App\Models\User::where('role', 'user')->findOrFail($user);
        $data = $request->validate([
            'credits' => 'required|integer|min:1|max:100000',
            'reason' => 'required|string|min:10|max:500',
            'payment_reference' => 'nullable|uuid',
            'request_id' => 'required|uuid',
        ]);
        if (! empty($data['payment_reference']) && ! DB::table('billing_payments')
            ->where('user_id', $customer->id)->where('reference', $data['payment_reference'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payment_reference' => 'This payment does not belong to the selected user.']);
        }
        $created = app(\App\Services\AiCreditService::class)->grantAdminCredits(
            $customer, (int) $data['credits'], 'admin-grant:'.$data['request_id'], trim($data['reason']), $data['payment_reference'] ?? null
        );

        $destination = $request->input('return_to_profile') === '1'
            ? route('admin.users.show', ['user' => $customer->id, 'tab' => 'credits'])
            : route('admin.users.payments', $customer->id);
        return redirect($destination)->with('status', $created
            ? 'Credits added and recorded in the credit ledger.' : 'This credit grant was already recorded.');
    }

    public function accountAction(Request $request, int $user)
    {
        $target = DB::table('users')->where('id',$user)->where('role','user')->first();
        abort_unless($target, 404);
        $data = $request->validate(['action' => 'required|in:suspend,restore', 'reason' => 'nullable|string|max:500']);
        abort_if($data['action'] === 'suspend' && trim((string) ($data['reason'] ?? '')) === '', 422, 'A reason is required to suspend an account.');
        $old = ['account_status' => $target->account_status, 'suspension_reason' => $target->suspension_reason];
        $values = $data['action'] === 'suspend' ? ['account_status' => 'suspended','suspended_at' => now(),'suspension_reason' => $data['reason']] : ['account_status' => 'active','suspended_at' => null,'suspension_reason' => null];
        DB::transaction(function () use ($user, $values, $data, $old, $request) {
            DB::table('users')->where('id', $user)->update([...$values, 'updated_at' => now(), ...($data['action'] === 'suspend' ? ['remember_token' => \Illuminate\Support\Str::random(60), 'session_version' => DB::raw('session_version + 1')] : [])]);
            app(\App\Services\Admin\AdminAuditTrail::class)->record('user.'.$data['action'], 'User', $user, 'Account status changed by super admin.', $old, $values);
            app(\App\Services\AuditTrail::class)->record($user, 'account.'.$data['action'], 'User', $user, ['actor_admin_id' => $request->user('admin')->id]);
        });
        if ($data['action'] === 'suspend') {
            app(\App\Services\AccountSessions::class)->erase(\App\Models\User::findOrFail($user));
        }
        return back()->with('status', 'User account '.($data['action'] === 'suspend' ? 'suspended' : 'restored').'. Change recorded against your super-admin account.');
    }
}
