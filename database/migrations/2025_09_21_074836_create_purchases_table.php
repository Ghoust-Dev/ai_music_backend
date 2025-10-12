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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('ai_music_users')->onDelete('cascade');
            $table->enum('product_type', ['subscription', 'credit_pack']);
            $table->string('product_name'); // 'weekly', 'monthly', 'yearly', 'basic_pack', 'standard_pack', 'premium_pack'
            $table->integer('credits_granted');
            $table->decimal('price', 8, 2); // e.g., 9.99
            $table->string('currency', 3)->default('USD'); // ISO currency code
            $table->json('metadata')->nullable(); // Store additional purchase details
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'product_type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
