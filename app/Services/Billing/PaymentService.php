<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function refresh(BillingPayment $payment): void
    {
        try {
            if ($payment->provider === 'paynow') {
                DB::transaction(function () use ($payment) {
                    User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
                    $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    $this->refreshVerified($locked);
                    app(BillingRecords::class)->record($locked->fresh());
                }, 3);
            } else {
                $this->refreshVerified($payment);
            }
            $confirmed = $payment->fresh();
            if (in_array($confirmed->status, ['paid', 'test_paid', 'reversed'], true)) {
                app(AuditTrail::class)->record($confirmed->user_id, 'payment.verified', 'BillingPayment', $confirmed->id,
                    ['reference' => $confirmed->reference, 'provider' => $confirmed->provider, 'mode' => $confirmed->mode,
                        'status' => $confirmed->status, 'subscription_id' => $confirmed->subscription_id],
                    'verified:'.$confirmed->id.':'.$confirmed->status.':'.($confirmed->paid_at?->timestamp ?? 0));
            }

        } catch (\Throwable $exception) {
            app(AuditTrail::class)->record($payment->user_id, 'payment.verification_failed', 'BillingPayment', $payment->id,
                ['reference' => $payment->reference, 'provider' => $payment->provider, 'mode' => $payment->mode, 'reason' => 'Provider confirmation could not be completed.']);
            throw $exception;
        }
    }

    private function refreshVerified(BillingPayment $payment): void
    {
        if ($payment->provider !== 'paynow') {
            throw new \DomainException('Unsupported payment provider.');
        }
        $gateway = app(PaynowGateway::class);
        $status = $gateway->status($payment);
        DB::transaction(function () use ($payment, $status) {
            User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($status === 'paid' && $locked->mode !== 'live') {
                $locked->update(['status' => 'test_paid']);

                return;
            }
            if ($status === 'paid' && ! $locked->paid_at && $locked->status !== 'reversed') {
                if ($locked->kind === 'topup') {
                    app(AiCreditService::class)->topup(User::findOrFail($locked->user_id), $locked->credits, 'payment:'.$locked->reference);
                    $locked->update(['status' => 'paid', 'paid_at' => now()]);

                    return;
                }
                $latestEnd = Subscription::where('user_id', $locked->user_id)
                    ->where('status', 'active')
                    ->where('ends_at', '>', now())->max('ends_at');
                $start = $latestEnd ? Carbon::parse($latestEnd) : now();
                $subscription = new Subscription;
                $subscription->forceFill(['user_id' => $locked->user_id, 'subscription_plan_id' => $locked->subscription_plan_id,
                    'status' => 'active', 'starts_at' => $start,
                    'ends_at' => $locked->billing_interval === 'annually' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow()])->save();
                $locked->subscription_id = $subscription->id;
                $locked->paid_at = now();
                $locked->status = 'paid';
            } elseif ($status === 'reversed') {
                if ($locked->kind === 'topup' && $locked->paid_at) {
                    app(AiCreditService::class)->reverseTopup(User::findOrFail($locked->user_id), $locked->credits, 'reverse:'.$locked->reference);
                }
                if ($locked->subscription_id) {
                    Subscription::whereKey($locked->subscription_id)->update(['status' => 'cancelled']);
                }
                $locked->status = 'reversed';
            } elseif (! $locked->paid_at && $locked->status !== 'reversed') {
                $locked->status = $status;
            }
            $locked->save();
            app(AiCreditService::class)->wallet(User::findOrFail($locked->user_id));
        });
        $payment->touch();
    }
}
