<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AiModelPricingSeeder extends Seeder
{
    public function run(): void
    {
        $model = config('services.openai.model', 'gpt-4.1');
        DB::table('ai_model_pricing')->updateOrInsert(
            ['provider' => 'openai', 'model' => $model, 'effective_from' => '2025-04-14'],
            ['input_price_per_million_micros' => 2_000_000, 'cached_input_price_per_million_micros' => 500_000,
                'output_price_per_million_micros' => 8_000_000,
                'effective_to' => null, 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
