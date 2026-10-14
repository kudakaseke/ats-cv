<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['slug', 'name', 'price_cents', 'currency', 'monthly_analyses', 'resume_limit', 'features', 'is_active'];

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean'];
    }
}
