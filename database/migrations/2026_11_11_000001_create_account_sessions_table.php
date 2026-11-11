<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('driver', 40);
            $table->unsignedBigInteger('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_sessions');
    }
};
