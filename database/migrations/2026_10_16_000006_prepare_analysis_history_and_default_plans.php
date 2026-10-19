<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_analyses', function (Blueprint $table) {
            $table->foreignId('resume_id')->nullable()->change();
        });
        // Fresh installations must have a valid default plan even without db:seed.
        foreach ([
            ['free', 'Free', 0, 'free', 3, 1, ['Basic CV builder', 'Limited ATS scans', 'Basic templates']],
            ['monthly', 'Monthly', 500, 'monthly', null, null, ['Unlimited CV edits', 'ATS matching', 'PDF downloads']],
            ['annual', 'Annual', 4740, 'annually', null, null, ['All Monthly features', 'Priority support', 'Advanced ATS insights']],
        ] as [$slug, $name, $amount, $interval, $analyses, $resumes, $features]) {
            DB::table('subscription_plans')->insertOrIgnore(['slug' => $slug, 'name' => $name, 'price_cents' => $amount,
                'currency' => 'USD', 'billing_interval' => $interval, 'monthly_analyses' => $analyses, 'resume_limit' => $resumes,
                'features' => json_encode($features), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Preserve default plans and analyses when reverting this additive migration.
    }
};
