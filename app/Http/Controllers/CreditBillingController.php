<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Billing\HostedPaystackPayments;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use App\Services\Billing\PaypalGateway;
use App\Services\Billing\PaystackGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditBillingController extends Controller
{
    public function callback(Request $request, HostedPaystackPayments $hosted)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._=-]+$/']]);
        try {
            $payment = $hosted->reconcile($data['reference'], $request->user());
            if (! in_array($payment->status, ['paid', 'test_paid'], true)) {
                return to_route('dashboard')->withErrors(['billing' => 'Payment was declined or has not completed. No credits were added.']);
            }

            return app(BillingController::class)->show($request, $payment->reference, app(PaymentService::class));
        } catch (\DomainException) {
            return to_route('dashboard')->withErrors(['billing' => 'Payment was declined or has not completed. No credits were added.']);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return to_route('dashboard')->withErrors(['billing' => 'This payment uses a different account email. Do not pay again. Contact support with the payment reference to recover it.']);
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('dashboard')->withErrors(['billing' => 'Payment could not be confirmed. No credits were added. Please check payment status or contact support.']);
        }
    }

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
        $payment = DB::transaction(function () use ($request, $provider, $mode, $amount, $currency, $pack) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($provider === 'paynow' && ($existing = BillingPayment::where('user_id', $request->user()->id)
                ->where('provider', 'paynow')->where('mode', $mode)->where('status', 'pending')->where('kind', 'topup')->first())) {
                return $existing;
            }

            return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $request->user()->id,
                'subscription_plan_id' => SubscriptionPlan::where('slug', 'free')->firstOrFail()->id, 'provider' => $provider,
                'mode' => $mode, 'amount_cents' => (int) $amount, 'currency' => $currency, 'billing_interval' => 'monthly',
                'kind' => 'topup', 'credits' => $pack['credits'], 'recurring' => false]);
        });
        if ($provider === 'paynow' && ! $payment->wasRecentlyCreated) {
            return to_route('billing.show', $payment->reference)->with('status', 'Check your existing top-up before starting another payment.');
        }
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
            $renewals = BillingPayment::where('user_id', $user->id)->where('mode', $gateway->mode())->where('recurring', true)
                ->where(fn ($q) => $q->whereNull('recurring_status')->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED', 'DISABLED', 'COMPLETE']));
            if (PaystackGateway::localTestCreditsEnabled()) {
                // A completed local test purchase without a renewal agreement must not block later tests.
                $renewals->where(fn ($q) => $q->where('provider', '!=', 'paystack')->orWhere('status', '!=', 'test_paid')
                    ->orWhereNotNull('recurring_status')->orWhere('provider_reference', 'like', 'SUB_%'));
            }
            if ($user->subscriptions()->where('status', 'active')->where('ends_at', '>', now())->exists()) {
                return null;
            }
            $open = $renewals->lockForUpdate()->get();
            if ($open->isNotEmpty()) {
                if ($open->count() !== 1) {
                    return null;
                }
                $existing = $open->first();

                return $existing->provider === 'paystack' && $existing->status === 'pending'
                    && $existing->subscription_plan_id === $offer->id && $existing->recurring_status === null
                    && ! str_starts_with($existing->provider_reference ?? '', 'SUB_') ? $existing : null;
            }

            return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'subscription_plan_id' => $offer->id,
                'provider' => 'paystack', 'mode' => $gateway->mode(), 'amount_cents' => $offer->paystack_amount_cents, 'currency' => config('paystack.currency'),
                'billing_interval' => $offer->billing_interval, 'recurring' => true, 'provider_plan_id' => $offer->paystack_plan_code, 'credits' => config('ai_credits.plans.'.$offer->slug)]);
        });
        if (! $payment) {
            return redirect()->to(route('subscription.payment-methods', 'monthly'));
        }
        if ($payment->checkout_url) {
            $url = $payment->checkout_url;
            if (parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.paystack.com') {
                return redirect()->away($url);
            }

            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Check this payment status before retrying checkout.']);
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
                $payment = BillingPayment::where('provider', 'paystack')->where('mode', $gateway->mode())
                    ->where(fn ($q) => $q->where('reference', $reference)->orWhere('hosted_reference', $reference))->first();
                if (! $payment && data_get($verified, 'metadata.referrer')) {
                    app(HostedPaystackPayments::class)->applyVerified($verified);

                    return response('OK');
                }
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
