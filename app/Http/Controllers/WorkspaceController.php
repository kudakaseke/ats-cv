<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\CvAnalysis;
use App\Models\SubscriptionPlan;
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
        if ($module === 'revamp') {
            return redirect()->route('workspace.module', 'checker');
        }
        if ($module === 'resumes') {
            return view('workspace.resumes', ['resumes' => auth()->user()->resumes()->latest('updated_at')->paginate(12)]);
        }
        if ($module === 'history') {
            return view('workspace.history', ['analyses' => auth()->user()->analyses()->latest()->paginate(15)]);
        }
        abort(404);
    }

    public function subscription(Request $request, PlanService $service): View
    {
        return view('workspace.subscription', [
            'currentPlan' => $service->current($request->user()),
            'remaining' => $service->remainingAnalyses($request->user()),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->get(),
            'paypalSubscriptions' => BillingPayment::where('user_id', $request->user()->id)->where('recurring', true)->where('provider', 'paypal')->latest()->get(),
        ]);
    }

    public function analysis(Request $request, CvAnalysis $analysis): View
    {
        abort_unless($analysis->user_id === $request->user()->id, 404);

        return view('workspace.analysis', compact('analysis'));
    }

    public function paymentMethods(string $plan): View
    {
        $plan = SubscriptionPlan::where('slug', $plan)->where('is_active', true)->whereIn('slug', ['monthly', 'annual'])->firstOrFail();

        return view('workspace.payment-methods', compact('plan'));
    }
}
