<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCreditService
{
    private function ledger(int $user, string $reference, string $type, int $subscription, int $topup, int $debt = 0): void
    {
        request()->attributes->remove('display_wallet_'.$user);
        DB::table('ai_credit_transactions')->insert(['user_id' => $user, 'reference' => $reference, 'type' => $type,
            'subscription_delta' => $subscription, 'topup_delta' => $topup, 'debt_delta' => $debt, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditTrail::class)->record($user, 'credits.'.$type, 'CreditTransaction', $reference,
            ['subscription_delta' => $subscription, 'topup_delta' => $topup, 'debt_delta' => $debt], 'credit:'.$reference);

    }

    // All wallet mutations serialize on the user's row, including first use.
    private function syncLocked(User $user): object
    {
        $wallet = DB::table('ai_wallets')->where('user_id', $user->id)->first();
        if (! $wallet) {
            DB::table('ai_wallets')->insert(['user_id' => $user->id, 'subscription_credits' => config('ai_credits.welcome'),
                'topup_credits' => 0, 'allocation_reference' => 'welcome:'.$user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger($user->id, 'welcome:'.$user->id, 'welcome', config('ai_credits.welcome'), 0);
            $wallet = DB::table('ai_wallets')->where('user_id', $user->id)->first();
        }
        // An early renewal pools remaining and newly purchased credits until
        // the end of the extended paid term. A period start never refills it.
        $poolPrefix = 'subscription-pool:';
        $allocation = $wallet->allocation_reference ?? '';
        if (str_starts_with($allocation, 'expired:'.$poolPrefix) && Subscription::localTestCreditsEnabled()) {
            $pool = substr($allocation, strlen('expired:'));
            $end = $user->subscriptions()->where('status', 'sandbox_active')->where('ends_at', '>', now())->max('ends_at');
            $expiry = DB::table('ai_credit_transactions')->where('user_id', $user->id)->where('type', 'subscription_expired')
                ->where('reference', 'like', 'expire:'.$pool.':%')->latest('id')->first();
            if (str_ends_with($pool, ':test') && $end && $expiry) {
                $restored = array_sum(json_decode($wallet->subscription_sources ?? '{}', true));
                $this->ledger($user->id, 'restore:'.$pool.':'.Str::uuid(), 'subscription_restored', $restored, 0);
                DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $restored,
                    'allocation_reference' => $pool, 'expires_at' => $end, 'updated_at' => now()]);
                $wallet = DB::table('ai_wallets')->where('id', $wallet->id)->first();
                $allocation = $pool;
            }
        }
        if (str_starts_with($allocation, $poolPrefix)) {
            $allowed = ! str_ends_with($allocation, ':test') || Subscription::localTestCreditsEnabled();
            if ($allowed && $wallet->expires_at && Carbon::parse($wallet->expires_at)->gt(now())) {
                return $wallet;
            }
            if ($wallet->subscription_credits > 0) {
                $this->ledger($user->id, 'expire:'.$allocation.':'.Str::uuid(), 'subscription_expired', -$wallet->subscription_credits, 0);
            }
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => 0,
                'allocation_reference' => 'expired:'.$allocation, 'expires_at' => null, 'updated_at' => now()]);
            $wallet = DB::table('ai_wallets')->where('id', $wallet->id)->first();
        }
        $subscription = $user->subscriptions()->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->whereIn('subscriptions.status', Subscription::availableStatuses())->where('subscriptions.starts_at', '<=', now())
            ->where('subscriptions.ends_at', '>', now())->select('subscriptions.*', 'subscription_plans.slug as plan_slug')
            ->orderByDesc('subscriptions.starts_at')->orderByDesc('subscriptions.id')->first();
        if ($subscription && ! $subscription->credits_granted_upfront && ($credits = config('ai_credits.plans.'.$subscription->plan_slug, 0)) > 0 && $subscription->plan_slug !== 'free') {
            // Honor the purchased quantity even if the plan catalogue changes
            // between checkout and provider confirmation. Legacy/manual periods
            // without a payment retain the configured allowance.
            $credits = DB::table('billing_payments')->where('subscription_id', $subscription->id)
                ->where('user_id', $user->id)->where('provider', 'paynow')->whereIn('status', ['paid', 'test_paid'])
                ->value('credits') ?? $credits;
            $anchor = Carbon::parse($subscription->starts_at);
            $month = 0;
            while ($subscription->plan_slug !== 'annual' && $anchor->copy()->addMonthsNoOverflow($month + 1)->lte(now()) && $month < 120) {
                $month++;
            }
            $start = $anchor->copy()->addMonthsNoOverflow($month);
            $end = $subscription->plan_slug === 'annual' ? Carbon::parse($subscription->ends_at)
                : $anchor->copy()->addMonthsNoOverflow($month + 1)->min(Carbon::parse($subscription->ends_at));
            $reference = 'subscription:'.$subscription->id.':'.($subscription->plan_slug === 'annual' ? 'annual:' : '').$start->format('YmdHis');
            if ($wallet->allocation_reference !== $reference && ! DB::table('ai_credit_transactions')->where('reference', $reference)->exists()) {
                if ($subscription->plan_slug === 'annual') {
                    $previous = DB::table('ai_operations')->where('user_id', $user->id)
                        ->where('allocation_reference', 'like', 'subscription:'.$subscription->id.':%');
                    $credits = max(0, $credits - (clone $previous)->whereIn('status', ['reserved', 'completed'])->sum('subscription_spent'));
                    // Keep in-flight refunds attached to the replacement yearly allowance.
                    (clone $previous)->where('status', 'reserved')->update(['allocation_reference' => $reference]);
                }
                $this->ledger($user->id, $reference, 'subscription_allocation', $credits - $wallet->subscription_credits, 0);
                DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $credits,
                    'allocation_reference' => $reference, 'expires_at' => $end, 'updated_at' => now()]);
            } elseif (in_array($subscription->status, ['test_active', 'sandbox_active'], true) && $wallet->allocation_reference === 'expired:'.$reference) {
                // Recover only the unspent balance if local test configuration was temporarily removed.
                // Prior operations remain authoritative, so restoring configuration cannot refill spent credits.
                $spent = DB::table('ai_operations')->where('user_id', $user->id)
                    ->when($subscription->plan_slug === 'annual', fn ($q) => $q->where('allocation_reference', 'like', 'subscription:'.$subscription->id.':%'), fn ($q) => $q->where('allocation_reference', $reference))
                    ->whereIn('status', ['reserved', 'completed'])->sum('subscription_spent');
                $remaining = max(0, $credits - $spent);
                $this->ledger($user->id, 'restore:'.$reference.':'.Str::uuid(), 'subscription_restored', $remaining - $wallet->subscription_credits, 0);
                DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $remaining,
                    'allocation_reference' => $reference, 'expires_at' => $end, 'updated_at' => now()]);
            }
        } elseif ($wallet->expires_at && Carbon::parse($wallet->expires_at)->lte(now()) ||
            (! $subscription && str_starts_with($wallet->allocation_reference ?? '', 'subscription:'))) {
            if ($wallet->subscription_credits > 0) {
                // An allowance can have been restored after an earlier expiry.
                // Each expiry is a distinct transition; the user lock and atomic
                // wallet update ensure subsequent reads do not expire it again.
                $this->ledger($user->id, 'expire:'.$wallet->allocation_reference.':'.Str::uuid(), 'subscription_expired', -$wallet->subscription_credits, 0);

            }
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => 0,
                'allocation_reference' => 'expired:'.$wallet->allocation_reference, 'expires_at' => null, 'updated_at' => now()]);
        }

        return DB::table('ai_wallets')->where('id', $wallet->id)->first();
    }

    // Cache presentation reads within this request only. All spending still locks
    // and reads the authoritative wallet; ledger mutations invalidate this snapshot.
    public function displayWallet(User $user): array
    {
        $key = 'display_wallet_'.$user->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, $this->wallet($user));
        }

        return request()->attributes->get($key);
    }

    public function wallet(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);

            return ['credits' => max(0, $wallet->subscription_credits + $wallet->topup_credits - $wallet->credit_debt), 'credit_debt' => $wallet->credit_debt,
                'purchased_credits' => max(0, ((str_starts_with($wallet->allocation_reference ?? '', 'subscription:') || str_starts_with($wallet->allocation_reference ?? '', 'subscription-pool:')) ? $wallet->subscription_credits : 0) + $wallet->topup_credits - $wallet->credit_debt),
                'subscription_credits' => $wallet->subscription_credits, 'topup_credits' => $wallet->topup_credits,
                'renews_at' => $wallet->expires_at];
        }, 3);
    }

    public function grantEarlyRenewalCredits(User $user, Subscription $period, int $credits): void
    {
        DB::transaction(function () use ($user, $period, $credits) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            $reference = 'subscription-upfront:'.$period->id;
            if (DB::table('ai_credit_transactions')->where('reference', $reference)->exists()) {
                return;
            }
            $pool = 'subscription-pool:'.$user->id.($period->status === 'sandbox_active' ? ':test' : ':live');
            $end = $wallet->expires_at ? Carbon::parse($wallet->expires_at)->max($period->ends_at) : $period->ends_at;
            $sources = json_decode($wallet->subscription_sources ?? '{}', true);
            if (! str_starts_with($wallet->allocation_reference ?? '', 'subscription-pool:')) {
                $sources = [];
                if (preg_match('/^subscription:(\d+):/', $wallet->allocation_reference ?? '', $match)) {
                    $sources[$match[1]] = $wallet->subscription_credits;
                    DB::table('ai_operations')->where('user_id', $user->id)->where('status', 'reserved')
                        ->where('allocation_reference', $wallet->allocation_reference)->orderBy('id')->get()->each(function ($operation) use ($match) {
                            DB::table('ai_operations')->where('id', $operation->id)->update([
                                'subscription_sources' => json_encode([$match[1] => $operation->subscription_spent], JSON_THROW_ON_ERROR)]);
                        });
                }
            }
            $sources[$period->id] = ($sources[$period->id] ?? 0) + $credits;
            $balance = array_sum($sources);
            // A paid pool replaces welcome/expired allowances, just as a first
            // subscription does. Every spendable credit must have a refund source.
            $this->ledger($user->id, $reference, 'subscription_upfront', $balance - $wallet->subscription_credits, 0);
            DB::table('ai_operations')->where('user_id', $user->id)->where('status', 'reserved')
                ->where('allocation_reference', $wallet->allocation_reference)->update(['allocation_reference' => $pool]);
            DB::table('ai_wallets')->where('id', $wallet->id)->update([
                'subscription_credits' => $balance,
                'allocation_reference' => $pool, 'subscription_sources' => json_encode($sources, JSON_THROW_ON_ERROR),
                'expires_at' => $end, 'updated_at' => now()]);
        }, 3);
    }

    public function reversePooledSubscriptionCredits(User $user, int $periodId, string $reference): void
    {
        DB::transaction(function () use ($user, $periodId, $reference) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = DB::table('ai_wallets')->where('user_id', $user->id)->first();
            if (! $wallet || (! str_starts_with($wallet->allocation_reference ?? '', 'subscription-pool:')
                    && ! str_starts_with($wallet->allocation_reference ?? '', 'expired:subscription-pool:'))
                || DB::table('ai_credit_transactions')->where('reference', $reference)->exists()) {
                return;
            }
            $end = $user->subscriptions()->whereIn('status', Subscription::availableStatuses())
                ->where('ends_at', '>', now())->max('ends_at');
            $sources = json_decode($wallet->subscription_sources ?? '{}', true);
            unset($sources[$periodId]);
            $remaining = $end && str_starts_with($wallet->allocation_reference, 'subscription-pool:') ? array_sum($sources) : 0;
            $this->ledger($user->id, $reference, 'subscription_reversal', $remaining - $wallet->subscription_credits, 0);
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $remaining,
                'expires_at' => $end, 'subscription_sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }, 3);
    }

    public function topup(User $user, int $credits, string $reference): void
    {
        if ($credits < 1) {
            throw new \InvalidArgumentException('Invalid credit grant.');
        }
        DB::transaction(function () use ($user, $credits, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            if (DB::table('ai_credit_transactions')->where('reference', $reference)->exists()) {
                return;
            }
            DB::table('ai_wallets')->where('id', $wallet->id)->increment('topup_credits', $credits);
            $this->ledger($user->id, $reference, 'topup', 0, $credits);
        }, 3);
    }

    public function reverseTopup(User $user, int $credits, string $reference): void
    {
        DB::transaction(function () use ($user, $credits, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            if (DB::table('ai_credit_transactions')->where('reference', $reference)->exists()) {
                return;
            }
            $deduct = min($credits, $wallet->topup_credits);
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['topup_credits' => $wallet->topup_credits - $deduct,
                'credit_debt' => $wallet->credit_debt + $credits - $deduct, 'updated_at' => now()]);
            $this->ledger($user->id, $reference, 'topup_reversal', 0, -$deduct, $credits - $deduct);
        }, 3);
    }

    public function reserve(User $user, string $feature, string $operationId, array $input): object
    {
        $cost = config('ai_credits.costs.'.$feature);
        if (! is_int($cost) || $cost < 1) {
            throw new \InvalidArgumentException('Invalid feature.');
        }

        return DB::transaction(function () use ($user, $feature, $operationId, $input, $cost) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $old = DB::table('ai_operations')->where('user_id', $user->id)->where('operation_id', $operationId)->first();
            if ($old) {
                abort_unless($old->feature === $feature && hash_equals($old->request_hash, $hash), 409, 'This operation belongs to different input.');
                abort_if($old->status === 'reserved', 409, 'This request is still processing. Please wait before retrying.');
                if ($old->status === 'failed') {
                    throw new HttpResponseException(response()->json([
                        'message' => 'This request failed and its credits were refunded. Start a new request to retry.',
                        'operation_failed' => true, 'operation_id' => $operationId,
                    ], 503));
                }

                return (object) ['cached' => true, 'result' => json_decode(Crypt::decryptString($old->result), true, 64, JSON_THROW_ON_ERROR)];
            }
            if ($wallet->subscription_credits + $wallet->topup_credits - $wallet->credit_debt < $cost) {
                throw ValidationException::withMessages(['credits' => "You need {$cost} credits for this action. Please top up or choose a plan."]);
            }
            $subscription = min($cost, $wallet->subscription_credits);
            $topup = $cost - $subscription;
            $sources = json_decode($wallet->subscription_sources ?? '{}', true);
            $spentSources = [];
            if (str_starts_with($wallet->allocation_reference ?? '', 'subscription-pool:')) {
                $unassigned = $subscription;
                ksort($sources, SORT_NUMERIC);
                foreach ($sources as $period => $remaining) {
                    $spent = min($remaining, $unassigned);
                    $sources[$period] -= $spent;
                    $spentSources[$period] = $spent;
                    $unassigned -= $spent;
                }
                if ($unassigned !== 0) {
                    throw new \RuntimeException('Subscription credit sources do not match the wallet.');
                }
            }
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $wallet->subscription_credits - $subscription,
                'topup_credits' => $wallet->topup_credits - $topup,
                'subscription_sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $id = DB::table('ai_operations')->insertGetId(['user_id' => $user->id, 'operation_id' => $operationId, 'feature' => $feature,
                'request_hash' => $hash, 'status' => 'reserved', 'subscription_spent' => $subscription, 'topup_spent' => $topup,
                'allocation_reference' => $wallet->allocation_reference, 'subscription_sources' => json_encode($spentSources, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            $this->ledger($user->id, 'usage:'.$id, 'usage_reserved', -$subscription, -$topup);

            return (object) ['id' => $id, 'cached' => false];
        }, 3);
    }

    public function finish(User $user, int $operationId, ?array $result, array $usage = []): void
    {
        DB::transaction(function () use ($user, $operationId, $result, $usage) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            $operation = DB::table('ai_operations')->where('id', $operationId)->where('user_id', $user->id)->firstOrFail();
            if ($operation->status !== 'reserved') {
                return;
            }
            if ($result === null) {
                // Restore only the allocation originally charged. Expired monthly
                // credits never turn into permanent top-up credits on a refund.
                $subscription = $wallet->allocation_reference === $operation->allocation_reference &&
                    (! $wallet->expires_at || Carbon::parse($wallet->expires_at)->gt(now())) ? $operation->subscription_spent : 0;
                $sources = json_decode($wallet->subscription_sources ?? '{}', true);
                if ($subscription > 0 && str_starts_with($wallet->allocation_reference ?? '', 'subscription-pool:')) {
                    $refundable = json_decode($operation->subscription_sources ?? '{}', true);
                    $valid = $user->subscriptions()->whereIn('id', array_keys($refundable))
                        ->whereIn('status', array_merge(Subscription::availableStatuses(), ['expired']))->pluck('id')->all();
                    $subscription = 0;
                    foreach ($valid as $period) {
                        $amount = $refundable[$period] ?? 0;
                        $sources[$period] = ($sources[$period] ?? 0) + $amount;
                        $subscription += $amount;
                    }
                }
                DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $wallet->subscription_credits + $subscription,
                    'topup_credits' => $wallet->topup_credits + $operation->topup_spent,
                    'subscription_sources' => json_encode($sources, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                $this->ledger($user->id, 'refund:'.$operation->id, 'failed_usage_refund', $subscription, $operation->topup_spent);
            }
            DB::table('ai_operations')->where('id', $operation->id)->update(['status' => $result === null ? 'failed' : 'completed',
                'result' => $result === null ? null : Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
            $provider = $usage['provider'] ?? config('services.cv_ai.provider');
            $model = $usage['model'] ?? config('services.'.$provider.'.model');
            $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
            $cachedInputTokens = min($inputTokens, max(0, (int) ($usage['cached_input_tokens'] ?? 0)));
            $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
            $usageKnown = array_key_exists('input_tokens', $usage) && array_key_exists('output_tokens', $usage);
            $pricing = $usageKnown ? DB::table('ai_model_pricing')->where('provider', $provider)->where('model', $model)
                ->where('effective_from', '<=', now()->toDateString())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
                ->orderByDesc('effective_from')->first() : null;
            $uncachedInputTokens = $inputTokens - $cachedInputTokens;
            $inputCost = $pricing ? intdiv($uncachedInputTokens * (int) $pricing->input_price_per_million_micros
                + $cachedInputTokens * (int) $pricing->cached_input_price_per_million_micros + 500_000, 1_000_000) : null;
            $outputCost = $pricing ? intdiv($outputTokens * (int) $pricing->output_price_per_million_micros + 500_000, 1_000_000) : null;
            DB::table('ai_usage_logs')->insert(['user_id' => $user->id, 'ai_operation_id' => $operation->id, 'feature' => $operation->feature,
                'provider' => $provider, 'model' => $model,
                'input_tokens' => $inputTokens, 'cached_input_tokens' => $cachedInputTokens, 'output_tokens' => $outputTokens,
                'pricing_id' => $pricing?->id, 'input_cost_micros' => $inputCost, 'output_cost_micros' => $outputCost,
                'total_cost_micros' => $pricing ? $inputCost + $outputCost : null,
                'credits_charged' => $result === null ? 0 : $operation->subscription_spent + $operation->topup_spent,
                'status' => $result === null ? 'failed' : 'completed', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditTrail::class)->record($user->id, $result === null ? 'ai.failed' : 'ai.completed', 'AiOperation', $operation->id,
                ['feature' => $operation->feature, 'credits_charged' => $result === null ? 0 : $operation->subscription_spent + $operation->topup_spent], 'ai:'.$operation->id.':finished');

        }, 3);
    }
}
