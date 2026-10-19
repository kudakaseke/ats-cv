<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Keep legacy rows for existing subscriptions, but remove them from sale.
        foreach ([
            ['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_analyses' => 3, 'resume_limit' => 1, 'features' => ['3 monthly CV analyses', 'Basic match results', 'One saved CV', 'Basic ATS-friendly template']],
            ['slug' => 'basic', 'name' => 'Basic', 'price_cents' => 300, 'monthly_analyses' => 20, 'resume_limit' => 5, 'features' => ['20 monthly CV analyses', 'AI CV revamping', 'Five saved CVs', 'PDF and DOCX downloads', 'Detailed skill matching']],
            ['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 700, 'monthly_analyses' => 75, 'resume_limit' => 25, 'features' => ['75 monthly CV analyses', 'Advanced AI recommendations', '25 tailored CV versions', 'Full CV Builder', 'Analysis history', 'Priority features']],
        ] as $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }

        SubscriptionPlan::whereIn('slug', ['basic', 'pro'])->update(['is_active' => false]);

        foreach ([
            ['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'billing_interval' => 'free', 'monthly_analyses' => 3, 'resume_limit' => 1, 'features' => ['Basic CV builder', 'Limited ATS scans', 'Basic templates']],
            ['slug' => 'monthly', 'name' => 'Monthly', 'price_cents' => 500, 'billing_interval' => 'monthly', 'monthly_analyses' => null, 'resume_limit' => null, 'features' => ['Unlimited CV edits', 'ATS matching', 'PDF downloads']],
            ['slug' => 'annual', 'name' => 'Annual', 'price_cents' => 4740, 'billing_interval' => 'annually', 'monthly_analyses' => null, 'resume_limit' => null, 'features' => ['All Monthly features', 'Priority support', 'Advanced ATS insights']],
        ] as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], array_merge($plan, ['currency' => 'USD', 'is_active' => true]));
        }
    }
}
