<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        foreach ($data as $value) {
            if (! is_scalar($value)) {
                return false;
            }
        }

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
        if (! in_array($payment->mode, ['test', 'live'], true) || $payment->currency !== 'USD' || $payment->amount_cents < 1) {
            throw new \DomainException('Invalid Paynow payment configuration.');
        }
        if ($payment->mode === 'live' && ! config('billing.paynow.live_currency_confirmed')) {
            throw new \DomainException('Confirm the merchant USD currency before enabling live checkout.');
        }
        $callbackBase = rtrim((string) config('billing.paynow.callback_url'), '/');
        if ($callbackBase !== '' && (parse_url($callbackBase, PHP_URL_SCHEME) !== 'https'
            || ! parse_url($callbackBase, PHP_URL_HOST) || parse_url($callbackBase, PHP_URL_USER)
            || parse_url($callbackBase, PHP_URL_PASS) || parse_url($callbackBase, PHP_URL_QUERY)
            || parse_url($callbackBase, PHP_URL_FRAGMENT))) {
            throw new \DomainException('Paynow callback base must be a public HTTPS URL.');
        }
        $isLocal = config('app.env') === 'local';
        $returnBase = rtrim((string) config('billing.paynow.return_url'), '/');
        if ($returnBase === '' && $isLocal) {
            // A quick Cloudflare tunnel can disappear during checkout. Return the
            // customer's browser to this machine so it can verify via Paynow poll.
            $returnBase = rtrim((string) config('app.url'), '/');
        }
        if ($returnBase !== '' && (parse_url($returnBase, PHP_URL_SCHEME) !== 'https'
            && !($isLocal && parse_url($returnBase, PHP_URL_SCHEME) === 'http')
            || ! parse_url($returnBase, PHP_URL_HOST) || parse_url($returnBase, PHP_URL_USER)
            || parse_url($returnBase, PHP_URL_PASS) || parse_url($returnBase, PHP_URL_QUERY)
            || parse_url($returnBase, PHP_URL_FRAGMENT))) {
            throw new \DomainException('Paynow return base is invalid for this environment.');
        }
        $callback = fn (string $route, string $base) => $base === '' ? route($route, $payment->reference)
            : $base.route($route, $payment->reference, false);
        $data = [
            'id' => config('billing.paynow.integration_id'),
            'reference' => $payment->reference,
            'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'additionalinfo' => $payment->kind === 'topup' ? 'atscvdetector '.$payment->credits.' AI credits - one-time purchase'
                : 'atscvdetector '.SubscriptionPlan::findOrFail($payment->subscription_plan_id)->name.' - '.$payment->credits.' AI credits - '.($payment->billing_interval === 'annually' ? 'one year' : 'one month').' access - one-time payment',
            'returnurl' => $callback('billing.paynow.return', $returnBase !== '' ? $returnBase : $callbackBase),
            'resulturl' => $callback('billing.paynow.notify', $callbackBase),
            'status' => 'Message',
        ];
        if (app()->isProduction() && (parse_url($data['returnurl'], PHP_URL_SCHEME) !== 'https' || parse_url($data['resulturl'], PHP_URL_SCHEME) !== 'https')) {
            throw new \DomainException('Production Paynow callbacks require HTTPS.');
        }
        // Test payments can only be completed by the integration's merchant account.
        $authEmail = $payment->mode === 'test' ? config('billing.paynow.test_email') : $email;
        if ($authEmail) {
            $data['authemail'] = $authEmail;
        }
        // Persist the documented recovery identifier before sending an initialization.
        $payment->update(['merchant_trace' => substr(hash('sha256', $payment->reference), 0, 32)]);
        $data['merchanttrace'] = $payment->merchant_trace;
        $data['hash'] = $this->signature($data);
        $result = $this->parse(Http::asForm()->timeout(20)->withoutRedirecting()
            ->post('https://www.paynow.co.zw/interface/initiatetransaction', $data)->throw()->body());
        if (strtolower($result['status'] ?? '') === 'error') {
            throw new \DomainException('Paynow rejected checkout initialization.');
        }
        if (strtolower($result['status'] ?? '') !== 'ok' || ! $this->safePollUrl($result['pollurl'] ?? '') || ! $this->safeUrl($result['browserurl'] ?? '')) {
            throw new \RuntimeException('Unable to start Paynow checkout.');
        }
        DB::transaction(function () use ($payment, $result) {
            User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->provider_reference && isset($result['paynowreference']) && $locked->provider_reference !== $result['paynowreference']) {
                throw new \RuntimeException('Paynow initialization reference mismatch.');
            }
            $locked->update(['poll_url' => $locked->poll_url ?? $result['pollurl'], 'checkout_url' => $result['browserurl'],
                'provider_reference' => $locked->provider_reference ?? ($result['paynowreference'] ?? null)]);
        });

        return $result['browserurl'];
    }

    public function safeUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(strtolower(parse_url($url, PHP_URL_HOST) ?? ''), ['www.paynow.co.zw', 'paynow.co.zw'], true)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)
            && ! parse_url($url, PHP_URL_FRAGMENT)
            && in_array(parse_url($url, PHP_URL_PORT), [null, 443], true);
    }

    public function safePollUrl(string $url): bool
    {
        return $this->safeUrl($url) && strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/')) === '/interface/checkpayment';
    }

    public function matches(BillingPayment $payment, array $data): bool
    {
        return ($data['reference'] ?? null) === $payment->reference
            && is_string($data['amount'] ?? null) && preg_match('/^\d{1,8}\.\d{2}$/D', $data['amount'])
            && (int) str_replace('.', '', $data['amount']) === $payment->amount_cents
            && $payment->currency === 'USD'
            && (! isset($data['currency']) || $data['currency'] === $payment->currency)
            && is_string($data['paynowreference'] ?? null) && $data['paynowreference'] !== ''
            && (! $payment->provider_reference || $payment->provider_reference === $data['paynowreference']);
    }

    public function status(BillingPayment $payment): string
    {
        if (! $payment->poll_url && $payment->merchant_trace) {
            $data = ['id' => config('billing.paynow.integration_id'), 'merchanttrace' => $payment->merchant_trace, 'status' => 'Message'];
            $data['hash'] = $this->signature($data);
            $trace = $this->parse(Http::asForm()->timeout(20)->withoutRedirecting()
                ->post('https://www.paynow.co.zw/interface/trace', $data)->throw()->body());
            if (strtolower($trace['status'] ?? '') === 'notfound') {
                $payment->update(['next_check_at' => now()->addMinutes(5), 'verification_attempts' => $payment->verification_attempts + 1]);

                return 'pending';
            }
            if (! $this->matches($payment, $trace) || ! $this->safePollUrl($trace['pollurl'] ?? '')) {
                throw new \RuntimeException('Paynow trace could not be verified.');
            }
            DB::transaction(function () use ($payment, $trace) {
                $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (! $this->matches($locked, $trace)) {
                    throw new \RuntimeException('Paynow trace reference mismatch.');
                }
                $locked->update(['poll_url' => $locked->poll_url ?? $trace['pollurl'], 'provider_reference' => $trace['paynowreference']]);
            });
            $payment->refresh();
        }
        if (! $payment->poll_url || ! $this->safePollUrl($payment->poll_url)) {
            return 'pending';
        }
        $result = $this->parse(Http::asForm()->timeout(20)->withoutRedirecting()->post($payment->poll_url, [])->throw()->body());
        if (! $this->matches($payment, $result)) {
            throw new \RuntimeException('Payment details do not match.');
        }
        $reference = (string) ($result['paynowreference'] ?? '');
        if ($reference === '' || ($payment->provider_reference && $payment->provider_reference !== $reference)) {
            throw new \RuntimeException('Payment reference does not match.');
        }
        DB::transaction(function () use ($payment, $reference, $result) {
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->provider_reference && $locked->provider_reference !== $reference) {
                throw new \RuntimeException('Payment reference does not match.');
            }
            $locked->update(['provider_reference' => $reference, 'provider_status' => $result['status'] ?? 'Unknown',
                'verified_at' => now(), 'verification_attempts' => $locked->verification_attempts + 1,
                'next_check_at' => now()->addMinutes(in_array(strtolower($result['status'] ?? ''), ['paid', 'awaiting delivery', 'delivered', 'refunded', 'disputed', 'cancelled'], true) ? 1440 : 5)]);
        });

        return match (strtolower($result['status'] ?? '')) {
            'paid', 'awaiting delivery', 'delivered' => 'paid',
            'refunded', 'disputed' => 'reversed',
            'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}
