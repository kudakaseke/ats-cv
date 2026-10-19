<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;

class PlanService
{
    public function current(User $user): SubscriptionPlan
    {
        $subscription = $user->subscriptions()
            ->where('status', 'active')
            ->whereNotNull('starts_at')->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('plan')->latest()->first();

        return $subscription?->plan ?? SubscriptionPlan::where('slug', 'free')->firstOrFail();
    }

    public function remainingAnalyses(User $user): ?int
    {
        $limit = $this->current($user)->monthly_analyses;
        if ($limit === null) {
            return null;
        }
        $used = $user->analyses()->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('status', ['pending', 'processing', 'completed'])->count();

        return max(0, $limit - $used);
    }
}
