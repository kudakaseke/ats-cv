<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PaystackGateway
{
    public function mode(): string
    {
        $key = config('paystack.secret_key');
        if (! is_string($key) || ! preg_match('/^sk_(test|live)_/', $key, $match)) {
            throw new \RuntimeException('Paystack is not configured.');
        }

        return $match[1];
    }

    private function client()
    {
        return Http::baseUrl('https://api.paystack.co')->withToken(config('paystack.secret_key'))->acceptJson()->connectTimeout(10)->timeout(25);
    }

    public function start(BillingPayment $payment, string $email): string
    {
        if ($payment->mode !== $this->mode()) {
            throw new \RuntimeException('Payment mode mismatch.');
        }
        $input = ['email' => $email, 'amount' => $payment->amount_cents, 'currency' => $payment->currency,
            'reference' => $payment->reference, 'callback_url' => route('billing.show', $payment->reference),
            'metadata' => ['payment_reference' => $payment->reference]];
        if ($payment->kind !== 'topup') {
            $plan = $this->client()->get('/plan/'.rawurlencode($payment->provider_plan_id))->throw()->json('data');
            if (($plan['plan_code'] ?? '') !== $payment->provider_plan_id || ($plan['domain'] ?? '') !== $payment->mode ||
                ($plan['currency'] ?? '') !== $payment->currency || (int) ($plan['amount'] ?? 0) !== $payment->amount_cents ||
                ($plan['interval'] ?? '') !== $payment->billing_interval || ! empty($plan['is_deleted'])) {
                throw new \RuntimeException('Paystack plan amount or currency mismatch.');
            }
            $input['plan'] = $payment->provider_plan_id;
        }
        $data = $this->client()->post('/transaction/initialize', $input)->throw()->json('data');
        $url = $data['authorization_url'] ?? '';
        if (($data['reference'] ?? '') !== $payment->reference || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.paystack.com') {
            throw new \RuntimeException('Invalid checkout response.');
        }
        $payment->update(['checkout_url' => $url, 'provider_reference' => $payment->reference]);

        return $url;
    }

    public function verified(string $reference): array
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference))->throw();
        if ($response->json('status') !== true || $response->json('data.reference') !== $reference) {
            throw new \RuntimeException('Transaction verification failed.');
        }

        return $response->json('data');
    }

    public function planCode(array $data): ?string
    {
        return data_get($data, 'plan_object.plan_code') ?? (is_array($data['plan'] ?? null) ? ($data['plan']['plan_code'] ?? null) : (is_string($data['plan'] ?? null) ? $data['plan'] : null));
    }

    public function refresh(BillingPayment $payment): void
    {
        $this->apply($payment, $this->verified($payment->reference));
        // Reconcile previously observed receipts too (refunds/reversals).
        foreach (DB::table('paystack_receipts')->where('billing_payment_id', $payment->id)->where('reference', '!=', $payment->reference)->pluck('reference') as $reference) {
            $this->apply($payment, $this->verified($reference));
        }
        if ($payment->recurring && $payment->provider_customer_id) {
            $customer = $this->client()->get('/customer/'.rawurlencode($payment->provider_customer_id))->throw()->json('data');
            $page = 1;
            do {
                $list = $this->client()->get('/transaction', ['customer' => $customer['id'], 'from' => $payment->created_at->toIso8601String(), 'perPage' => 100, 'page' => $page])->throw()->json();
                foreach ($list['data'] ?? [] as $item) {
                    if ($this->planCode($item) === $payment->provider_plan_id && is_string($item['reference'] ?? null)) {
                        $this->apply($payment, $this->verified($item['reference']));
                    }
                }
                $pages = (int) ($list['meta']['pageCount'] ?? 1);
                if ($pages > 100) {
                    throw new \RuntimeException('Transaction history requires manual reconciliation.');
                }
            } while ($page++ < $pages);
        }
        $payment->touch();
    }

    public function apply(BillingPayment $payment, array $data): void
    {
        if ($payment->mode !== $this->mode() || ($data['domain'] ?? '') !== $payment->mode ||
            ($data['currency'] ?? '') !== $payment->currency || (int) ($data['amount'] ?? 0) !== $payment->amount_cents ||
            empty($data['id']) || ! is_string($data['reference'] ?? null)) {
            throw new \RuntimeException('Verified payment details mismatch.');
        }
        $initial = $data['reference'] === $payment->reference;
        $customer = data_get($data, 'customer.customer_code');
        if (! $initial && (! $payment->recurring || ! $payment->provider_customer_id || $customer !== $payment->provider_customer_id)) {
            throw new \RuntimeException('Customer mismatch.');
        }
        if ($payment->kind !== 'topup' && $this->planCode($data) !== $payment->provider_plan_id) {
            throw new \RuntimeException('Plan mismatch.');
        }
        if ($initial && ($payment->provider_customer_id
            ? $customer !== $payment->provider_customer_id
            : strtolower((string) data_get($data, 'customer.email')) !== strtolower(User::findOrFail($payment->user_id)->email))) {
            throw new \RuntimeException('Checkout identity mismatch.');
        }
        $status = $data['status'] ?? '';
        if (! in_array($status, ['success', 'reversed'], true)) {
            return;
        }
        $paid = Carbon::parse($data['paid_at'] ?? $data['paidAt'] ?? throw new \RuntimeException('Missing payment date.'));
        if ($paid->isFuture() || $paid->lt($payment->created_at->copy()->subMinutes(5))) {
            throw new \RuntimeException('Invalid payment date.');
        }
        DB::transaction(function () use ($payment, $data, $status, $paid, $customer) {
            $user = User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('paystack_receipts')->where('provider_transaction_id', (string) $data['id'])->first();
            if ($receipt && $receipt->billing_payment_id !== $locked->id) {
                throw new \RuntimeException('Transaction already assigned.');
            }
            if ($receipt) {
                if ($status === 'reversed' && $receipt->status !== 'reversed') {
                    if ($receipt->subscription_id && ! DB::table('paystack_receipts')->where('subscription_id', $receipt->subscription_id)->where('id', '!=', $receipt->id)->where('status', 'success')->exists()) {
                        Subscription::whereKey($receipt->subscription_id)->update(['status' => 'cancelled']);
                    }
                    if ($locked->kind === 'topup' && $locked->mode === 'live') {
                        app(AiCreditService::class)->reverseTopup($user, $locked->credits, 'reverse:'.$data['reference']);
                    }
                    DB::table('paystack_receipts')->where('id', $receipt->id)->update(['status' => 'reversed', 'updated_at' => now()]);
                    $locked->update(['status' => 'reversed']);
                    app(AiCreditService::class)->wallet($user);
                }

                return;
            }
            $subscription = null;
            if ($status === 'success' && $locked->mode === 'live') {
                if ($locked->kind === 'topup') {
                    app(AiCreditService::class)->topup($user, $locked->credits, 'payment:'.$data['reference']);
                } else {
                    $anchor = $locked->paid_at ?? $paid;
                    $cycle = max(0, (int) round($anchor->diffInMonths($paid) / ($locked->billing_interval === 'annually' ? 12 : 1)));
                    $period = 'paystack:'.$locked->id.':'.$cycle;
                    $subscription = Subscription::where('funding_period', $period)->first();
                    if (! $subscription) {
                        $subscription = new Subscription;
                        $subscription->forceFill(['user_id' => $user->id, 'subscription_plan_id' => $locked->subscription_plan_id, 'status' => 'active',
                            'funding_period' => $period, 'starts_at' => $paid, 'ends_at' => $locked->billing_interval === 'annually' ? $paid->copy()->addYearNoOverflow() : $paid->copy()->addMonthNoOverflow()])->save();
                    }
                }
            }
            DB::table('paystack_receipts')->insert(['billing_payment_id' => $locked->id, 'subscription_id' => $subscription?->id,
                'provider_transaction_id' => (string) $data['id'], 'reference' => $data['reference'], 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            $locked->update(['status' => $status === 'reversed' ? 'reversed' : ($locked->mode === 'live' ? 'paid' : 'test_paid'),
                'provider_customer_id' => $customer, 'paid_at' => $locked->paid_at ?? $paid, 'subscription_id' => $subscription?->id]);
            if ($locked->mode === 'live') {
                app(AiCreditService::class)->wallet($user);
            }
            app(AuditTrail::class)->record($user->id, 'payment.provider_confirmed', 'BillingPayment', $locked->id,
                ['provider' => 'paystack', 'mode' => $locked->mode, 'status' => $locked->status, 'reference' => $data['reference'],
                    'provider_transaction_id' => (string) $data['id'], 'amount_cents' => $locked->amount_cents, 'currency' => $locked->currency,
                    'subscription_id' => $subscription?->id, 'credits_granted' => $status === 'success' && $locked->mode === 'live' ? $locked->credits : 0],
                'paystack:'.$data['id'].':'.$status);

        }, 3);
    }

    public function subscriptionEvent(array $data): void
    {
        $code = $data['subscription_code'] ?? data_get($data, 'subscription.subscription_code', '');
        if (! is_string($code) || ! str_starts_with($code, 'SUB_')) {
            return;
        }
        $verified = $this->client()->get('/subscription/'.rawurlencode($code))->throw()->json('data');
        $customer = data_get($verified, 'customer.customer_code');
        $plan = data_get($verified, 'plan.plan_code');
        if (($verified['subscription_code'] ?? '') !== $code || ! $customer || ! $plan) {
            throw new \RuntimeException('Subscription verification failed.');
        }
        $payment = BillingPayment::where('provider', 'paystack')->where('mode', $this->mode())->where('recurring', true)
            ->where('provider_customer_id', $customer)->where('provider_plan_id', $plan)->sole();
        $payment->update(['provider_reference' => $code, 'recurring_status' => strtoupper($verified['status'] ?? 'UNKNOWN')]);
    }

    public function cancel(BillingPayment $payment): void
    {
        if ($payment->mode !== $this->mode() || ! str_starts_with($payment->provider_reference ?? '', 'SUB_')) {
            throw new \RuntimeException('Subscription must be reconciled first.');
        }
        $details = $this->client()->get('/subscription/'.rawurlencode($payment->provider_reference))->throw()->json('data');
        if (($details['subscription_code'] ?? '') !== $payment->provider_reference || data_get($details, 'customer.customer_code') !== $payment->provider_customer_id || data_get($details, 'plan.plan_code') !== $payment->provider_plan_id) {
            throw new \RuntimeException('Subscription mismatch.');
        }
        $response = $this->client()->post('/subscription/disable', ['code' => $payment->provider_reference, 'token' => $details['email_token'] ?? ''])->throw();
        if ($response->json('status') !== true) {
            throw new \RuntimeException('Cancellation not confirmed.');
        }
        $payment->update(['recurring_status' => 'CANCELLED']);
    }
}
