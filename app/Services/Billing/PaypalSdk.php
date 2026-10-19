<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

class PaypalSdk
{
    public function createSubscription(BillingPayment $payment, array $data): array
    {
        if (! in_array($payment->mode, ['sandbox', 'live'], true) || $payment->mode !== config('billing.paypal.mode')) {
            throw new \RuntimeException('Invalid PayPal environment.');
        }
        $settings = config('paypal');
        $settings['mode'] = $payment->mode;
        $settings[$payment->mode]['client_id'] = config('billing.paypal.client_id');
        $settings[$payment->mode]['client_secret'] = config('billing.paypal.client_secret');
        $settings['currency'] = 'USD';
        $settings['validate_ssl'] = true;
        $provider = app(PayPalClient::class);
        $provider->setApiCredentials($settings);
        $token = $provider->getAccessToken();
        if (! is_array($token) || ! is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
            throw new \RuntimeException('PayPal authentication failed.');
        }
        $result = $provider->withIdempotencyKey($payment->reference)->createSubscription($data);
        if (! is_array($result) || isset($result['error'])) {
            throw new \RuntimeException('PayPal subscription creation failed.');
        }

        return $result;
    }
}
