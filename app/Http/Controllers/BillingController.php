<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaynowNotification;
use App\Jobs\RefreshPayment;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\AuditTrail;
use App\Services\Billing\BillingRecords;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function start(Request $request, string $provider, string $plan)
    {
        abort_unless($provider === 'paynow', 404);
        $offer = SubscriptionPlan::where('slug', $plan)->where('is_active', true)->whereIn('slug', ['monthly', 'pro', 'annual'])->firstOrFail();
        abort_unless($offer->currency === 'USD' && $offer->price_cents > 0 && config('ai_credits.plans.'.$offer->slug) > 0, 503);
        $request->validate(['billing_consent' => ['accepted']]);
        if (! $this->available($provider)) {
            return to_route('subscription')->withErrors(['billing' => ucfirst($provider).' checkout is not available yet.']);
        }
        if (config('billing.paynow.plan_change_policy') !== 'end_of_cycle' && $request->user()->subscriptions()->whereIn('status', Subscription::availableStatuses())->where('ends_at', '>', now())
            ->where('subscription_plan_id', '!=', $offer->id)->exists()) {
            return to_route('subscription')->withErrors(['billing' => 'Please wait for your current paid plan to end before switching plans.']);
        }
        $payment = DB::transaction(function () use ($request, $provider, $offer) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($provider === 'paynow') {
                $existing = BillingPayment::where('user_id', $request->user()->id)->where('provider', 'paynow')
                    ->where('mode', config('billing.paynow.mode'))->where('status', 'pending')->where('kind', 'subscription')->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
                // Serialize the active-plan check with fulfillment and concurrent checkouts.
                if (config('billing.paynow.plan_change_policy') !== 'end_of_cycle' && $request->user()->subscriptions()->whereIn('status', Subscription::availableStatuses())->where('ends_at', '>', now())
                    ->where('subscription_plan_id', '!=', $offer->id)->exists()) {
                    return null;
                }
                // Immediate-credit purchases may extend an already prepaid term.
                // Keep the former limit for deferred or after-expiry billing.
                if ((config('billing.subscription_credit_timing') !== 'immediate' || config('billing.paynow.plan_change_policy') === 'after_expiry')
                    && $request->user()->subscriptions()->whereIn('status', Subscription::availableStatuses())->where('starts_at', '>', now())->exists()) {
                    return null;
                }
            }
            if (config('billing.paynow.mode') === 'live' && BillingPayment::where('user_id', $request->user()->id)->where('recurring', true)->where('mode', 'live')
                ->where(fn ($query) => $query->whereNull('recurring_status')->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED', 'DISABLED', 'COMPLETE']))->exists()) {
                return null;
            }

            return BillingPayment::create([
                'reference' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'subscription_plan_id' => $offer->id,
                'provider' => $provider, 'mode' => config('billing.paynow.mode', 'test'),
                'amount_cents' => $offer->price_cents, 'currency' => 'USD', 'billing_interval' => $offer->billing_interval,
                'credits' => config('ai_credits.plans.'.$offer->slug),
                'recurring' => false,
            ]);
        }, 3);
        if (! $payment) {
            return to_route('subscription')->withErrors(['billing' => 'An existing subscription or prepaid renewal is still open. Contact support before starting another purchase.']);
        }
        if ($provider === 'paynow' && ! $payment->wasRecentlyCreated) {
            if ($payment->subscription_plan_id !== $offer->id || $payment->amount_cents !== $offer->price_cents
                || $payment->currency !== $offer->currency) {
                $existingPlan = SubscriptionPlan::findOrFail($payment->subscription_plan_id);

                return to_route('subscription.payment-methods', $offer->slug)->withErrors(['billing' => 'You have an unfinished '.$existingPlan->name.' checkout for '.$payment->currency.' '.number_format($payment->amount_cents / 100, 2).
                    '. No '.$offer->name.' checkout was started. Review the existing checkout in Payment History, cancel it at Paynow if unwanted, and check its status before trying again.']);
            }

            return to_route('billing.show', $payment->reference);
        }
        try {
            $gateway = app(PaynowGateway::class);

            return redirect()->away($gateway->start($payment, $request->user()->email));
        } catch (\Throwable $exception) {
            if ($provider === 'paynow' && $exception instanceof \DomainException) {
                $payment->update(['status' => 'failed', 'failure_reason' => 'Checkout rejected by provider']);
                app(BillingRecords::class)->record($payment);
            }
            app(AuditTrail::class)->record($payment->user_id, 'payment.initialization_uncertain', 'BillingPayment', $payment->id,
                ['reference' => $payment->reference, 'provider' => $payment->provider]);

            // A timed-out request might exist at the provider. Keep the reference for reconciliation.
            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Checkout could not be opened. No access has been activated. Contact support with the payment reference if you were charged.']);
        }
    }

    public function show(Request $request, string $reference, PaymentService $service)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();

        if ($payment->status === 'pending') {
            try {
                $this->refreshOrQueue($payment, $service, $request->routeIs('billing.paynow.return'));
                $payment->refresh();
            } catch (\Throwable $exception) {
                report($exception);
                // Keep waiting for provider confirmation; a return URL alone is not proof of payment.
            }
        }
        if (($payment->status === 'paid' && $payment->mode === 'live') || $payment->status === 'test_paid') {
            return $this->paidResponse($request, $payment);
        }

        return view('workspace.payment', ['payment' => $payment]);
    }

    public function check(Request $request, string $reference, PaymentService $service)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        try {
            $this->refreshOrQueue($payment, $service);
        } catch (\Throwable $exception) {
            report($exception);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Payment confirmation is temporarily unavailable. We will try again.'], 503);
            }

            return back()->withErrors(['billing' => 'We could not verify this payment yet. Please try again or contact support with your reference.']);
        }

        $payment->refresh();
        if (($payment->status === 'paid' && $payment->mode === 'live') || $payment->status === 'test_paid') {
            return $this->paidResponse($request, $payment);
        }
        if ($request->expectsJson()) {
            return response()->json(['status' => $payment->status, 'redirect_url' => null]);
        }

        return to_route('billing.show', $reference)->with('status', 'Payment status checked.');
    }

    private function refreshOrQueue(BillingPayment $payment, PaymentService $service, bool $checkoutReturn = false): void
    {
        if ($payment->provider !== 'paynow') {
            return;
        }
        // The customer may have paid immediately after a pending status check.
        // Verify that checkout return now; ordinary polling keeps its cooldown.
        if (! $checkoutReturn && $payment->verified_at?->gt(now()->subSeconds(30))) {
            return;
        }
        if (config('performance.queue_payment_checks')) {
            RefreshPayment::dispatch($payment->id);
        } else {
            $service->refresh($payment);
        }
    }

    private function paidResponse(Request $request, BillingPayment $payment)
    {
        $wallet = app(AiCreditService::class)->wallet($request->user());
        $plan = $payment->kind === 'topup' ? 'Credit top-up' : SubscriptionPlan::find($payment->subscription_plan_id)?->name;
        $message = 'Payment successful. '.($plan ?? 'Your plan').' is confirmed. Your available balance is '.$wallet['credits'].' credits.';
        if ($payment->status === 'test_paid') {
            $message = 'Test payment successful. '.($plan ?? 'Your purchase').' confirmed: '.$payment->currency.' '.number_format($payment->amount_cents / 100, 2).'. This was a test transaction; your live plan and credits are unchanged.';
            if ($payment->subscription_id && Subscription::localTestCreditsEnabled()) {
                $message = 'Test payment successful. '.($plan ?? 'Your plan').' confirmed for local testing. Your available balance is '.$wallet['credits'].' credits. No live payment was recorded.';
            }

        }

        $period = $payment->subscription_id ? Subscription::find($payment->subscription_id) : null;
        if ($period && in_array($period->status, Subscription::availableStatuses(), true) && $period->starts_at?->isFuture()) {
            $message .= $period->credits_granted_upfront
                ? ' Its '.$payment->credits.' credits have been added now. The '.$plan.' access period starts on '.$period->starts_at->timezone('Africa/Johannesburg')->format('d M Y').'; credits will not be added again then.'
                : ' Your '.$plan.' subscription starts on '.$period->starts_at->timezone('Africa/Johannesburg')->format('d M Y').'. Its '.$payment->credits.' credits become available then; this purchase does not refill your current period.';
        }

        if ($request->expectsJson()) {
            $request->session()->flash('payment_success', $message);

            return response()->json(['status' => $payment->status, 'credits' => $wallet['credits'], 'redirect_url' => route('dashboard')]);
        }

        return to_route('dashboard')->with('payment_success', $message);
    }

    public function paynowNotify(Request $request, string $reference, PaynowGateway $gateway, PaymentService $service)
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        parse_str($request->getContent(), $data);
        abort_unless($gateway->verify($data), 400);
        $safe = array_intersect_key(array_change_key_case($data, CASE_LOWER), array_flip(['reference', 'amount', 'paynowreference', 'status']));
        $safe = array_map(fn ($value) => is_scalar($value) ? mb_substr((string) $value, 0, 255) : '[invalid]', $safe);
        ksort($safe);
        $fingerprint = hash('sha256', json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $payment = BillingPayment::where('reference', $reference)->where('provider', 'paynow')->first();
        $data = array_change_key_case($data, CASE_LOWER);
        abort_unless(is_string($data['reference'] ?? null) && hash_equals($reference, $data['reference']), 400);
        abort_unless($payment, 404);
        if (! $gateway->matches($payment, $data)) {
            abort(400);
        }
        DB::table('payment_notifications')->insertOrIgnore(['billing_payment_id' => $payment->id, 'fingerprint' => $fingerprint,
            'payload' => json_encode($safe, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), 'hash_valid' => true,
            'error' => null, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::table('payment_notifications')->where('fingerprint', $fingerprint)->whereNotNull('processed_at')->exists()) {
            return response('OK');
        }
        // A signed provider notification can recover a poll URL lost to an initialization timeout.
        // Once stored, it is never replaced by incoming callbacks.
        if (! $payment->poll_url && is_string($data['pollurl'] ?? null) && $gateway->safePollUrl($data['pollurl'])) {
            DB::transaction(function () use ($payment, $data) {
                User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
                $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (! $locked->poll_url) {
                    $locked->update(['poll_url' => $data['pollurl']]);
                }
            });
            $payment->refresh();
        }
        if (config('performance.queue_payment_checks')) {
            ProcessPaynowNotification::dispatch($fingerprint);

            return response('OK');
        }
        try {
            if (! $payment->poll_url && ! $payment->merchant_trace) {
                throw new \RuntimeException('Paynow poll URL not yet available.');
            }
            $service->refresh($payment);
        } catch (\Throwable $exception) {
            DB::table('payment_notifications')->where('fingerprint', $fingerprint)->update(['error' => 'Provider verification unavailable']);

            return response('Verification unavailable', 503);
        }

        DB::table('payment_notifications')->where('fingerprint', $fingerprint)->update(['processed_at' => now(), 'error' => null, 'updated_at' => now()]);

        return response('OK');
    }

    public function available(string $provider): bool
    {
        return $provider === 'paynow'
            && config('billing.paynow.enabled')
            && config('billing.paynow.integration_id')
            && config('billing.paynow.integration_key')
            && in_array(config('billing.paynow.mode'), ['test', 'live'], true)
            && (config('billing.paynow.mode') !== 'live' || config('billing.paynow.live_currency_confirmed'));
    }
}
