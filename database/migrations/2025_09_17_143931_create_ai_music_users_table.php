<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_music_users', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique()->index();
            $table->string('device_fingerprint')->nullable();
            $table->enum('subscription_status', ['free', 'premium', 'trial'])->default('free');
            $table->integer('usage_count')->default(0);
            $table->integer('monthly_usage')->default(0);
            $table->date('usage_reset_date')->default(now()->format('Y-m-d'));
            $table->timestamp('last_active_at')->nullable();
            $table->json('device_info')->nullable(); // Store device details
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['subscription_status', 'usage_count']);
            $table->index('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_music_users');
    }
};