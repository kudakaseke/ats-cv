<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModelPricing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input_price_per_million_micros' => 'integer', 'cached_input_price_per_million_micros' => 'integer',
            'output_price_per_million_micros' => 'integer',
            'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class, 'pricing_id');
    }
}
