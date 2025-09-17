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
        Schema::create('generated_content', function (Blueprint $table) {
            $table->id();
            
            // User relationship
            $table->foreignId('user_id')->constrained('ai_music_users')->onDelete('cascade');
            
            // Content identification
            $table->string('title')->nullable();
            $table->enum('content_type', ['song', 'lyrics', 'instrumental', 'vocal'])->index();
            $table->string('topmediai_task_id')->unique()->index();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            
            // Generation parameters
            $table->text('prompt');
            $table->string('mood')->nullable();
            $table->string('genre')->nullable();
            $table->json('instruments')->nullable(); // Array of instruments
            $table->string('language')->default('english');
            $table->integer('duration')->nullable(); // In seconds
            
            // TopMediai V3 URLs (This is the key part!)
            $table->text('content_url')->nullable(); // Main audio/content URL from TopMediai
            $table->text('thumbnail_url')->nullable(); // Thumbnail image URL from TopMediai
            $table->text('download_url')->nullable(); // Download URL from TopMediai
            $table->text('preview_url')->nullable(); // Preview URL if available
            
            // Metadata from TopMediai response
            $table->json('metadata')->nullable(); // Store TopMediai response metadata
            /*
            metadata example:
            {
                "duration": 120,
                "file_size": "4.2MB",
                "format": "mp3",
                "bitrate": "320kbps",
                "sample_rate": "44100Hz",
                "topmediai_response": {...} // Full API response
            }
            */
            
            // Processing tracking
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Usage tracking
            $table->boolean('is_premium_generation')->default(false);
            $table->timestamp('last_accessed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'content_type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['content_type', 'status']);
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_content');
    }
};