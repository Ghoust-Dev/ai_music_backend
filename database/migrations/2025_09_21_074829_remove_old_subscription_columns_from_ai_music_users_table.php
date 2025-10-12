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
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Check if columns exist before dropping them
            if (Schema::hasColumn('ai_music_users', 'subscription_status')) {
                $table->dropColumn('subscription_status');
            }
            if (Schema::hasColumn('ai_music_users', 'usage_count')) {
                $table->dropColumn('usage_count');
            }
            if (Schema::hasColumn('ai_music_users', 'monthly_usage')) {
                $table->dropColumn('monthly_usage');
            }
            if (Schema::hasColumn('ai_music_users', 'usage_reset_date')) {
                $table->dropColumn('usage_reset_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Restore old columns
            $table->enum('subscription_status', ['free', 'premium', 'trial'])->default('free');
            $table->integer('usage_count')->default(0);
            $table->integer('monthly_usage')->default(0);
            $table->date('usage_reset_date')->default(now()->format('Y-m-d'));
        });
    }
};
