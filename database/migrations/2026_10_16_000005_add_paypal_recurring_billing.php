<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->boolean('recurring')->default(false);
            $table->string('provider_plan_id')->nullable();
            $table->string('recurring_status')->nullable();
        });
        Schema::create('paypal_subscription_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_id');
            $table->string('status');
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['billing_payment_id', 'transaction_id'], 'paypal_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_subscription_receipts');
        Schema::table('billing_payments', fn (Blueprint $table) => $table->dropColumn(['recurring', 'provider_plan_id', 'recurring_status']));
    }
};
