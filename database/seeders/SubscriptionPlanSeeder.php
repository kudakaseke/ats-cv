<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_analyses' => 3, 'resume_limit' => 1, 'features' => ['3 monthly CV analyses', 'Basic match results', 'One saved CV', 'Basic ATS-friendly template']],
            ['slug' => 'basic', 'name' => 'Basic', 'price_cents' => 300, 'monthly_analyses' => 20, 'resume_limit' => 5, 'features' => ['20 monthly CV analyses', 'AI CV revamping', 'Five saved CVs', 'PDF and DOCX downloads', 'Detailed skill matching']],
            ['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 700, 'monthly_analyses' => 75, 'resume_limit' => 25, 'features' => ['75 monthly CV analyses', 'Advanced AI recommendations', '25 tailored CV versions', 'Full CV Builder', 'Analysis history', 'Priority features']],
        ] as $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
