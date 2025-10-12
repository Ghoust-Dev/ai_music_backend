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
            // Add streaming_url column for temporary processing URLs from TopMediai
            $table->string('streaming_url', 500)->nullable()->after('content_url');
            
            // Add index for efficient queries when looking for processing content
            $table->index(['status', 'streaming_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_content', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['status', 'streaming_url']);
            
            // Drop the streaming_url column
            $table->dropColumn('streaming_url');
        });
    }
};
