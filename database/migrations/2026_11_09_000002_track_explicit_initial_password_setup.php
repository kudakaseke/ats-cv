<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy hashes cannot distinguish generated passwords from user-chosen ones.
        // Only a new Google registration may explicitly enable initial setup.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_setup_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_setup_required'));
    }
};
