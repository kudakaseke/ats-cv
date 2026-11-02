<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('provider_status')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->index(['user_id', 'provider', 'status']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(false);
        });
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('fingerprint', 64)->unique();
            $table->json('payload');
            $table->boolean('hash_valid');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
        Schema::create('billing_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->json('details');
            $table->timestamps();
        });
        Schema::create('billing_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key')->unique();
            $table->string('subject');
            $table->text('message');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('billing_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->text('notes');
            $table->timestamps();
        });
        DB::table('subscription_plans')->where('slug', 'pro')->where('price_cents', 625)->update(['price_cents' => 650]);
    }

    public function down(): void
    {
        foreach (['billing_reviews', 'billing_messages', 'billing_receipts', 'payment_notifications'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['cancelled_at', 'auto_renew']));
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'provider', 'status']);
            $table->dropColumn(['provider_status', 'failure_reason', 'verified_at', 'next_check_at', 'verification_attempts']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
