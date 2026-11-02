<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPayment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['poll_url', 'checkout_url'];

    protected function casts(): array
    {
        return ['poll_url' => 'encrypted', 'checkout_url' => 'encrypted', 'paid_at' => 'datetime', 'verified_at' => 'datetime', 'next_check_at' => 'datetime', 'amount_cents' => 'integer', 'recurring' => 'boolean'];
    }
}
