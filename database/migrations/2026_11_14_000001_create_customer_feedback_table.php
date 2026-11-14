<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body');
            $table->boolean('testimonial_consent')->default(false);
            $table->string('status', 24)->default('pending')->index();
            $table->text('admin_reply')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->index(['category','created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('customer_feedback'); }
};
