<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('password_set_at')->nullable());
        // Legacy Google hashes may be generated placeholders. Track their first
        // explicit password setup separately from email/password registrations.
        DB::table('users')->whereNull('google_id')->update(['password_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_set_at'));
    }
};
