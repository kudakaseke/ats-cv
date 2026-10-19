<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('billing_interval')->default('monthly');
            $table->string('paystack_plan_code')->nullable()->unique();
            $table->string('paystack_mode')->nullable();
            $table->unsignedInteger('paystack_amount_cents')->nullable();
            $table->unsignedInteger('monthly_analyses')->nullable()->change();
            $table->unsignedInteger('resume_limit')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropUnique(['paystack_plan_code']);
            $table->dropColumn(['billing_interval', 'paystack_plan_code', 'paystack_mode', 'paystack_amount_cents']);
        });
    }
};
