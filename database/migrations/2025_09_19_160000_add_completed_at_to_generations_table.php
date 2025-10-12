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
        Schema::table('generations', function (Blueprint $table) {
            // Add completed_at timestamp after task_count
            $table->timestamp('completed_at')->nullable()->after('task_count');
            
            // Add index for performance
            $table->index('completed_at');
            $table->index(['status', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropIndex(['completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};