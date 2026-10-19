<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use App\Services\Billing\PaypalGateway;
use App\Services\Billing\PaypalSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    public function start(Request $request, string $provider, string $plan)
    {
        abort_unless(in_array($provider, ['paynow', 'paypal'], true), 404);
        $offer = SubscriptionPlan::where('slug', $plan)->where('is_active', true)->whereIn('slug', ['monthly', 'annual'])->firstOrFail();
        $request->validate(['billing_consent' => ['accepted']]);
        if (! $this->available($provider)) {
            return to_route('subscription')->withErrors(['billing' => ucfirst($provider).' checkout is not available yet.']);
        }
        $paypalPlan = config('billing.paypal.plans.'.$offer->slug);
        if ($provider === 'paypal' && ! $paypalPlan) {
            return to_route('subscription')->withErrors(['billing' => 'This PayPal subscription plan is not configured yet.']);
        }
        if ($provider === 'paypal' && $request->user()->subscriptions()->where('status', 'active')->where('ends_at', '>', now())->exists()) {
            return to_route('subscription')->withErrors(['billing' => 'Your paid access is still active. Start a new PayPal subscription after it ends to avoid overlapping charges.']);
        }
        if ($request->user()->subscriptions()->where('status', 'active')->where('ends_at', '>', now())
            ->where('subscription_plan_id', '!=', $offer->id)->exists()) {
            return to_route('subscription')->withErrors(['billing' => 'Please wait for your current paid plan to end before switching plans.']);
        }
        $payment = DB::transaction(function () use ($request, $provider, $offer, $paypalPlan) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (BillingPayment::where('user_id', $request->user()->id)->where('recurring', true)
                ->where(fn ($query) => $query->whereNull('recurring_status')->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED']))->exists()) {
                return null;
            }

            return BillingPayment::create([
                'reference' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'subscription_plan_id' => $offer->id,
                'provider' => $provider, 'mode' => $provider === 'paypal' ? config('billing.paypal.mode') : config('billing.paynow.mode', 'live'),
                'amount_cents' => $offer->price_cents, 'currency' => 'USD', 'billing_interval' => $offer->billing_interval,
                'recurring' => $provider === 'paypal', 'provider_plan_id' => $provider === 'paypal' ? $paypalPlan : null,
            ]);
        });
        if (! $payment) {
            return to_route('subscription')->withErrors(['billing' => 'An existing PayPal subscription or approval is still open. Manage or cancel it before starting another purchase.']);
        }
        try {
            $gateway = $provider === 'paynow' ? app(PaynowGateway::class) : app(PaypalSubscriptions::class);

            return redirect()->away($gateway->start($payment, $request->user()->email));
        } catch (\Throwable $exception) {
            // A timed-out request might exist at the provider. Keep the reference for reconciliation.
            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'Checkout could not be opened. No access has been activated. Contact support with the payment reference if you were charged.']);
        }
    }

    public function show(Request $request, string $reference)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();

        return view('workspace.payment', ['payment' => $payment]);
    }

    public function check(Request $request, string $reference, PaymentService $service)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)->firstOrFail();
        try {
            $service->refresh($payment);
        } catch (\Throwable $exception) {
            return back()->withErrors(['billing' => 'We could not verify this payment yet. Please try again or contact support with your reference.']);
        }

        return to_route('billing.show', $reference)->with('status', 'Payment status checked.');
    }

    public function paynowNotify(Request $request, string $reference, PaynowGateway $gateway, PaymentService $service)
    {
        $payment = BillingPayment::where('reference', $reference)->where('provider', 'paynow')->firstOrFail();
        parse_str($request->getContent(), $data);
        try {
            abort_unless($gateway->verify($data), 400);
        } catch (\RuntimeException $exception) {
            abort(400);
        }
        // Never use an incoming poll URL; poll only the signed URL saved at initiation.
        try {
            $service->refresh($payment);
        } catch (\Throwable $exception) {
            return response('Verification unavailable', 503);
        }

        return response('OK');
    }

    public function available(string $provider): bool
    {
        if (! config('billing.'.$provider.'.enabled')) {
            return false;
        }

        return $provider === 'paynow'
            ? (bool) (config('billing.paynow.integration_id') && config('billing.paynow.integration_key'))
            : (bool) (config('billing.paypal.client_id') && config('billing.paypal.client_secret') && in_array(config('billing.paypal.mode'), ['sandbox', 'live'], true));
    }

    public function paypalNotify(Request $request, PaypalGateway $gateway, PaymentService $service)
    {
        try {
            if (! $gateway->verifyWebhook($request)) {
                return response('Invalid notification', 400);
            }
        } catch (\Throwable $exception) {
            return response('Verification unavailable', 503);
        }
        $agreementId = $request->input('resource.billing_agreement_id', $request->input('resource.id'));
        $recurring = is_string($agreementId) ? BillingPayment::where('provider', 'paypal')->where('recurring', true)
            ->where('mode', config('billing.paypal.mode'))->where('provider_reference', $agreementId)->first() : null;
        $transactionId = $request->input('resource.sale_id', $request->input('resource.id'));
        $receipt = is_string($transactionId) ? DB::table('paypal_subscription_receipts')
            ->join('billing_payments', 'billing_payments.id', '=', 'paypal_subscription_receipts.billing_payment_id')
            ->where('billing_payments.mode', config('billing.paypal.mode'))->where('transaction_id', $transactionId)
            ->select('paypal_subscription_receipts.*')->first() : null;
        if (! $recurring && $receipt) {
            $recurring = BillingPayment::whereKey($receipt->billing_payment_id)->where('mode', config('billing.paypal.mode'))->first();
        }
        if ($recurring) {
            try {
                app(PaypalSubscriptions::class)->refresh($recurring, $receipt ? Carbon::parse($receipt->paid_at) : null);
            } catch (\Throwable $exception) {
                return response('Verification unavailable', 503);
            }

            return response('OK');
        }
        $orderId = $request->input('resource.supplementary_data.related_ids.order_id');
        $captureId = $request->input('resource.supplementary_data.related_ids.capture_id', $request->input('resource.id'));
        if (! is_string($orderId) && ! is_string($captureId)) {
            return response('OK');
        }
        $payment = BillingPayment::where('provider', 'paypal')->where('mode', config('billing.paypal.mode'))
            ->where(function ($query) use ($orderId, $captureId) {
                $query->where('provider_reference', is_string($orderId) ? $orderId : '');
                if (is_string($captureId)) {
                    $query->orWhere('capture_reference', $captureId);
                }
            })->first();
        if ($payment) {
            try {
                $service->refresh($payment);
            } catch (\Throwable $exception) {
                return response('Verification unavailable', 503);
            }
        }

        return response('OK');
    }

    public function cancelPaypal(Request $request, string $reference, PaypalSubscriptions $gateway)
    {
        $payment = BillingPayment::where('reference', $reference)->where('user_id', $request->user()->id)
            ->where('provider', 'paypal')->where('recurring', true)->firstOrFail();
        try {
            if (! $payment->provider_reference) {
                throw new \RuntimeException('Missing provider reference; support must reconcile.');
            }
            $gateway->cancel($payment);
        } catch (\Throwable $exception) {
            return back()->withErrors(['billing' => 'Cancellation could not be confirmed. Please manage the subscription in PayPal or contact support.']);
        }

        return to_route('subscription')->with('billing_status', 'Automatic renewal cancelled. Your existing paid access remains until its end date.');
    }
}
