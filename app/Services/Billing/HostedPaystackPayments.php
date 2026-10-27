<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class HostedPaystackPayments
{
    public function reconcile(string $reference, ?User $owner = null): BillingPayment
    {
        return $this->applyVerified(app(PaystackGateway::class)->verified($reference), $owner);
    }

    public function applyVerified(array $data, ?User $owner = null): BillingPayment
    {
        $gateway = app(PaystackGateway::class);
        $email = strtolower((string) data_get($data, 'customer.email'));
        $user = $owner ?? User::whereRaw('LOWER(email) = ?', [$email])->firstOrFail();
        abort_unless($user->hasVerifiedEmail() && strtolower($user->email) === $email, 403);
        if (($data['domain'] ?? '') !== $gateway->mode()) {
            throw new \RuntimeException('Payment mode mismatch.');
        }
        return DB::transaction(function () use ($data, $user, $gateway) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $payment = BillingPayment::where('provider', 'paystack')->where('mode', $gateway->mode())
                ->where(fn ($q) => $q->where('hosted_reference', $data['reference'])->orWhere('reference', $data['reference']))->first();
            if ($payment) {
                abort_unless($payment->user_id === $user->id, 403);
                $gateway->apply($payment, $data);
                return $payment->fresh();
            }
            if (($data['status'] ?? '') !== 'success') {
                throw new \DomainException('Payment has not succeeded.');
            }
            // The referrer selects a candidate only. Fetch the merchant's page and
            // independently verify its plan, currency, amount and account identity.
            $referrers = explode(',', (string) data_get($data, 'metadata.referrer', ''));
            $slug = null;
            foreach (config('paystack.test_payment_pages') as $candidate => $page) {
                if (in_array($page['url'], array_map(fn ($url) => strtok(trim($url), '?'), $referrers), true)) {
                    $slug = $candidate;
                    break;
                }
            }
            if (! $slug || $gateway->mode() !== 'test') {
                throw new \RuntimeException('Unknown hosted payment page.');
            }
            $page = Http::withToken(config('paystack.secret_key'))->acceptJson()->timeout(20)
                ->get('https://api.paystack.co/page/'.config('paystack.test_payment_pages.'.$slug.'.page_id'))->throw()->json('data');
            $remote = $page['plan'] ?? [];
            $plan = SubscriptionPlan::where('slug', $slug)->where('is_active', true)->firstOrFail();
            if (($page['slug'] ?? '') !== basename(config('paystack.test_payment_pages.'.$slug.'.url'))
                || ($remote['domain'] ?? '') !== $gateway->mode() || empty($remote['plan_code'])
                || ($remote['interval'] ?? '') !== $plan->billing_interval
                || ($remote['currency'] ?? '') !== ($data['currency'] ?? '')
                || ($data['currency'] ?? '') !== 'ZAR' || (int) ($remote['amount'] ?? 0) !== (int) ($data['amount'] ?? -1)) {
                throw new \RuntimeException('Hosted payment does not match the merchant plan.');
            }
            $paidAt = Carbon::parse($data['paid_at']);
            $addsToAnnual = $slug !== 'annual' && $user->subscriptions()
                ->whereIn('status', \App\Models\Subscription::availableStatuses())
                ->where('subscription_plan_id', SubscriptionPlan::where('slug', 'annual')->value('id'))
                ->where('starts_at', '<=', $paidAt)->where('ends_at', '>', $paidAt)->exists();
            $payment = BillingPayment::create(['reference' => (string) Str::uuid(), 'hosted_reference' => $data['reference'],
                'hosted_page_slug' => $page['slug'], 'user_id' => $user->id, 'subscription_plan_id' => $plan->id,
                'provider' => 'paystack', 'mode' => $gateway->mode(), 'amount_cents' => (int) $data['amount'],
                'currency' => $data['currency'], 'billing_interval' => $plan->billing_interval, 'kind' => $addsToAnnual ? 'topup' : 'subscription',
                'recurring' => true, 'provider_plan_id' => $remote['plan_code'], 'credits' => config('ai_credits.plans.'.$slug),
                'created_at' => Carbon::parse($data['created_at'] ?? $data['paid_at'])]);
            $gateway->apply($payment, $data);
            return $payment->fresh();
        });
    }
}
