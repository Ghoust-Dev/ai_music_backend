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
            // Add new credit system columns
            $table->integer('credits')->default(0)->after('device_fingerprint');
            $table->timestamp('subscription_expires_at')->nullable()->after('credits');
            
            // Add indexes for performance
            $table->index('credits');
            $table->index('subscription_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_music_users', function (Blueprint $table) {
            // Remove new columns
            $table->dropIndex(['credits']);
            $table->dropIndex(['subscription_expires_at']);
            $table->dropColumn(['credits', 'subscription_expires_at']);
        });
    }
};
