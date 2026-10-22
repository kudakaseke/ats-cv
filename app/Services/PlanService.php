<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

class PlanService
{
    public function templateIds(?User $user): array
    {
        $ids = $user && $this->current($user)->slug !== 'free'
            ? array_column(config('cv_templates'), 'id')
            : config('cv_access.free_templates');
        // Existing saved CVs remain editable after a subscription ends.
        if ($user) {
            foreach ($user->resumes()->get(['content']) as $resume) {
                $ids[] = $resume->content['template'] ?? '';
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function current(User $user): SubscriptionPlan
    {
        $subscription = $user->subscriptions()
            ->whereIn('status', Subscription::availableStatuses())
            ->whereNotNull('starts_at')->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('plan')->latest()->first();

        return $subscription?->plan ?? SubscriptionPlan::where('slug', 'free')->firstOrFail();
    }

    public function remainingAnalyses(User $user): ?int
    {
        return intdiv(app(AiCreditService::class)->wallet($user)['credits'], config('ai_credits.costs.analyse'));
    }
}
