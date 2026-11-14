<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SectionController extends Controller
{
    private const SECTIONS = [
        'users' => ['User Analytics', 'New registrations, active users, returning users, user growth and activity.'],
        'revenue' => ['Subscriptions & Revenue', 'Free, Monthly, Pro and Annual plans, successful and failed payments, renewals and churn.'],
        'cv' => ['CV Analytics', 'CV uploads, ATS scans, CV revamps, CV creations, average ATS scores and frequently missing skills.'],
        'ai' => ['AI Usage & Costs', 'AI requests, input/output tokens, model usage, API spending, cost per CV scan and estimated profit.'],
        'credits' => ['Credit Analytics', 'Credits allocated, consumed, remaining and expired; usage by plan.'],
        'accounts' => ['Users Management', 'Search accounts, see plan status, credit balances, activity and suspend accounts when needed.'],
        'reviews' => ['Reviews & Feedback', 'Customer ratings, written reviews, complaints, suggestions and feedback moderation.'],
        'system' => ['System Monitoring', 'Errors, payment webhooks, API failures, server performance, audit logs and security events.'],
    ];

    public function show(string $section, Request $request): View
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        [$title, $description] = self::SECTIONS[$section];
        $stats = [];
        $rows = collect();
        $columns = [];
        $search = trim((string) $request->query('search', ''));
        $filters = [];
        $incidents = [];

        switch ($section) {
            case 'users':
                $stats = ['Total users' => DB::table('users')->where('role', 'user')->count(), 'New this month' => DB::table('users')->where('role', 'user')->where('created_at', '>=', now()->startOfMonth())->count(), 'Active in 30 days' => DB::table('account_sessions')->where('last_activity', '>=', now()->subDays(30)->timestamp)->distinct('user_id')->count('user_id'), 'Returning users' => DB::table('audit_events')->join('users','audit_events.user_id','=','users.id')->where('users.created_at','<',now()->subDays(30))->where('audit_events.created_at','>=',now()->subDays(30))->distinct('audit_events.user_id')->count('audit_events.user_id')];
                $rows = DB::table('users')->where('role', 'user')->select('id', 'name', 'email', 'created_at', 'account_status')->latest()->limit(30)->get();
                $columns = ['name' => 'Name', 'email' => 'Email', 'account_status' => 'Status', 'created_at' => 'Registered'];
                break;
            case 'revenue':
                $stats = ['Verified live revenue (month)' => '$'.number_format(DB::table('billing_payments')->where('status','paid')->where('mode','live')->whereNotNull('verified_at')->where('paid_at','>=',now()->startOfMonth())->sum('amount_cents') / 100, 2), 'Successful payments' => DB::table('billing_payments')->where('status','paid')->where('mode','live')->count(), 'Failed payments' => DB::table('billing_payments')->where('status','failed')->count(), 'Active subscriptions' => DB::table('subscriptions')->where('status','active')->count(), 'Renewal payments' => DB::table('billing_payments')->where('status','paid')->where('kind','renewal')->count(), 'Cancelled subscriptions' => DB::table('subscriptions')->whereNotNull('cancelled_at')->count()];
                $rows = DB::table('subscription_plans')->leftJoin('subscriptions','subscription_plans.id','=','subscriptions.subscription_plan_id')->select('subscription_plans.name','subscription_plans.slug',DB::raw('count(case when subscriptions.status = \'active\' then 1 end) as active_subscribers'))->groupBy('subscription_plans.id','subscription_plans.name','subscription_plans.slug')->orderBy('subscription_plans.id')->get();
                $columns = ['name' => 'Plan', 'slug' => 'Plan key', 'active_subscribers' => 'Active subscribers'];
                break;
            case 'cv':
                $missingCounts = [];
                foreach (DB::table('cv_analyses')->where('status','completed')->whereNotNull('results')->select('results')->cursor() as $analysis) {
                    $result = json_decode($analysis->results, true) ?: [];
                    foreach (($result['missing'] ?? []) as $missing) {
                        $label = is_string($missing) ? trim($missing) : (is_array($missing) ? trim((string) ($missing['requirement'] ?? $missing['name'] ?? '')) : '');
                        if ($label !== '') $missingCounts[$label] = ($missingCounts[$label] ?? 0) + 1;
                    }
                }
                arsort($missingCounts);
                $frequentMissing = collect(array_slice($missingCounts, 0, 5, true))->map(fn ($count, $label) => $label.' ('.$count.')')->implode(', ');
                $stats = ['CV uploads' => DB::table('resumes')->whereNotNull('original_path')->count(), 'ATS scans' => DB::table('cv_analyses')->where('status','completed')->count(), 'CV revamps' => DB::table('ai_operations')->where('feature','revamp')->where('status','completed')->count(), 'CV creations' => DB::table('resumes')->where('source','created')->count(), 'Average ATS score' => round((float) DB::table('cv_analyses')->where('status','completed')->whereNotNull('score')->avg('score'), 1), 'Frequent missing requirements' => $frequentMissing ?: 'No data yet'];
                $rows = DB::table('cv_analyses')->where('status','completed')->select('job_title','score','created_at')->latest()->limit(30)->get();
                $columns = ['job_title' => 'Job title', 'score' => 'ATS score', 'created_at' => 'Scanned'];
                break;
            case 'ai':
                $knownCost = (int) DB::table('ai_usage_logs')->whereNotNull('total_cost_micros')->sum('total_cost_micros');
                $knownCount = DB::table('ai_usage_logs')->whereNotNull('total_cost_micros')->count();
                $verifiedRevenue = (int) DB::table('billing_payments')->where('status','paid')->where('mode','live')->whereNotNull('verified_at')->where('currency','USD')->sum('amount_cents');
                $grossDifference = $verifiedRevenue / 100 - $knownCost / 1_000_000;
                $stats = ['AI requests' => DB::table('ai_usage_logs')->count(), 'Input tokens' => number_format(DB::table('ai_usage_logs')->sum('input_tokens')), 'Output tokens' => number_format(DB::table('ai_usage_logs')->sum('output_tokens')), 'Known AI cost' => '$'.number_format($knownCost / 1_000_000, 4), 'Average cost per priced request' => $knownCount ? '$'.number_format($knownCost / $knownCount / 1_000_000, 5) : 'Not available', 'Revenue less known AI costs' => '$'.number_format($grossDifference, 2)];
                $rows = DB::table('ai_usage_logs')->select('feature','provider','model',DB::raw('count(*) as requests'),DB::raw('sum(input_tokens) as input_tokens'),DB::raw('sum(output_tokens) as output_tokens'),DB::raw('sum(total_cost_micros) as cost_micros'))->groupBy('feature','provider','model')->orderByDesc('requests')->limit(30)->get();
                $columns = ['feature' => 'Feature', 'provider' => 'Provider', 'model' => 'Model', 'requests' => 'Requests', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'cost_micros' => 'Cost (micros USD)'];
                break;
            case 'credits':
                $creditMovements = DB::table('ai_credit_transactions')->selectRaw('coalesce(sum(case when subscription_delta > 0 then subscription_delta else 0 end + case when topup_delta > 0 then topup_delta else 0 end),0) as allocated, coalesce(sum(case when subscription_delta < 0 then -subscription_delta else 0 end + case when topup_delta < 0 then -topup_delta else 0 end),0) as consumed')->first();
                $stats = ['Credits allocated (recorded)' => number_format((int) $creditMovements->allocated), 'Credits consumed (recorded)' => number_format((int) $creditMovements->consumed), 'Credits remaining' => number_format(DB::table('ai_wallets')->sum('subscription_credits') + DB::table('ai_wallets')->sum('topup_credits')), 'Outstanding debt' => number_format(DB::table('ai_wallets')->sum('credit_debt')), 'Expired allocations' => DB::table('ai_credit_transactions')->where('type','like','%expir%')->count()];
                $rows = DB::table('subscription_plans')->leftJoin('subscriptions','subscription_plans.id','=','subscriptions.subscription_plan_id')->leftJoin('ai_wallets','subscriptions.user_id','=','ai_wallets.user_id')->select('subscription_plans.name',DB::raw('count(distinct subscriptions.user_id) as subscribers'),DB::raw('coalesce(sum(ai_wallets.subscription_credits),0) as remaining_credits'))->groupBy('subscription_plans.id','subscription_plans.name')->orderBy('subscription_plans.id')->get();
                $columns = ['name' => 'Plan', 'subscribers' => 'Subscribers', 'remaining_credits' => 'Remaining credits'];
                break;
            case 'accounts':
                $q = DB::table('users')->where('role','user')->leftJoin('ai_wallets','users.id','=','ai_wallets.user_id')->leftJoin('subscriptions',function ($join) { $join->on('users.id','=','subscriptions.user_id')->where('subscriptions.status','=','active'); })->leftJoin('subscription_plans','subscriptions.subscription_plan_id','=','subscription_plans.id')->select('users.id','users.name','users.email','users.account_status','users.created_at','subscription_plans.name as plan','ai_wallets.subscription_credits','ai_wallets.topup_credits')->selectSub(DB::table('account_sessions')->selectRaw('max(last_activity)')->whereColumn('account_sessions.user_id','users.id'),'last_activity')->distinct();
                if ($search !== '') $q->where(fn($x) => $x->where('users.name','like','%'.$search.'%')->orWhere('users.email','like','%'.$search.'%'));
                $rows = $q->orderByDesc('users.created_at')->paginate(25)->withQueryString();
                $columns = ['name' => 'Name', 'email' => 'Email', 'plan' => 'Plan', 'account_status' => 'Status', 'subscription_credits' => 'Subscription credits', 'topup_credits' => 'Top-up credits', 'last_activity' => 'Last activity (Unix time)'];
                break;
            case 'reviews':
                $filters = $request->validate(['rating' => 'nullable|integer|min:1|max:5', 'category' => 'nullable|in:cv_analysis,account,billing,complaint,suggestion,other', 'status' => 'nullable|in:pending,approved,rejected', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
                $reviewQuery = DB::table('customer_feedback')->join('users','customer_feedback.user_id','=','users.id')->select('customer_feedback.*','users.name','users.email');
                foreach (['rating','category','status'] as $filter) if (! empty($filters[$filter])) $reviewQuery->where('customer_feedback.'.$filter,$filters[$filter]);
                if (! empty($filters['from'])) $reviewQuery->whereDate('customer_feedback.created_at','>=',$filters['from']);
                if (! empty($filters['to'])) $reviewQuery->whereDate('customer_feedback.created_at','<=',$filters['to']);
                $rows = $reviewQuery->latest('customer_feedback.created_at')->paginate(25)->withQueryString();
                $columns = ['name' => 'Customer', 'category' => 'Category', 'rating' => 'Rating', 'body' => 'Feedback', 'status' => 'Status', 'created_at' => 'Received'];
                $stats = ['Reviews' => DB::table('customer_feedback')->count(), 'Average rating' => round((float) DB::table('customer_feedback')->whereNotNull('rating')->avg('rating'), 1).'/5', 'Pending' => DB::table('customer_feedback')->where('status','pending')->count(), 'Complaints' => DB::table('customer_feedback')->where('category','complaint')->count()];
                break;
            case 'system':
                $incidents = $this->recentIncidents();
                $stats = ['Application errors (14 days)' => count($incidents), 'Payment notifications' => DB::table('payment_notifications')->count(), 'Unprocessed webhooks' => DB::table('payment_notifications')->whereNull('processed_at')->count(), 'AI/API failures' => DB::table('ai_usage_logs')->where('status','failed')->count(), 'Admin audit events' => DB::table('admin_audit_logs')->count()];
                $rows = DB::table('admin_audit_logs')->latest('created_at')->limit(30)->get(['actor_id','actor_email_hash','action','subject_type','subject_id','description','created_at']);
                $columns = ['actor_id' => 'Admin user ID', 'action' => 'Action', 'subject_type' => 'Record type', 'subject_id' => 'Record ID', 'description' => 'Details', 'created_at' => 'Time'];
                break;
        }
        return view('admin.section', compact('section','title','description','stats','rows','columns','search','filters','incidents'));
    }

    private function recentIncidents(): array
    {
        $files = glob(storage_path('logs/incidents-*.log')) ?: [];
        rsort($files, SORT_STRING);
        $entries = [];
        foreach (array_slice($files, 0, 14) as $filePath) {
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
                    $entries[] = ['time' => $entry['datetime'] ?? '', 'reference' => $context['reference'] ?? '',
                        'type' => $context['type'] ?? '', 'location' => ($context['file'] ?? '').':'.($context['line'] ?? ''),
                        'route' => $context['route'] ?? 'background/console'];
                }
            } finally { fclose($handle); }
        }
        return array_slice(array_reverse($entries), 0, 30);
    }

    public function accountAction(Request $request, int $user)
    {
        $target = DB::table('users')->where('id',$user)->where('role','user')->first();
        abort_unless($target, 404);
        $data = $request->validate(['action' => 'required|in:suspend,restore', 'reason' => 'nullable|string|max:500']);
        abort_if($data['action'] === 'suspend' && trim((string) ($data['reason'] ?? '')) === '', 422, 'A reason is required to suspend an account.');
        $old = ['account_status' => $target->account_status, 'suspension_reason' => $target->suspension_reason];
        $values = $data['action'] === 'suspend' ? ['account_status' => 'suspended','suspended_at' => now(),'suspension_reason' => $data['reason']] : ['account_status' => 'active','suspended_at' => null,'suspension_reason' => null];
        DB::table('users')->where('id',$user)->update([...$values,'updated_at' => now()]);
        if ($data['action'] === 'suspend') {
            app(\App\Services\AccountSessions::class)->erase(\App\Models\User::findOrFail($user));
            DB::table('users')->where('id',$user)->update(['remember_token' => \Illuminate\Support\Str::random(60)]);
        }
        app(\App\Services\Admin\AdminAuditTrail::class)->record('user.'.$data['action'], 'User', $user, 'Account status changed by super admin.', $old, $values);
        app(\App\Services\AuditTrail::class)->record($user, 'account.'.$data['action'], 'User', $user, ['actor_admin_id' => $request->user('admin')->id]);
        return back()->with('status', 'User account '.$data['action'].'ed. Change recorded against your super-admin account.');
    }
}
