<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', fn (Blueprint $table) => $table->string('merchant_trace', 32)->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropUnique(['merchant_trace']);
            $table->dropColumn('merchant_trace');
        });
    }
};
