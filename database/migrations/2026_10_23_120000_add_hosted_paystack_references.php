<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('hosted_reference')->nullable()->unique();
            $table->string('hosted_page_slug')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropUnique(['hosted_reference']);
            $table->dropColumn(['hosted_reference', 'hosted_page_slug']);
        });
    }
};
