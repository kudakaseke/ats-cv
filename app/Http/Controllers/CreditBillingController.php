<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Billing\PaynowGateway;
use App\Services\Billing\PaypalGateway;
use App\Services\Billing\PaystackGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreditBillingController extends Controller
{
    public function topup(Request $request)
    {
        $data = $request->validate(['pack' => ['required', Rule::in(array_keys(config('ai_credits.topups')))], 'provider' => ['required', 'in:paystack,paynow,paypal'], 'billing_consent' => ['accepted']]);
        $pack = config('ai_credits.topups.'.$data['pack']);
        abort_unless(is_numeric($pack['price_cents']) && (int) $pack['price_cents'] > 0, 503, 'This credit pack has not been priced yet.');
        $provider = $data['provider'];
        if ($provider === 'paystack') {
            if (! $this->paystackReady()) {
                return to_route('subscription')->withErrors(['billing' => 'Paystack checkout is being configured. Please try again shortly.']);
            }
            $mode = app(PaystackGateway::class)->mode();
            $amount = config('paystack.currency') === 'USD' ? $pack['price_cents'] : config('paystack.topup_amounts.'.$data['pack']);
            abort_unless(is_numeric($amount) && (int) $amount > 0, 503, 'Merchant-currency price not configured.');
            $currency = config('paystack.currency');
        } else {
            abort_unless(config('billing.'.$provider.'.enabled'), 503);
            $mode = config('billing.'.$provider.'.mode');
            $amount = $pack['price_cents'];
            $currency = 'USD';
        }
        $payment = BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'free')->firstOrFail()->id, 'provider' => $provider,
            'mode' => $mode, 'amount_cents' => (int) $amount, 'currency' => $currency, 'billing_interval' => 'monthly',
            'kind' => 'topup', 'credits' => $pack['credits'], 'recurring' => false]);
        try {
            $gateway = match ($provider) {
                'paystack' => app(PaystackGateway::class), 'paynow' => app(PaynowGateway::class), default => app(PaypalGateway::class)
            };

            return redirect()->away($gateway->start($payment, $request->user()->email));
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Checkout could not be opened. No credits have been added. Check payment status before retrying.']);
        }
    }

    public function subscribe(Request $request, string $plan, PaystackGateway $gateway)
    {
        $request->validate(['billing_consent' => ['accepted']]);
        if (! $this->paystackReady()) {
            return to_route('subscription')->withErrors(['billing' => 'Paystack checkout is being configured. Please try again shortly.']);
        }
        $offer = SubscriptionPlan::where('slug', $plan)->where('is_active', true)->whereIn('slug', ['monthly', 'pro', 'annual'])->firstOrFail();
        abort_unless($offer->paystack_plan_code && $offer->paystack_amount_cents > 0 && $offer->paystack_mode === $gateway->mode(), 503, 'Paystack plan is not configured.');
        $payment = DB::transaction(function () use ($request, $offer, $gateway) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->subscriptions()->where('status', 'active')->where('ends_at', '>', now())->exists() || BillingPayment::where('user_id', $user->id)->where('mode', $gateway->mode())->where('recurring', true)->where(fn ($q) => $q->whereNull('recurring_status')->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED', 'DISABLED', 'COMPLETE']))->exists()) {
                return null;
            }

            return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'subscription_plan_id' => $offer->id,
                'provider' => 'paystack', 'mode' => $gateway->mode(), 'amount_cents' => $offer->paystack_amount_cents, 'currency' => config('paystack.currency'),
                'billing_interval' => $offer->billing_interval, 'recurring' => true, 'provider_plan_id' => $offer->paystack_plan_code, 'credits' => config('ai_credits.plans.'.$offer->slug)]);
        });
        if (! $payment) {
            return to_route('subscription')->withErrors(['billing' => 'Manage your existing subscription before starting another one.']);
        }
        try {
            return redirect()->away($gateway->start($payment, $request->user()->email));
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Checkout could not be confirmed. Check payment status before retrying.']);
        }
    }

    private function paystackReady(): bool
    {
        return config('paystack.enabled') && is_string(config('paystack.secret_key'))
            && preg_match('/^sk_(test|live)_/', config('paystack.secret_key')) === 1;
    }

    public function webhook(Request $request, PaystackGateway $gateway)
    {
        $secret = config('paystack.secret_key');
        if (! (is_string($secret) && $secret !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), (string) $request->header('x-paystack-signature')))) {
            app(AuditTrail::class)->record(null, 'webhook.rejected', 'Provider', 'paystack', ['reason' => 'Invalid signature']);
            abort(401);
        }
        try {
            $event = $request->input('event', '');
            if (str_starts_with($event, 'subscription.') || $event === 'invoice.payment_failed') {
                $gateway->subscriptionEvent($request->input('data', []));
            } else {
                $reference = $request->input('data.reference', $request->input('data.transaction.reference'));
                if (! is_string($reference) || ! in_array($event, ['charge.success', 'refund.processed', 'charge.dispute.create', 'charge.dispute.resolve'], true)) {
                    return response('OK');
                }
                $verified = $gateway->verified($reference);
                $payment = BillingPayment::where('provider', 'paystack')->where('reference', $reference)->first();
                if (! $payment) {
                    $matches = BillingPayment::where('provider', 'paystack')->where('mode', $gateway->mode())->where('recurring', true)
                        ->where('provider_customer_id', data_get($verified, 'customer.customer_code'))->where('provider_plan_id', $gateway->planCode($verified))->get();
                    if ($matches->count() !== 1) {
                        return response('Unknown subscription', 503);
                    }
                    $payment = $matches->first();
                }
                $gateway->apply($payment, $verified);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $reference = $request->input('data.reference', $request->input('data.transaction.reference'));
            $payment = is_string($reference) ? BillingPayment::where('provider', 'paystack')->where('reference', $reference)->first() : null;
            app(AuditTrail::class)->record($payment?->user_id, 'webhook.verification_failed', 'BillingPayment', $payment?->id ?? 'unknown',
                ['provider' => 'paystack', 'reason' => 'Provider confirmation could not be completed; retry required.']);

            return response('Verification unavailable; retry required', 503);
        }

        return response('OK');
    }

    public function cancel(Request $request, string $reference, PaystackGateway $gateway)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->where('provider', 'paystack')->where('recurring', true)->firstOrFail();
        try {
            $gateway->cancel($payment);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['billing' => 'Cancellation could not be verified. Please contact support or manage your subscription in Paystack.']);
        }

        return to_route('subscription')->with('billing_status', 'Future Paystack renewals cancelled. Your paid credits remain available until their expiry date.');
    }
}
