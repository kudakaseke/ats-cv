<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPayment extends Model
{
    public const CHECKOUT_START_FAILED = 'Checkout could not be opened';

    protected $guarded = ['id'];

    protected $hidden = ['poll_url', 'checkout_url'];

    public function failCheckoutStart(bool $rejected): void
    {
        self::whereKey($this->id)->where('status', 'pending')->whereNull('checkout_url')->update([
            'status' => 'failed',
            'failure_reason' => $rejected ? 'Checkout rejected by provider' : self::CHECKOUT_START_FAILED,
        ]);
    }

    protected function casts(): array
    {
        return ['poll_url' => 'encrypted', 'checkout_url' => 'encrypted', 'paid_at' => 'datetime', 'verified_at' => 'datetime', 'next_check_at' => 'datetime', 'amount_cents' => 'integer', 'recurring' => 'boolean'];
    }
}
