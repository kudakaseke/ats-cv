<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BillingRecords;
use App\Services\Billing\PaynowGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreditBillingController extends Controller
{
    public function topup(Request $request)
    {
        $data = $request->validate(['pack' => ['required', Rule::in(array_keys(config('ai_credits.topups')))], 'provider' => ['required', 'in:paynow'], 'billing_consent' => ['accepted']]);
        $pack = config('ai_credits.topups.'.$data['pack']);
        abort_unless(is_numeric($pack['price_cents']) && (int) $pack['price_cents'] > 0, 503, 'This credit pack has not been priced yet.');
        $provider = $data['provider'];
        abort_unless(app(BillingController::class)->available($provider), 503);
        $mode = config('billing.paynow.mode');
        $amount = $pack['price_cents'];
        $currency = 'USD';
        $payment = DB::transaction(function () use ($request, $provider, $mode, $amount, $currency, $pack) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($provider === 'paynow' && ($existing = BillingPayment::where('user_id', $request->user()->id)
                ->where('provider', 'paynow')->where('mode', $mode)->where('status', 'pending')->where('kind', 'topup')->lockForUpdate()->first())) {
                return $existing;
            }

            return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $request->user()->id,
                'subscription_plan_id' => SubscriptionPlan::where('slug', 'free')->firstOrFail()->id, 'provider' => $provider,
                'mode' => $mode, 'amount_cents' => (int) $amount, 'currency' => $currency, 'billing_interval' => 'monthly',
                'kind' => 'topup', 'credits' => $pack['credits'], 'recurring' => false]);
        }, 3);
        if ($provider === 'paynow' && ! $payment->wasRecentlyCreated) {
            return to_route('billing.show', $payment->reference)->with('status', 'Check your existing top-up before starting another payment.');
        }
        try {
            $gateway = app(PaynowGateway::class);

            return redirect()->away($gateway->start($payment, $request->user()->email));
        } catch (\Throwable $exception) {
            if ($exception instanceof \DomainException) {
                $payment->update(['status' => 'failed', 'failure_reason' => 'Checkout rejected by provider']);
                app(BillingRecords::class)->record($payment);
            }
            report($exception);

            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Checkout could not be opened. No credits have been added. Check payment status before retrying.']);
        }
    }
}
