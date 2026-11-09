<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\CvAnalysis;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\AiCreditService;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function module(string $module): View|RedirectResponse
    {
        if ($module === 'builder') {
            return redirect()->route('builder.start', ['new' => 1]);
        }
        if ($module === 'checker') {
            return view('workspace.checker');
        }
        if ($module === 'detect') {
            return view('workspace.detect');
        }
        if ($module === 'revamp') {
            return redirect()->route('workspace.module', 'checker');
        }
        if ($module === 'resumes') {
            return view('workspace.resumes', ['resumes' => auth()->user()->resumes()->select('id', 'name', 'updated_at')->latest('updated_at')->paginate(12)]);
        }
        if ($module === 'drafts') {
            return view('workspace.drafts', [
                'drafts' => auth()->user()->resumes()->where('status', 'draft')
                    ->select('id', 'name', 'updated_at')->latest('updated_at')->paginate(12),
                'currentDraft' => session('cv_builder'),
            ]);
        }
        if ($module === 'history') {
            return view('workspace.history', ['analyses' => auth()->user()->analyses()->latest()->paginate(15)]);
        }
        abort(404);
    }

    public function subscription(Request $request, PlanService $service): View
    {
        $currentPlan = $service->current($request->user());
        $wallet = app(AiCreditService::class)->displayWallet($request->user());

        return view('workspace.subscription', [
            'currentPlan' => $currentPlan,
            'activeSubscription' => $request->user()->subscriptions()->where('subscription_plan_id', $currentPlan->id)
                ->whereIn('status', Subscription::availableStatuses())
                ->whereNotNull('starts_at')->where('starts_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->latest()->first(),
            'payments' => BillingPayment::where('user_id', $request->user()->id)
                ->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
                ->select('billing_payments.*', 'subscription_plans.name as plan_name')
                ->orderByDesc('billing_payments.id')->paginate(10, ['*'], 'payments'),
            'remaining' => intdiv($wallet['credits'], config('ai_credits.costs.analyse')),
            'wallet' => $wallet,
            'scheduledSubscriptions' => $request->user()->subscriptions()->with('plan')->whereIn('status', Subscription::availableStatuses())->where('starts_at', '>', now())->orderBy('starts_at')->get(),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->get(),
        ]);
    }

    public function analysis(Request $request, CvAnalysis $analysis): View
    {
        abort_unless($analysis->user_id === $request->user()->id, 404);

        $results = $analysis->results;
        if (isset($results['experience_check'])) {
            $results['experience_check'] = app(\App\Services\CvExperienceMatcher::class)->reconcile($results['experience_check'], $results['assessment'] ?? $results['ai_analysis']['assessment'] ?? null);
        }
        if (($results['scoring_basis'] ?? '') !== \App\Services\CvJobRequirements::VERSION) {
            // Saved checks do not retain the original CV wording. Do not pretend
            // their old keyword counts were produced by the new weighted method.
            $results['legacy_wording_score'] = $results['keyword_score'] ?? $analysis->score;
            $results['keyword_score'] = null;
            $results['certificate_bonus'] = 0;
        }
        $analysis->results = app(\App\Services\CvCompatibilityScore::class)->scan($results);
        return view('workspace.analysis', compact('analysis'));
    }

    public function paymentMethods(string $plan): View
    {
        $plan = SubscriptionPlan::where('slug', $plan)->where('is_active', true)->whereIn('slug', ['monthly', 'pro', 'annual'])->firstOrFail();

        return view('workspace.payment-methods', compact('plan'));
    }
}
