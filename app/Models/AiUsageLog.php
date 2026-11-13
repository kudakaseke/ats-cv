<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'cached_input_tokens' => 'integer', 'output_tokens' => 'integer', 'credits_charged' => 'integer',
            'input_cost_micros' => 'integer', 'output_cost_micros' => 'integer', 'total_cost_micros' => 'integer'];
    }
}
