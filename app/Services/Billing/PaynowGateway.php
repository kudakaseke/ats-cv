<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use Illuminate\Support\Facades\Http;

class PaynowGateway
{
    public function signature(array $data): string
    {
        $values = '';
        foreach ($data as $key => $value) {
            if (! is_scalar($value)) {
                throw new \RuntimeException('Invalid Paynow data.');
            }
            if (strtolower($key) !== 'hash') {
                $values .= $value;
            }
        }

        return strtoupper(hash('sha512', $values.config('billing.paynow.integration_key')));
    }

    public function verify(array $data): bool
    {
        $data = array_change_key_case($data, CASE_LOWER);

        return config('billing.paynow.integration_key') && is_string($data['hash'] ?? null)
            && hash_equals($this->signature($data), strtoupper($data['hash']));
    }

    private function parse(string $body): array
    {
        parse_str($body, $data);
        $data = array_change_key_case($data, CASE_LOWER);
        if (! $this->verify($data)) {
            throw new \RuntimeException('Invalid Paynow signature.');
        }

        return $data;
    }

    public function start(BillingPayment $payment, string $email): string
    {
        $callbackBase = rtrim((string) config('billing.paynow.callback_url'), '/');
        if ($callbackBase !== '' && (parse_url($callbackBase, PHP_URL_SCHEME) !== 'https'
            || ! parse_url($callbackBase, PHP_URL_HOST) || parse_url($callbackBase, PHP_URL_USER)
            || parse_url($callbackBase, PHP_URL_PASS) || parse_url($callbackBase, PHP_URL_QUERY)
            || parse_url($callbackBase, PHP_URL_FRAGMENT))) {
            throw new \RuntimeException('Paynow callback base must be a public HTTPS URL.');
        }
        $callback = fn (string $route) => $callbackBase === '' ? route($route, $payment->reference)
            : $callbackBase.route($route, $payment->reference, false);
        $data = [
            'id' => config('billing.paynow.integration_id'),
            'reference' => $payment->reference,
            'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'additionalinfo' => $payment->kind === 'topup' ? 'atscvdetector '.$payment->credits.' AI credits - one-time purchase' : 'atscvdetector '.($payment->billing_interval === 'annually' ? 'annual' : 'monthly').' access - one-time payment',
            'returnurl' => $callback('billing.show'),
            'resulturl' => $callback('billing.paynow.notify'),
            'status' => 'Message',
        ];
        // Test payments can only be completed by the integration's merchant account.
        $authEmail = $payment->mode === 'test' ? config('billing.paynow.test_email') : $email;
        if ($authEmail) {
            $data['authemail'] = $authEmail;
        }
        $data['hash'] = $this->signature($data);
        $result = $this->parse(Http::asForm()->timeout(20)->withoutRedirecting()
            ->post('https://www.paynow.co.zw/interface/initiatetransaction', $data)->throw()->body());
        if (strtolower($result['status'] ?? '') !== 'ok' || ! $this->safeUrl($result['pollurl'] ?? '') || ! $this->safeUrl($result['browserurl'] ?? '')) {
            throw new \RuntimeException('Unable to start Paynow checkout.');
        }
        $payment->update(['poll_url' => $result['pollurl'], 'checkout_url' => $result['browserurl']]);

        return $result['browserurl'];
    }

    public function safeUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(strtolower(parse_url($url, PHP_URL_HOST) ?? ''), ['www.paynow.co.zw', 'paynow.co.zw'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)
            && in_array(parse_url($url, PHP_URL_PORT), [null, 443], true);
    }

    public function status(BillingPayment $payment): string
    {
        if (! $payment->poll_url || ! $this->safeUrl($payment->poll_url)) {
            return 'pending';
        }
        $result = $this->parse(Http::asForm()->timeout(20)->withoutRedirecting()->post($payment->poll_url, [])->throw()->body());
        if (($result['reference'] ?? '') !== $payment->reference || ! preg_match('/^\d+\.\d{2}$/', $result['amount'] ?? '')
            || (int) str_replace('.', '', $result['amount']) !== $payment->amount_cents) {
            throw new \RuntimeException('Payment details do not match.');
        }
        $reference = (string) ($result['paynowreference'] ?? '');
        if ($reference === '' || ($payment->provider_reference && $payment->provider_reference !== $reference)) {
            throw new \RuntimeException('Payment reference does not match.');
        }
        $payment->update(['provider_reference' => $reference]);

        return match (strtolower($result['status'] ?? '')) {
            'paid', 'awaiting delivery', 'delivered' => 'paid',
            'refunded', 'disputed' => 'reversed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}
