<?php

namespace ATSCV;

final class Paynow
{
    public static function signature(array $data, ?string $key = null): string
    {
        $values = '';
        foreach ($data as $name => $value) {
            if (! is_scalar($value)) {
                throw new \InvalidArgumentException('Invalid provider fields.');
            }
            if (strtolower((string) $name) !== 'hash') {
                $values .= $value;
            }
        }

        return strtoupper(hash('sha512', $values.($key ?? Config::get('PAYNOW_KEY'))));
    }

    public static function verify(array $data): bool
    {
        try {
            $data = array_change_key_case($data, CASE_LOWER);

            return Config::get('PAYNOW_KEY') !== '' && is_string($data['hash'] ?? null) && hash_equals(self::signature($data), strtoupper($data['hash']));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function safeUrl(string $url): bool
    {
        $parts = wp_parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), ['www.paynow.co.zw', 'paynow.co.zw'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']) && in_array($parts['port'] ?? 443, [443], true);
    }

    public static function request(string $url, array $data): array
    {
        if (! self::safeUrl($url)) {
            throw new \RuntimeException('Invalid Paynow endpoint.');
        }
        $response = wp_remote_post($url, ['body' => $data, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new \RuntimeException('Paynow confirmation is temporarily unavailable.');
        }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 65536) {
            throw new \RuntimeException('Invalid Paynow response.');
        }
        parse_str($body, $result);
        if (! self::verify($result)) {
            throw new \RuntimeException('Paynow response could not be authenticated.');
        }

        return array_change_key_case($result, CASE_LOWER);
    }

    public static function matches(object $payment, array $data): bool
    {
        return ($data['reference'] ?? '') === $payment->reference && is_string($data['amount'] ?? null)
            && preg_match('/^\d{1,8}\.\d{2}$/D', $data['amount']) && (int) str_replace('.', '', $data['amount']) === (int) $payment->amount_cents
            && (! isset($data['currency']) || $data['currency'] === 'USD') && is_string($data['paynowreference'] ?? null) && strlen($data['paynowreference']) > 0
            && strlen($data['paynowreference']) <= 100 && (! $payment->provider_reference || $payment->provider_reference === $data['paynowreference']);
    }

    public static function assertIntegration(object $payment): void
    {
        if ($payment->integration_id !== (string) Config::get('PAYNOW_ID') || $payment->mode !== Config::get('PAYNOW_MODE', 'test')) {
            throw new \RuntimeException('This payment needs its original Paynow configuration for reconciliation.');
        }
    }

    public static function start(object $payment): string
    {
        self::assertIntegration($payment);
        $origin = home_url('/');
        if (wp_parse_url($origin, PHP_URL_SCHEME) !== 'https') {
            throw new \DomainException('Paynow needs your public HTTPS WordPress URL.');
        }
        if ($payment->mode === 'live' && ! Config::enabled('PAYNOW_LIVE_APPROVED')) {
            throw new \DomainException('Live Paynow payments have not been approved in configuration.');
        }
        $data = ['id' => $payment->integration_id, 'reference' => $payment->reference, 'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'additionalinfo' => 'ATS CV Detector '.$payment->plan.' - one-time payment',
            'returnurl' => add_query_arg(['atscv_payment' => $payment->reference], Config::page()),
            'resulturl' => rest_url('atscv/v1/paynow/result/'.$payment->reference), 'status' => 'Message'];
        $email = $payment->mode === 'test' ? Config::get('PAYNOW_TEST_EMAIL') : get_userdata($payment->user_id)?->user_email;
        if ($email) {
            $data['authemail'] = $email;
        }
        $data['merchanttrace'] = $payment->trace;
        $data['hash'] = self::signature($data);
        $result = self::request('https://www.paynow.co.zw/interface/initiatetransaction', $data);
        if (strtolower($result['status'] ?? '') === 'error') {
            throw new \DomainException('Paynow could not open this checkout.');
        }
        if (strtolower($result['status'] ?? '') !== 'ok' || ! self::safeUrl($result['browserurl'] ?? '') || ! self::safeUrl($result['pollurl'] ?? '')) {
            throw new \RuntimeException('Checkout response is incomplete. Check payment status before trying again.');
        }
        DB::transaction(static function () use ($payment, $result) {
            $locked = Billing::locked($payment);
            if ($locked->provider_reference && isset($result['paynowreference']) && $locked->provider_reference !== $result['paynowreference']) {
                throw new \RuntimeException('Provider reference mismatch.');
            }
            DB::update('payments', ['poll_url' => $locked->poll_url ?: Config::crypt($result['pollurl']), 'checkout_url' => Config::crypt($result['browserurl']),
                'provider_reference' => $locked->provider_reference ?: ($result['paynowreference'] ?? null), 'updated_at' => DB::now()], ['id' => $payment->id]);
        });

        return $result['browserurl'];
    }

    public static function poll(object $payment): array
    {
        self::assertIntegration($payment);
        if (! $payment->poll_url) {
            $data = ['id' => $payment->integration_id, 'merchanttrace' => $payment->trace, 'status' => 'Message'];
            $data['hash'] = self::signature($data);
            $result = self::request('https://www.paynow.co.zw/interface/trace', $data);
            if (strtolower($result['status'] ?? '') === 'notfound') {
                return ['status' => 'NotFound'];
            }
            if (! self::matches($payment, $result) || ! self::safeUrl($result['pollurl'] ?? '')) {
                throw new \RuntimeException('Paynow trace could not be matched.');
            }
            DB::update('payments', ['poll_url' => Config::crypt($result['pollurl']), 'provider_reference' => $result['paynowreference']], ['id' => $payment->id]);
            $payment->poll_url = Config::crypt($result['pollurl']);
            $payment->provider_reference = $result['paynowreference'];
        }
        $result = self::request(Config::crypt($payment->poll_url, true), []);
        if (! self::matches($payment, $result)) {
            throw new \RuntimeException('Payment details do not match.');
        }

        return $result;
    }
}
