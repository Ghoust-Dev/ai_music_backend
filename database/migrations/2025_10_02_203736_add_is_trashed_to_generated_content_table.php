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
            // Add is_trashed column (boolean, default false)
            $table->boolean('is_trashed')->default(false)->after('retry_count');
            
            // Add trashed_at timestamp (nullable) to track when song was trashed
            $table->timestamp('trashed_at')->nullable()->after('is_trashed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_content', function (Blueprint $table) {
            $table->dropColumn(['is_trashed', 'trashed_at']);
        });
    }
};
