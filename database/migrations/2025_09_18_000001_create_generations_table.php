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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            
            // Generation identification
            $table->string('generation_id')->unique()->index(); // Unique ID like "gen_abc123"
            
            // User/Device relationship
            $table->string('device_id')->index();
            $table->foreignId('user_id')->constrained('ai_music_users')->onDelete('cascade');
            
            // Generation configuration
            $table->enum('mode', ['text_to_song', 'lyrics_to_song', 'instrumental'])->index();
            $table->json('request_data'); // Original request parameters
            $table->string('estimated_time')->default('2-3 minutes');
            
            // Status tracking
            $table->enum('status', ['processing', 'completed', 'failed', 'mixed'])->default('processing')->index();
            $table->integer('task_count')->default(2); // Always expect 2 tasks from TopMediai
            
            // Timestamps
            $table->timestamps();
            
            // Performance indexes
            $table->index(['user_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['mode', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};