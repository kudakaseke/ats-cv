<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function refresh(BillingPayment $payment): void
    {
        $verificationLock = Cache::lock('paynow-verification:'.$payment->id, 90);
        if (! $verificationLock->get()) {
            throw new \RuntimeException('Payment verification is already in progress.');
        }
        try {
            if ($payment->provider !== 'paynow') {
                throw new \DomainException('Unsupported payment provider.');
            }
            // Network I/O must not hold a user/database row lock.
            $status = app(PaynowGateway::class)->status($payment->fresh());
            DB::transaction(function () use ($payment, $status) {
                User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
                $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $this->refreshVerified($locked, $status);
                app(BillingRecords::class)->record($locked->fresh());
            }, 3);
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
        } finally {
            $verificationLock->release();
        }
    }

    private function refreshVerified(BillingPayment $payment, string $status): void
    {
        if ($payment->provider !== 'paynow') {
            throw new \DomainException('Unsupported payment provider.');
        }
        DB::transaction(function () use ($payment, $status) {
            User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'reversed' && $status !== 'reversed') {
                return;
            }
            if (in_array($locked->status, ['paid', 'test_paid'], true) && in_array($status, ['pending', 'cancelled', 'failed'], true)) {
                return;
            }
            $sandbox = $locked->mode === 'test' && $locked->kind === 'subscription' && Subscription::localTestCreditsEnabled();
            if ($sandbox && Subscription::where('user_id', $locked->user_id)->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()) {
                // Local test allocations must never replace real paid access.
                $sandbox = false;
            }
            if ($status === 'paid' && $locked->mode !== 'live' && ! $sandbox) {
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
                    ->where('status', $sandbox ? 'sandbox_active' : 'active')
                    ->where('ends_at', '>', now())->max('ends_at');
                $start = $latestEnd ? Carbon::parse($latestEnd) : now();
                $subscription = new Subscription;
                $subscription->forceFill(['user_id' => $locked->user_id, 'subscription_plan_id' => $locked->subscription_plan_id,
                    'status' => $sandbox ? 'sandbox_active' : 'active', 'starts_at' => $start,
                    'ends_at' => $locked->billing_interval === 'annually' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow()])->save();
                $locked->subscription_id = $subscription->id;
                $locked->paid_at = now();
                $locked->status = $sandbox ? 'test_paid' : 'paid';
            } elseif ($status === 'reversed') {
                if ($locked->kind === 'topup' && $locked->paid_at) {
                    app(AiCreditService::class)->reverseTopup(User::findOrFail($locked->user_id), $locked->credits, 'reverse:'.$locked->reference);
                }
                if ($locked->subscription_id) {
                    Subscription::whereKey($locked->subscription_id)->update(['status' => 'cancelled']);
                    app(AiCreditService::class)->reversePooledSubscriptionCredits(User::findOrFail($locked->user_id),
                        $locked->subscription_id, 'subscription-reversal:'.$locked->id);
                }
                $locked->status = 'reversed';
            } elseif (! $locked->paid_at && $locked->status !== 'reversed') {
                $locked->status = $status;
            }
            $locked->save();
            if ($status === 'paid' && in_array($locked->status, ['paid', 'test_paid'], true) && $locked->subscription_id && ($locked->mode === 'live' || $sandbox)
                && config('billing.subscription_credit_timing') === 'immediate') {
                $period = Subscription::whereKey($locked->subscription_id)->lockForUpdate()->firstOrFail();
                if (in_array($period->status, Subscription::availableStatuses(), true) && $period->starts_at->isFuture() && ! $period->credits_granted_upfront) {
                    app(AiCreditService::class)->grantEarlyRenewalCredits(User::findOrFail($locked->user_id), $period, $locked->credits);
                    $period->credits_granted_upfront = true;
                    $period->save();
                }
            }
            app(AiCreditService::class)->wallet(User::findOrFail($locked->user_id));
        });
        $payment->touch();
    }
}
