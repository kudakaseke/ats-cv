<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['slug', 'name', 'price_cents', 'currency', 'monthly_analyses', 'resume_limit', 'features', 'is_active', 'billing_interval', 'paystack_plan_code', 'paystack_mode', 'paystack_amount_cents'];

    public function monthlyDisplayCents(): int
    {
        return $this->billing_interval === 'annually' ? (int) round($this->price_cents / 12) : $this->price_cents;
    }

    public function displayPrice(): string
    {
        $cents = $this->monthlyDisplayCents();

        return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean'];
    }
}
