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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            
            // User relationship
            $table->foreignId('user_id')->constrained('ai_music_users')->onDelete('cascade');
            
            // Subscription details
            $table->enum('subscription_type', [
                'free',           // Free tier
                'premium_monthly', // Monthly premium
                'premium_yearly',  // Yearly premium
                'premium_lifetime', // One-time purchase
                'trial'           // Free trial
            ])->index();
            
            $table->enum('status', [
                'active',         // Currently active
                'expired',        // Subscription ended
                'cancelled',      // User cancelled
                'pending',        // Payment pending
                'trial_expired',  // Trial period ended
                'refunded'        // Subscription refunded
            ])->default('pending')->index();
            
            // Subscription period
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('auto_renewal')->default(true);
            
            // Platform-specific data (iOS/Android)
            $table->enum('platform', ['ios', 'android', 'web'])->index();
            $table->string('platform_product_id')->nullable(); // iOS/Android product ID
            $table->text('purchase_receipt')->nullable(); // Base64 receipt from store
            $table->string('platform_transaction_id')->nullable()->unique(); // Store transaction ID
            $table->string('platform_original_transaction_id')->nullable(); // Original purchase ID
            
            // Receipt validation
            $table->json('receipt_validation_data')->nullable(); // Store validation response
            /*
            receipt_validation_data example:
            {
                "validated_at": "2024-01-01T00:00:00Z",
                "validation_response": {...},
                "is_valid": true,
                "store_response": {...}
            }
            */
            
            $table->timestamp('last_validation_at')->nullable();
            $table->boolean('is_receipt_valid')->default(false);
            
            // Pricing information
            $table->decimal('price', 8, 2)->nullable(); // Subscription price
            $table->string('currency', 3)->default('USD'); // Currency code
            $table->decimal('local_price', 8, 2)->nullable(); // Price in user's currency
            $table->string('local_currency', 3)->nullable(); // User's local currency
            
            // Usage tracking
            $table->integer('generations_included')->default(0); // Generations included in plan
            $table->integer('generations_used')->default(0); // Generations used this period
            $table->timestamp('usage_reset_at')->nullable(); // When usage resets
            
            // Features access
            $table->json('features_enabled')->nullable(); // Which premium features are enabled
            /*
            features_enabled example:
            {
                "unlimited_generations": true,
                "high_quality_audio": true,
                "custom_voices": true,
                "priority_processing": true,
                "commercial_license": false
            }
            */
            
            // Cancellation tracking
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancellation_reason', [
                'user_request',
                'payment_failed',
                'store_refund',
                'violation',
                'system_error'
            ])->nullable();
            $table->text('cancellation_notes')->nullable();
            
            // Device linking
            $table->string('device_id')->index(); // Which device purchased
            $table->boolean('allows_device_transfer')->default(true);
            $table->integer('max_linked_devices')->default(3);
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'expires_at']);
            $table->index(['platform', 'status']);
            $table->index(['subscription_type', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['auto_renewal', 'expires_at']);
            $table->index('platform_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};