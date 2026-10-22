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
            ['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 700, 'monthly_analyses' => 75, 'resume_limit' => 25, 'features' => ['75 monthly CV analyses', 'Advanced AI recommendations', '25 tailored CV versions', 'Full CV Builder', 'Job-to-CV ATS Detector history', 'Priority features']],
        ] as $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }

        SubscriptionPlan::whereIn('slug', ['basic', 'pro'])->update(['is_active' => false]);

        $common = ['Basic ATS Checker checks without a job advert', 'Job matching: qualifications, skills and competencies', 'Experience-gap warnings and improvement suggestions', 'AI CV revamp using your real experience', 'Import PDF or Word, or build from scratch', 'Edit on the CV; customise sections and headings', 'Review before downloading as PDF or Word', 'Manual editing and downloads use no credits'];
        $paid = array_merge(['All '.count(config('cv_templates')).' templates, including By Department'], $common, ['Unlimited saved CVs', 'Saved scan results and history', 'Optional one-time credit top-ups']);

        foreach ([
            ['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'billing_interval' => 'free', 'monthly_analyses' => null, 'resume_limit' => 1, 'features' => array_merge(['15 welcome credits, once per account', 'Enough for one job check and CV revamp', count(config('cv_access.free_templates')).' selected CV templates: '.collect(config('cv_templates'))->whereIn('id', config('cv_access.free_templates'))->pluck('name')->implode(', ')], $common, ['One saved CV', 'Saved scan results and history', 'Optional one-time credit top-ups'])],
            ['slug' => 'monthly', 'name' => 'Monthly', 'price_cents' => 475, 'billing_interval' => 'monthly', 'monthly_analyses' => null, 'resume_limit' => null, 'features' => array_merge(['150 credits each paid month', 'Up to 10 job checks and CV revamps per month'], $paid)],
            ['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 625, 'billing_interval' => 'monthly', 'monthly_analyses' => null, 'resume_limit' => null, 'features' => array_merge(['225 credits each paid month', 'Up to 15 job checks and CV revamps per month'], $paid)],
            ['slug' => 'annual', 'name' => 'Annual', 'price_cents' => 4740, 'billing_interval' => 'annually', 'monthly_analyses' => null, 'resume_limit' => null, 'features' => array_merge(['200 credits released each month for 12 months', 'Up to 13 job checks and CV revamps per month'], $paid)],
        ] as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], array_merge($plan, ['currency' => 'USD', 'is_active' => true]));
        }
    }
}
