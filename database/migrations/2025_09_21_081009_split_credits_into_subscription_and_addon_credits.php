<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Add new separate credit columns
            $table->integer('subscription_credits')->default(0)->after('credits');
            $table->integer('addon_credits')->default(0)->after('subscription_credits');
        });
        
        // Migrate existing credits to subscription_credits
        // (assuming existing credits are subscription-based)
        DB::statement('UPDATE ai_music_users SET subscription_credits = credits WHERE credits > 0');
        
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Remove old credits column
            if (Schema::hasColumn('ai_music_users', 'credits')) {
                $table->dropIndex(['credits']);
                $table->dropColumn('credits');
            }
            
            // Add new indexes
            $table->index('subscription_credits');
            $table->index('addon_credits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Restore single credits column
            $table->integer('credits')->default(0)->after('device_fingerprint');
        });
        
        // Migrate data back (sum both credit types)
        DB::statement('UPDATE ai_music_users SET credits = (subscription_credits + addon_credits)');
        
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Remove split columns
            $table->dropIndex(['subscription_credits']);
            $table->dropIndex(['addon_credits']);
            $table->dropColumn(['subscription_credits', 'addon_credits']);
            
            // Restore credits index
            $table->index('credits');
        });
    }
};
