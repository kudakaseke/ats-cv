<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function __invoke(Request $request)
    {
        $owner = $request->user()->id;

        return view('workspace.activity', [
            'payments' => DB::table('billing_payments')->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'billing_payments.subscription_plan_id')
                ->where('billing_payments.user_id', $owner)->select('billing_payments.*', 'subscription_plans.name as plan_name')
                ->orderByDesc('billing_payments.id')->paginate(15, ['*'], 'payments'),
            'credits' => DB::table('ai_credit_transactions')->where('user_id', $owner)->orderByDesc('id')->paginate(20, ['*'], 'credits'),
            'events' => DB::table('audit_events')->where('user_id', $owner)->orderByDesc('id')->paginate(20, ['*'], 'events'),
        ]);
    }
}
