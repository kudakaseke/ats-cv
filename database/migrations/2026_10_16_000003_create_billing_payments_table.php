<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('mode');
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('billing_interval');
            $table->string('status')->default('pending');
            $table->string('provider_reference')->nullable();
            $table->text('poll_url')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'mode', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
    }
};
