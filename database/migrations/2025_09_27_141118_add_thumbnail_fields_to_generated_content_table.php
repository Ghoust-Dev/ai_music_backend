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
        Schema::table('generated_content', function (Blueprint $table) {
            $table->string('custom_thumbnail_url')->nullable()->after('thumbnail_url');
            $table->enum('thumbnail_generation_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')->after('custom_thumbnail_url');
            $table->text('thumbnail_prompt_used')->nullable()->after('thumbnail_generation_status');
            $table->integer('thumbnail_retry_count')->default(0)->after('thumbnail_prompt_used');
            $table->timestamp('thumbnail_completed_at')->nullable()->after('thumbnail_retry_count');
            
            // Add indexes for better performance
            $table->index('thumbnail_generation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_content', function (Blueprint $table) {
            $table->dropIndex(['thumbnail_generation_status']);
            $table->dropColumn([
                'custom_thumbnail_url',
                'thumbnail_generation_status', 
                'thumbnail_prompt_used',
                'thumbnail_retry_count',
                'thumbnail_completed_at'
            ]);
        });
    }
};