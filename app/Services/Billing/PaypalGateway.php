<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaypalGateway
{
    public function verifyWebhook(Request $request): bool
    {
        if (! config('billing.paypal.webhook_id')) {
            return false;
        }
        $payment = new BillingPayment(['mode' => config('billing.paypal.mode')]);
        $data = $this->client($payment)->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => config('billing.paypal.webhook_id'),
            'webhook_event' => $request->json()->all(),
        ])->throw()->json();

        return ($data['verification_status'] ?? '') === 'SUCCESS';
    }

    public function client(BillingPayment $payment): PendingRequest
    {
        if ($payment->mode !== config('billing.paypal.mode')) {
            throw new \RuntimeException('PayPal environment changed.');
        }
        $base = $payment->mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $token = Http::asForm()->withBasicAuth(config('billing.paypal.client_id'), config('billing.paypal.client_secret'))
            ->timeout(20)->post($base.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])->throw()->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('PayPal authentication failed.');
        }

        return Http::baseUrl($base)->withToken($token)->acceptJson()->timeout(20);
    }

    public function start(BillingPayment $payment, string $email): string
    {
        $data = $this->client($payment)->withHeaders(['PayPal-Request-Id' => $payment->reference])->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $payment->reference,
                'custom_id' => $payment->reference,
                'description' => $payment->kind === 'topup' ? 'atscvdetector '.$payment->credits.' AI credits - one-time purchase' : 'atscvdetector '.($payment->billing_interval === 'annually' ? 'annual' : 'monthly').' access - one-time payment',
                'amount' => ['currency_code' => 'USD', 'value' => number_format($payment->amount_cents / 100, 2, '.', '')],
            ]],
            'application_context' => ['brand_name' => 'atscvdetector', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW',
                'return_url' => route('billing.show', $payment->reference), 'cancel_url' => route('billing.show', $payment->reference)],
        ])->throw()->json();
        $url = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? '';
        $host = $payment->mode === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
        if (! is_string($data['id'] ?? null) || ! preg_match('/^[A-Z0-9]+$/', $data['id']) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host) {
            throw new \RuntimeException('Unexpected PayPal checkout response.');
        }
        $payment->update(['provider_reference' => $data['id'], 'checkout_url' => $url]);

        return $url;
    }

    public function status(BillingPayment $payment): string
    {
        if (! $payment->provider_reference) {
            return 'pending';
        }
        $client = $this->client($payment);
        $url = '/v2/checkout/orders/'.rawurlencode($payment->provider_reference);
        $order = $client->get($url)->throw()->json();
        $unit = $order['purchase_units'][0] ?? [];
        if (($order['id'] ?? '') !== $payment->provider_reference || count($order['purchase_units'] ?? []) !== 1
            || ($unit['custom_id'] ?? '') !== $payment->reference || ($unit['amount']['currency_code'] ?? '') !== $payment->currency
            || ($unit['amount']['value'] ?? '') !== number_format($payment->amount_cents / 100, 2, '.', '')) {
            throw new \RuntimeException('PayPal order does not match.');
        }
        if (($order['status'] ?? '') === 'APPROVED') {
            // Stable idempotency key makes retry after a lost response safe.
            $order = $client->withHeaders(['PayPal-Request-Id' => $payment->reference.'-capture'])
                ->withBody('{}', 'application/json')->post($url.'/capture')->throw()->json();
        }
        $captures = $order['purchase_units'][0]['payments']['captures'] ?? [];
        $capture = $captures[0] ?? [];
        if (is_string($capture['id'] ?? null) && $capture['id'] !== '') {
            $payment->update(['capture_reference' => $capture['id']]);
        }
        if (($order['status'] ?? '') === 'COMPLETED' && count($captures) === 1
            && ($capture['status'] ?? '') === 'COMPLETED'
            && ($capture['amount']['currency_code'] ?? '') === $payment->currency
            && ($capture['amount']['value'] ?? '') === number_format($payment->amount_cents / 100, 2, '.', '')) {
            return 'paid';
        }

        return in_array($capture['status'] ?? '', ['REFUNDED', 'PARTIALLY_REFUNDED', 'REVERSED'], true) ? 'reversed' : 'pending';
    }
}
