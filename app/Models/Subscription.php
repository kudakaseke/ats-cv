<?php

namespace App\Models;

use App\Services\Billing\PaystackGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['subscription_plan_id', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'auto_renew' => 'boolean'];
    }

    public static function availableStatuses(): array
    {
        return PaystackGateway::localTestCreditsEnabled() ? ['active', 'test_active'] : ['active'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
