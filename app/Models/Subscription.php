<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['subscription_plan_id', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'auto_renew' => 'boolean', 'credits_granted_upfront' => 'boolean'];
    }

    public static function availableStatuses(): array
    {
        return self::localTestCreditsEnabled() ? ['active', 'sandbox_active'] : ['active'];
    }

    public static function localTestCreditsEnabled(): bool
    {
        return app()->environment('local') && config('billing.paynow.mode') === 'test'
            && (bool) config('billing.paynow.local_test_credits');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
