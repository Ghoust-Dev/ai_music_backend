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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50); // INITIAL_PURCHASE, RENEWAL, etc.
            $table->string('app_user_id', 255); // RevenueCat user ID (device_id)
            $table->string('product_id', 100)->nullable();
            $table->string('platform', 20)->nullable(); // ios, android
            $table->string('transaction_id', 255)->nullable();
            $table->json('event_data'); // Full event payload
            $table->boolean('processed')->default(false);
            $table->text('processing_result')->nullable(); // Success/error message
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes for querying
            $table->index('event_type');
            $table->index('app_user_id');
            $table->index('transaction_id');
            $table->index('processed');
            $table->index(['app_user_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
