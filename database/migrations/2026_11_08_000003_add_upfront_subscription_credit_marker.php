<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->boolean('credits_granted_upfront')->default(false));
        Schema::table('ai_wallets', fn (Blueprint $table) => $table->json('subscription_sources')->nullable());
        Schema::table('ai_operations', fn (Blueprint $table) => $table->json('subscription_sources')->nullable());
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('credits_granted_upfront'));
        Schema::table('ai_wallets', fn (Blueprint $table) => $table->dropColumn('subscription_sources'));
        Schema::table('ai_operations', fn (Blueprint $table) => $table->dropColumn('subscription_sources'));
    }
};
