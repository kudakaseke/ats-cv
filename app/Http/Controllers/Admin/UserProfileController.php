<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountSessions;
use App\Services\Admin\AdminAuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserProfileController extends Controller
{
    private const TABS = ['profile', 'subscription', 'payments', 'usage', 'credits', 'reviews', 'security', 'audit'];

    public function show(Request $request, int $user): View
    {
        $customer = User::where('role', 'user')->findOrFail($user);
        $tab = $request->query('tab', 'profile');
        abort_unless(in_array($tab, self::TABS, true), 404);
        $data = ['customer' => $customer, 'tab' => $tab];
        if (in_array($tab, ['payments', 'credits'], true)) {
            $data = array_merge(app(SectionController::class)->paymentHistory($user)->getData(), $data);
        }
        if ($tab === 'subscription') {
            $data['subscriptions'] = DB::table('subscriptions')->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
                ->where('subscriptions.user_id', $user)->select('subscriptions.*', 'subscription_plans.name as plan_name')
                ->orderByDesc('subscriptions.starts_at')->orderByDesc('subscriptions.id')->paginate(25)->withQueryString();
            $data['currentSubscription'] = DB::table('subscriptions')->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
                ->where('subscriptions.user_id', $user)->whereIn('subscriptions.status', Subscription::availableStatuses())
                ->where('subscriptions.starts_at', '<=', now())->where(fn ($q) => $q->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now()))
                ->select('subscriptions.*', 'subscription_plans.name as plan_name')->orderByDesc('subscriptions.created_at')->orderByDesc('subscriptions.id')->first();
        }
        if ($tab === 'payments') {
            $data['spending'] = DB::table('billing_payments')->where('user_id', $user)->where('status', 'paid')->where('mode', 'live')
                ->whereNotNull('verified_at')->whereNotNull('paid_at')->selectRaw('currency, sum(amount_cents) as cents')->groupBy('currency')->get();
            $data['reviews'] = DB::table('billing_reviews')->join('billing_payments', 'billing_payments.id', '=', 'billing_reviews.billing_payment_id')
                ->where('billing_payments.user_id', $user)->select('billing_reviews.type', 'billing_reviews.notes', 'billing_reviews.created_at', 'billing_payments.reference')
                ->latest('billing_reviews.created_at')->limit(30)->get();
        }
        if ($tab === 'usage') {
            $data['usage'] = DB::table('ai_usage_logs')->where('user_id', $user)->selectRaw('feature, status, count(*) as total')->groupBy('feature', 'status')->orderBy('feature')->get();
            $data['trends'] = DB::table('ai_usage_logs')->where('user_id', $user)->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('date(created_at) as day, count(*) as total')->groupBy(DB::raw('date(created_at)'))->orderByDesc('day')->get();
        }
        if (in_array($tab, ['usage', 'security'], true)) {
            $data['sessions'] = DB::table('account_sessions')->where('user_id', $user)->orderByDesc('last_activity')->get(['last_activity']);
        }
        if ($tab === 'credits') {
            $data['creditTotals'] = DB::table('ai_credit_transactions')->where('user_id', $user)
                ->selectRaw('coalesce(sum(case when subscription_delta > 0 then subscription_delta else 0 end + case when topup_delta > 0 then topup_delta else 0 end), 0) as awarded, coalesce(sum(case when type = ? then -subscription_delta - topup_delta else 0 end), 0) as expired', ['subscription_expired'])->first();
            $data['consumed'] = DB::table('ai_operations')->where('user_id', $user)->where('status', 'completed')->sum(DB::raw('subscription_spent + topup_spent'));
        }
        if ($tab === 'reviews') {
            $data['feedback'] = DB::table('customer_feedback')->where('user_id', $user)->latest('created_at')->paginate(25)->withQueryString();
        }
        if ($tab === 'audit') {
            $data['events'] = DB::table('admin_audit_logs')->leftJoin('users as actor', 'actor.id', '=', 'admin_audit_logs.actor_id')
                ->where(function ($q) use ($user) {
                    $q->where(fn ($q) => $q->where('admin_audit_logs.subject_type', 'User')->where('admin_audit_logs.subject_id', (string) $user))
                        ->orWhere(fn ($q) => $q->where('admin_audit_logs.subject_type', 'CustomerFeedback')->whereIn('admin_audit_logs.subject_id', DB::table('customer_feedback')->where('user_id', $user)->select('id')))
                        ->orWhere(fn ($q) => $q->where('admin_audit_logs.subject_type', 'BillingPayment')->whereIn('admin_audit_logs.subject_id', DB::table('billing_payments')->where('user_id', $user)->select('id')));
                })->select('admin_audit_logs.*', 'actor.name as actor_name')->orderByDesc('admin_audit_logs.id')->paginate(25)->withQueryString();
        }
        return view('admin.user-profile', $data);
    }

    public function revokeSessions(Request $request, int $user)
    {
        $customer = User::where('role', 'user')->findOrFail($user);
        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);
        if (config('session.driver') === 'cookie' || DB::table('account_sessions')->where('user_id', $customer->id)->where('driver', 'cookie')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sessions' => 'Cookie-based sessions cannot be revoked remotely. Suspend the account to block access, or use a server-side session driver.']);
        }
        app(AccountSessions::class)->revoke($customer);
        $customer->forceFill(['remember_token' => Str::random(60)])->save();
        app(AdminAuditTrail::class)->record('user.sessions_revoked', 'User', $customer->id, 'Customer sessions revoked.', null, ['reason' => $data['reason']]);
        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'security'])->with('status', 'User sessions revoked.');
    }
}
