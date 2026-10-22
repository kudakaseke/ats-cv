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
            foreach ($user->resumes()->select('content->template as template')->distinct()->get() as $resume) {
                $ids[] = $resume->template ?? '';
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function current(User $user): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()->join('subscriptions', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('subscriptions.user_id', $user->id)
            ->whereIn('subscriptions.status', Subscription::availableStatuses())
            ->whereNotNull('subscriptions.starts_at')->where('subscriptions.starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now()))
            ->select('subscription_plans.*')->orderByDesc('subscriptions.created_at')->orderByDesc('subscriptions.id')->first();

        return $plan ?? SubscriptionPlan::where('slug', 'free')->firstOrFail();
    }

    public function remainingAnalyses(User $user): ?int
    {
        return intdiv(app(AiCreditService::class)->wallet($user)['credits'], config('ai_credits.costs.analyse'));
    }
}
