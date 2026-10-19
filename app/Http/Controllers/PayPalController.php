<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaypalGateway;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayPalController extends Controller
{
    public function checkout(Request $request, BillingController $billing)
    {
        $data = $request->validate(['plan' => ['required', Rule::in(['monthly', 'annual'])]]);

        return $billing->start($request, 'paypal', $data['plan']);
    }

    public function success(Request $request, PaymentService $service)
    {
        $payment = $this->payment($request);
        $providerId = $request->query('subscription_id');
        if ($providerId !== null && $providerId !== $payment->provider_reference) {
            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'The returned subscription does not match your checkout.']);
        }
        try {
            // A success URL or token is not proof of payment; re-query the stored subscription.
            $service->refresh($payment);
        } catch (\Throwable $exception) {
            return to_route('billing.show', $payment->reference)->withErrors(['billing' => 'PayPal has returned you to the app, but payment verification is not complete. Please check the status again.']);
        }

        return to_route('billing.show', $payment->reference);
    }

    public function cancel(Request $request)
    {
        $payment = $this->payment($request);

        return to_route('billing.show', $payment->reference)->with('status', 'Checkout closed. Check the subscription status before trying again. To stop an existing renewal, use Cancel automatic renewal on the Subscription page.');
    }

    public function handleWebhook(Request $request, BillingController $billing, PaypalGateway $gateway, PaymentService $service)
    {
        return $billing->paypalNotify($request, $gateway, $service);
    }

    private function payment(Request $request): BillingPayment
    {
        $data = $request->validate(['reference' => ['required', 'uuid']]);

        return BillingPayment::where('reference', $data['reference'])->where('user_id', $request->user()->id)
            ->where('provider', 'paypal')->where('recurring', true)->firstOrFail();
    }
}
