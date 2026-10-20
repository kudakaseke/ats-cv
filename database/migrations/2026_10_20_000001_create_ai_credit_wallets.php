<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('subscription_credits')->default(0);
            $table->unsignedInteger('topup_credits')->default(0);
            $table->unsignedInteger('credit_debt')->default(0);
            $table->string('allocation_reference')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('type');
            $table->integer('subscription_delta');
            $table->integer('topup_delta');
            $table->integer('debt_delta')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->unique(['user_id', 'operation_id']);
            $table->string('feature');
            $table->string('request_hash', 64);
            $table->string('status')->default('reserved')->index();
            $table->unsignedInteger('subscription_spent');
            $table->unsignedInteger('topup_spent');
            $table->string('allocation_reference')->nullable();
            $table->mediumText('result')->nullable(); // Encrypted at rest by the service.
            $table->timestamps();
        });
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_operation_id')->unique()->constrained('ai_operations')->cascadeOnDelete();
            $table->string('feature');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->string('status');
            $table->timestamps();
        });
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('kind')->default('subscription');
            $table->unsignedInteger('credits')->default(0);
            $table->string('provider_customer_id')->nullable();
        });
        Schema::create('paystack_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_transaction_id')->unique();
            $table->string('reference')->unique();
            $table->string('status');
            $table->timestamps();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('funding_period')->nullable()->unique();
        });
        app(SubscriptionPlanSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_receipts');
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('funding_period'));
        Schema::table('billing_payments', fn (Blueprint $table) => $table->dropColumn(['kind', 'credits', 'provider_customer_id']));
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_operations');
        Schema::dropIfExists('ai_credit_transactions');
        Schema::dropIfExists('ai_wallets');
    }
};
