<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function refresh(BillingPayment $payment): void
    {
        if ($payment->provider === 'paypal' && $payment->recurring) {
            app(PaypalSubscriptions::class)->refresh($payment);

            return;
        }
        $gateway = $payment->provider === 'paynow' ? app(PaynowGateway::class) : app(PaypalGateway::class);
        $status = $gateway->status($payment);
        DB::transaction(function () use ($payment, $status) {
            User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $locked = BillingPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($status === 'paid' && $locked->mode !== 'live') {
                $locked->update(['status' => 'test_paid']);

                return;
            }
            if ($status === 'paid' && ! $locked->paid_at && $locked->status !== 'reversed') {
                $latestEnd = Subscription::where('user_id', $locked->user_id)
                    ->where('subscription_plan_id', $locked->subscription_plan_id)->where('status', 'active')
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
                if ($locked->subscription_id) {
                    Subscription::whereKey($locked->subscription_id)->update(['status' => 'cancelled']);
                }
                $locked->status = 'reversed';
            } elseif (! $locked->paid_at && $locked->status !== 'reversed') {
                $locked->status = $status;
            }
            $locked->save();
        });
        $payment->touch();
    }
}
