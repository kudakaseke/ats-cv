<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user')->index();
            $table->string('account_status', 24)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_counter')->nullable();
        });
        DB::table('users')->where('is_admin', true)->update(['role' => 'super_admin']);

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email_hash', 64)->nullable();
            $table->string('action', 120)->index();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ai_model_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->unsignedBigInteger('input_price_per_million_micros');
            $table->unsignedBigInteger('cached_input_price_per_million_micros')->default(0);
            $table->unsignedBigInteger('output_price_per_million_micros');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'model', 'effective_from']);
        });
        app(\Database\Seeders\AiModelPricingSeeder::class)->run();

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('input_cost_micros')->nullable();
            $table->unsignedBigInteger('output_cost_micros')->nullable();
            $table->unsignedBigInteger('total_cost_micros')->nullable()->index();
            $table->foreignId('pricing_id')->nullable()->constrained('ai_model_pricing')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_id');
            $table->dropColumn(['cached_input_tokens', 'input_cost_micros', 'output_cost_micros', 'total_cost_micros']);
        });
        Schema::dropIfExists('ai_model_pricing');
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'account_status', 'suspended_at', 'suspension_reason', 'two_factor_secret',
                'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_counter']);
        });
    }
};
