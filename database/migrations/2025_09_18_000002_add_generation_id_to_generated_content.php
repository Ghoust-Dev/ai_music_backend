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
            // Add generation_id foreign key after user_id
            $table->foreignId('generation_id')->nullable()->after('user_id')
                  ->constrained('generations')->onDelete('cascade');
            
            // Add index for performance
            $table->index('generation_id');
            $table->index(['generation_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_content', function (Blueprint $table) {
            $table->dropForeign(['generation_id']);
            $table->dropIndex(['generation_id']);
            $table->dropIndex(['generation_id', 'status']);
            $table->dropColumn('generation_id');
        });
    }
};