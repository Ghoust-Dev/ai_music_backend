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
        Schema::create('generation_requests', function (Blueprint $table) {
            $table->id();
            
            // User relationship
            $table->foreignId('user_id')->constrained('ai_music_users')->onDelete('cascade');
            
            // TopMediai API tracking
            $table->string('topmediai_task_id')->nullable()->index();
            $table->enum('endpoint_used', [
                'v1_lyrics', 
                'v3_music', 
                'v3_singer', 
                'v3_convert_mp4', 
                'v3_convert_wav'
            ])->index();
            
            // Request details
            $table->json('request_payload'); // Store the full request sent to TopMediai
            /*
            request_payload example:
            {
                "prompt": "Happy summer song",
                "mood": "happy",
                "genre": "pop",
                "instruments": ["guitar", "drums"],
                "language": "english",
                "duration": 120
            }
            */
            
            // Response tracking
            $table->json('response_data')->nullable(); // Store TopMediai response
            /*
            response_data example:
            {
                "task_id": "abc123",
                "status": "pending",
                "estimated_time": 60,
                "content_url": "https://...",
                "thumbnail_url": "https://...",
                "metadata": {...}
            }
            */
            
            // Status tracking
            $table->enum('status', [
                'initiated',     // Request sent to TopMediai
                'pending',       // TopMediai processing
                'processing',    // TopMediai generating
                'completed',     // Successfully completed
                'failed',        // Failed generation
                'timeout',       // Request timed out
                'cancelled'      // User cancelled
            ])->default('initiated')->index();
            
            // Error handling
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            // Timing and performance
            $table->timestamp('request_sent_at')->nullable();
            $table->timestamp('response_received_at')->nullable();
            $table->integer('processing_time_seconds')->nullable(); // Total time taken
            $table->integer('estimated_completion_time')->nullable(); // From TopMediai
            
            // Rate limiting and quota tracking
            $table->boolean('counted_towards_quota')->default(true);
            $table->boolean('is_premium_request')->default(false);
            $table->decimal('api_cost', 8, 4)->nullable(); // If TopMediai charges per request
            
            // Request metadata
            $table->string('device_id')->index(); // For device-specific tracking
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance and analytics
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['endpoint_used', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index('processing_time_seconds');
            $table->index(['counted_towards_quota', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_requests');
    }
};