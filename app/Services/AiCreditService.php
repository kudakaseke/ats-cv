<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
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
        $subscription = $user->subscriptions()->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.subscription_plan_id')
            ->whereIn('subscriptions.status', Subscription::availableStatuses())->where('subscriptions.starts_at', '<=', now())
            ->where('subscriptions.ends_at', '>', now())->select('subscriptions.*', 'subscription_plans.slug as plan_slug')
            ->orderByDesc('subscriptions.starts_at')->orderByDesc('subscriptions.id')->first();
        if ($subscription && ($credits = config('ai_credits.plans.'.$subscription->plan_slug, 0)) > 0 && $subscription->plan_slug !== 'free') {
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
            } elseif ($subscription->status === 'test_active' && $wallet->allocation_reference === 'expired:'.$reference) {
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
                $this->ledger($user->id, 'expire:'.$wallet->allocation_reference, 'subscription_expired', -$wallet->subscription_credits, 0);

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
                'purchased_credits' => max(0, (str_starts_with($wallet->allocation_reference ?? '', 'subscription:') ? $wallet->subscription_credits : 0) + $wallet->topup_credits - $wallet->credit_debt),
                'subscription_credits' => $wallet->subscription_credits, 'topup_credits' => $wallet->topup_credits,
                'renews_at' => $wallet->expires_at];
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

    public function reserve(User $user, string $feature, string $operationId, array $input, bool $allowPending = false): object
    {
        $cost = config('ai_credits.costs.'.$feature);
        if (! is_int($cost) || $cost < 1) {
            throw new \InvalidArgumentException('Invalid feature.');
        }

        return DB::transaction(function () use ($user, $feature, $operationId, $input, $cost, $allowPending) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->syncLocked($locked);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $old = DB::table('ai_operations')->where('user_id', $user->id)->where('operation_id', $operationId)->first();
            if ($old) {
                abort_unless($old->feature === $feature && hash_equals($old->request_hash, $hash), 409, 'This operation belongs to different input.');
                if ($old->status === 'reserved' && $allowPending) {
                    return (object) ['id' => $old->id, 'cached' => false, 'pending' => true];
                }
                abort_if($old->status === 'reserved', 409, 'This request is still processing. Please wait before retrying.');
                abort_if($old->status === 'failed', 503, 'This request failed and its credits were refunded. Start a new request to retry.');

                return (object) ['cached' => true, 'result' => json_decode(Crypt::decryptString($old->result), true, 64, JSON_THROW_ON_ERROR)];
            }
            // A second browser tab has a different operation UUID but must not
            // launch another billable request for the same in-flight documents.
            $pending = DB::table('ai_operations')->where('user_id', $user->id)->where('feature', $feature)
                ->where('request_hash', $hash)->where('status', 'reserved')->first();
            if ($pending) {
                if ($allowPending) {
                    return (object) ['id' => $pending->id, 'cached' => false, 'pending' => true];
                }
                abort(409, 'This request is already processing. Please wait before retrying.');
            }
            if ($wallet->subscription_credits + $wallet->topup_credits - $wallet->credit_debt < $cost) {
                throw ValidationException::withMessages(['credits' => "You need {$cost} credits for this action. Please top up or choose a plan."]);
            }
            $subscription = min($cost, $wallet->subscription_credits);
            $topup = $cost - $subscription;
            DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $wallet->subscription_credits - $subscription,
                'topup_credits' => $wallet->topup_credits - $topup, 'updated_at' => now()]);
            $id = DB::table('ai_operations')->insertGetId(['user_id' => $user->id, 'operation_id' => $operationId, 'feature' => $feature,
                'request_hash' => $hash, 'status' => 'reserved', 'subscription_spent' => $subscription, 'topup_spent' => $topup,
                'allocation_reference' => $wallet->allocation_reference, 'created_at' => now(), 'updated_at' => now()]);
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
                DB::table('ai_wallets')->where('id', $wallet->id)->update(['subscription_credits' => $wallet->subscription_credits + $subscription,
                    'topup_credits' => $wallet->topup_credits + $operation->topup_spent, 'updated_at' => now()]);
                $this->ledger($user->id, 'refund:'.$operation->id, 'failed_usage_refund', $subscription, $operation->topup_spent);
            }
            DB::table('ai_operations')->where('id', $operation->id)->update(['status' => $result === null ? 'failed' : 'completed',
                'result' => $result === null ? null : Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
            DB::table('ai_usage_logs')->insert(['user_id' => $user->id, 'ai_operation_id' => $operation->id, 'feature' => $operation->feature,
                'provider' => $usage['provider'] ?? config('services.cv_ai.provider'), 'model' => $usage['model'] ?? config('services.'.config('services.cv_ai.provider').'.model'),
                'input_tokens' => max(0, (int) ($usage['input_tokens'] ?? 0)), 'output_tokens' => max(0, (int) ($usage['output_tokens'] ?? 0)),
                'credits_charged' => $result === null ? 0 : $operation->subscription_spent + $operation->topup_spent,
                'status' => $result === null ? 'failed' : 'completed', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditTrail::class)->record($user->id, $result === null ? 'ai.failed' : 'ai.completed', 'AiOperation', $operation->id,
                ['feature' => $operation->feature, 'credits_charged' => $result === null ? 0 : $operation->subscription_spent + $operation->topup_spent], 'ai:'.$operation->id.':finished');

        }, 3);
    }
}
