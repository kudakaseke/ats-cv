<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillingRecords
{
    public function message(int $user, string $key, string $subject, string $message): void
    {
        DB::table('billing_messages')->insertOrIgnore(['user_id' => $user, 'event_key' => $key,
            'subject' => $subject, 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function record(BillingPayment $payment): void
    {
        if ($payment->provider !== 'paynow') {
            return;
        }
        $plan = SubscriptionPlan::findOrFail($payment->subscription_plan_id);
        if ($payment->status === 'paid' && $payment->paid_at) {
            $user = User::findOrFail($payment->user_id);
            $subscription = $payment->subscription_id ? Subscription::find($payment->subscription_id) : null;
            DB::table('billing_receipts')->insertOrIgnore(['billing_payment_id' => $payment->id,
                'number' => 'ATS-'.$payment->id,
                'details' => json_encode(['business' => config('billing.business_name'), 'address' => config('billing.business_address'),
                    'customer' => $user->name, 'email' => $user->email, 'reference' => $payment->reference,
                    'paynow_reference' => $payment->provider_reference, 'plan' => $plan->name,
                    'amount_cents' => $payment->amount_cents, 'currency' => $payment->currency,
                    'paid_at' => $payment->paid_at->toIso8601String(), 'starts_at' => $subscription?->starts_at?->toIso8601String(),
                    'ends_at' => $subscription?->ends_at?->toIso8601String()], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            $this->message($user->id, 'payment:'.$payment->id.':paid', 'Payment successful',
                'Your '.$plan->name.' payment of '.$payment->currency.' '.number_format($payment->amount_cents / 100, 2).' is verified. Reference: '.$payment->reference.'. Your receipt is available on the Subscription page.');
            if ($subscription) {
                $this->message($user->id, 'subscription:'.$subscription->id.':activated', 'Subscription confirmed',
                    $plan->name.' access is confirmed from '.$subscription->starts_at->toDateString().' until '.$subscription->ends_at->toDateString().'. Credits are allocated when the paid period begins. Renew manually; no automatic deductions.');
            }
        } elseif (in_array($payment->status, ['cancelled', 'failed', 'reversed'], true)) {
            $this->message($payment->user_id, 'payment:'.$payment->id.':'.$payment->status, 'Payment update: '.$payment->status,
                'Payment '.$payment->reference.' is '.$payment->status.'. Provider status: '.$payment->provider_status.'. View your Subscription page for details or contact support.');
        }
    }
}
