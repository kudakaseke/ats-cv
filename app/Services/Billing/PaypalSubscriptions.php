<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaypalSubscriptions
{
    public function __construct(private PaypalGateway $gateway) {}

    private function value(BillingPayment $payment): string
    {
        return number_format($payment->amount_cents / 100, 2, '.', '');
    }

    public function validatePlan(BillingPayment $payment): void
    {
        $plan = $this->gateway->client($payment)->get('/v1/billing/plans/'.rawurlencode($payment->provider_plan_id))->throw()->json();
        $cycles = $plan['billing_cycles'] ?? [];
        $cycle = $cycles[0] ?? [];
        if (($plan['id'] ?? '') !== $payment->provider_plan_id || ($plan['status'] ?? '') !== 'ACTIVE'
            || count($cycles) !== 1 || ($cycle['tenure_type'] ?? '') !== 'REGULAR' || ($cycle['total_cycles'] ?? -1) !== 0
            || ($cycle['frequency']['interval_unit'] ?? '') !== ($payment->billing_interval === 'annually' ? 'YEAR' : 'MONTH')
            || ($cycle['frequency']['interval_count'] ?? 0) !== 1
            || ($cycle['pricing_scheme']['fixed_price']['currency_code'] ?? '') !== 'USD'
            || ($cycle['pricing_scheme']['fixed_price']['value'] ?? '') !== $this->value($payment)
            || (float) ($plan['payment_preferences']['setup_fee']['value'] ?? 0) !== 0.0
            || (float) ($plan['taxes']['percentage'] ?? 0) !== 0.0) {
            throw new \RuntimeException('PayPal plan does not match the offer.');
        }
    }

    public function start(BillingPayment $payment, string $email): string
    {
        try {
            $this->validatePlan($payment);
        } catch (\Throwable $exception) {
            // Validation happens before subscription creation, so this is safe to retry.
            $payment->update(['recurring_status' => 'SETUP_FAILED']);
            throw $exception;
        }
        $result = app(PaypalSdk::class)->createSubscription($payment, [
            'plan_id' => $payment->provider_plan_id, 'custom_id' => $payment->reference,
            'application_context' => ['brand_name' => 'atscvdetector', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => route('paypal.success', ['reference' => $payment->reference]), 'cancel_url' => route('paypal.cancel', ['reference' => $payment->reference])],
        ]);
        $url = collect($result['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? '';
        $host = $payment->mode === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
        if (! is_string($result['id'] ?? null) || ! preg_match('/^I-[A-Z0-9]+$/', $result['id'])
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host) {
            throw new \RuntimeException('Unexpected subscription response.');
        }
        $payment->update(['provider_reference' => $result['id'], 'checkout_url' => $url, 'recurring_status' => 'APPROVAL_PENDING']);

        return $url;
    }

    private function details(BillingPayment $payment): array
    {
        $data = $this->gateway->client($payment)->get('/v1/billing/subscriptions/'.rawurlencode($payment->provider_reference))->throw()->json();
        if (($data['id'] ?? '') !== $payment->provider_reference || ($data['plan_id'] ?? '') !== $payment->provider_plan_id
            || ($data['custom_id'] ?? '') !== $payment->reference || (string) ($data['quantity'] ?? '1') !== '1') {
            throw new \RuntimeException('Subscription details do not match.');
        }

        return $data;
    }

    public function cancel(BillingPayment $payment): void
    {
        $data = $this->details($payment);
        if (! in_array($data['status'] ?? '', ['CANCELLED', 'EXPIRED'], true)) {
            $this->gateway->client($payment)->post('/v1/billing/subscriptions/'.rawurlencode($payment->provider_reference).'/cancel',
                ['reason' => 'Customer cancelled automatic renewal in atscvdetector.'])->throw();
        }
        $payment->update(['recurring_status' => 'CANCELLED']);
    }

    public function refresh(BillingPayment $payment, ?Carbon $transactionDate = null): void
    {
        if (! $payment->provider_reference) {
            return;
        }
        $data = $this->details($payment);
        $payment->update(['recurring_status' => $data['status'] ?? 'UNKNOWN']);
        // Approval/activation is not proof of a successful payment. Query actual transactions.
        $last = $data['billing_info']['last_payment']['time'] ?? null;
        $dates = [now()];
        if ($last) {
            $dates[] = Carbon::parse($last);
        }
        if ($transactionDate) {
            $dates[] = $transactionDate;
        }
        foreach (collect($dates)->unique(fn ($date) => $date->format('Y-m-d')) as $date) {
            $transactions = $this->gateway->client($payment)
                ->get('/v1/billing/subscriptions/'.rawurlencode($payment->provider_reference).'/transactions', [
                    'start_time' => $date->copy()->subDays(29)->toIso8601ZuluString(),
                    'end_time' => $date->copy()->addSecond()->min(now()->addSecond())->toIso8601ZuluString(),
                ])->throw()->json();
            if (isset($transactions['links']) && collect($transactions['links'])->contains('rel', 'next')) {
                throw new \RuntimeException('Transaction pagination requires reconciliation.');
            }
            foreach ($transactions['transactions'] ?? [] as $transaction) {
                $this->apply($payment, $transaction, $data);
            }
        }
        $payment->touch();
    }

    private function apply(BillingPayment $payment, array $transaction, array $agreement): void
    {
        $status = $transaction['status'] ?? '';
        $money = $transaction['amount_with_breakdown']['gross_amount'] ?? [];
        if (! is_string($transaction['id'] ?? null) || ! is_string($transaction['time'] ?? null)
            || ! in_array($status, ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'REVERSED'], true)) {
            return;
        }
        if (($money['currency_code'] ?? '') !== $payment->currency || ($money['value'] ?? '') !== $this->value($payment)) {
            throw new \RuntimeException('Subscription payment amount does not match.');
        }
        $paidAt = Carbon::parse($transaction['time'])->setTimezone(config('app.timezone'));
        if ($paidAt->isFuture() || $paidAt->lt($payment->created_at->copy()->subMinutes(5))) {
            throw new \RuntimeException('Invalid transaction date.');
        }
        $end = $payment->billing_interval === 'annually' ? $paidAt->copy()->addYearNoOverflow() : $paidAt->copy()->addMonthNoOverflow();
        $lastTime = $agreement['billing_info']['last_payment']['time'] ?? null;
        $nextTime = $agreement['billing_info']['next_billing_time'] ?? null;
        if ($lastTime && $nextTime && Carbon::parse($lastTime)->equalTo($paidAt) && ($agreement['billing_info']['failed_payments_count'] ?? 0) === 0) {
            $next = Carbon::parse($nextTime)->setTimezone(config('app.timezone'));
            // Respect calendar billing anchors without extending access for an unpaid retry cycle.
            if ($next->gt($paidAt) && $next->lte($end->copy()->addDays(3))) {
                $end = $next;
            }
        }
        DB::transaction(function () use ($payment, $transaction, $status, $paidAt, $end) {
            User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('paypal_subscription_receipts')->where('billing_payment_id', $locked->id)->where('transaction_id', $transaction['id'])->first();
            if ($status !== 'COMPLETED') {
                if ($receipt) {
                    if (! DB::table('paypal_subscription_receipts')->where('subscription_id', $receipt->subscription_id)->where('id', '!=', $receipt->id)->where('status', 'COMPLETED')->exists()) {
                        Subscription::whereKey($receipt->subscription_id)->update(['status' => 'cancelled']);
                    }
                    DB::table('paypal_subscription_receipts')->where('id', $receipt->id)->update(['status' => $status, 'updated_at' => now()]);
                    app(AiCreditService::class)->wallet(User::findOrFail($locked->user_id));
                } else {
                    DB::table('paypal_subscription_receipts')->insert(['billing_payment_id' => $locked->id, 'transaction_id' => $transaction['id'], 'status' => $status, 'paid_at' => $paidAt, 'created_at' => now(), 'updated_at' => now()]);
                }

                return;
            }
            if ($receipt) {
                return;
            }
            $subscription = null;
            if ($locked->mode === 'live') {
                $anchor = $locked->paid_at ?? $paidAt;
                $cycle = max(0, (int) round($anchor->diffInMonths($paidAt) / ($locked->billing_interval === 'annually' ? 12 : 1)));
                $period = 'paypal:'.$locked->id.':'.$cycle;
                $subscription = Subscription::where('funding_period', $period)->first();
                if (! $subscription) {
                    $subscription = new Subscription;
                    $subscription->forceFill(['user_id' => $locked->user_id, 'subscription_plan_id' => $locked->subscription_plan_id,
                        'status' => 'active', 'starts_at' => $paidAt, 'funding_period' => $period,
                        'ends_at' => $end])->save();
                }
            }
            DB::table('paypal_subscription_receipts')->insert(['billing_payment_id' => $locked->id, 'transaction_id' => $transaction['id'],
                'subscription_id' => $subscription?->id, 'status' => 'COMPLETED', 'paid_at' => $paidAt, 'created_at' => now(), 'updated_at' => now()]);
            $locked->update(['status' => $locked->mode === 'live' ? 'paid' : 'test_paid', 'paid_at' => $locked->paid_at ?? $paidAt, 'subscription_id' => $subscription?->id]);
            if ($locked->mode === 'live') {
                app(AiCreditService::class)->wallet(User::findOrFail($locked->user_id));
            }
        });
    }
}
