<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_type',
        'status',
        'starts_at',
        'expires_at',
        'trial_ends_at',
        'auto_renewal',
        'platform',
        'platform_product_id',
        'purchase_receipt',
        'platform_transaction_id',
        'platform_original_transaction_id',
        'receipt_validation_data',
        'last_validation_at',
        'is_receipt_valid',
        'price',
        'currency',
        'local_price',
        'local_currency',
        'generations_included',
        'generations_used',
        'usage_reset_at',
        'features_enabled',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_notes',
        'device_id',
        'allows_device_transfer',
        'max_linked_devices',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'auto_renewal' => 'boolean',
        'receipt_validation_data' => 'array',
        'last_validation_at' => 'datetime',
        'is_receipt_valid' => 'boolean',
        'price' => 'decimal:2',
        'local_price' => 'decimal:2',
        'usage_reset_at' => 'datetime',
        'features_enabled' => 'array',
        'cancelled_at' => 'datetime',
        'allows_device_transfer' => 'boolean',
    ];

    /**
     * Get the user that owns this subscription
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AiMusicUser::class, 'user_id');
    }

    /**
     * Check if subscription is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               (!$this->expires_at || $this->expires_at > now());
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at <= now();
    }

    /**
     * Check if subscription is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if subscription is in trial period
     */
    public function isInTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at > now();
    }

    /**
     * Check if trial has expired
     */
    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at <= now();
    }

    /**
     * Check if subscription needs renewal
     */
    public function needsRenewal(): bool
    {
        return $this->isActive() && 
               $this->expires_at && 
               $this->expires_at <= now()->addDays(7); // 7 days before expiry
    }

    /**
     * Check if feature is enabled
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->features_enabled ?? [];
        return $features[$feature] ?? false;
    }

    /**
     * Get remaining generations
     */
    public function getRemainingGenerations(): int
    {
        if ($this->hasFeature('unlimited_generations')) {
            return -1; // Unlimited
        }

        return max(0, $this->generations_included - $this->generations_used);
    }

    /**
     * Use generation from subscription
     */
    public function useGeneration(): bool
    {
        if ($this->hasFeature('unlimited_generations')) {
            return true; // Unlimited, always allow
        }

        if ($this->getRemainingGenerations() <= 0) {
            return false; // No generations left
        }

        $this->increment('generations_used');
        return true;
    }

    /**
     * Reset usage count (monthly/yearly reset)
     */
    public function resetUsage(): void
    {
        $this->update([
            'generations_used' => 0,
            'usage_reset_at' => $this->getNextResetDate(),
        ]);
    }

    /**
     * Get next reset date based on subscription type
     */
    protected function getNextResetDate(): \DateTime
    {
        return match ($this->subscription_type) {
            'premium_monthly' => now()->addMonth(),
            'premium_yearly' => now()->addYear(),
            default => now()->addMonth(),
        };
    }

    /**
     * Cancel subscription
     */
    public function cancel(string $reason = 'user_request', ?string $notes = null): void
    {
        $this->update([
            'status' => 'cancelled',
            'auto_renewal' => false,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'cancellation_notes' => $notes,
        ]);
    }

    /**
     * Validate receipt with platform
     */
    public function validateReceipt(): bool
    {
        // This would integrate with iOS/Android receipt validation
        // For now, just mark as validated
        $this->update([
            'last_validation_at' => now(),
            'is_receipt_valid' => true,
        ]);

        return true;
    }

    /**
     * Check if receipt needs validation
     */
    public function needsReceiptValidation(): bool
    {
        if (!$this->purchase_receipt) {
            return false;
        }

        // Validate every 24 hours for active subscriptions
        return !$this->last_validation_at || 
               $this->last_validation_at <= now()->subDay();
    }

    /**
     * Scope: Active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    /**
     * Scope: Expired subscriptions
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: Cancelled subscriptions
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope: Trial subscriptions
     */
    public function scopeInTrial($query)
    {
        return $query->where('trial_ends_at', '>', now());
    }

    /**
     * Scope: Filter by platform
     */
    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Scope: Filter by subscription type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('subscription_type', $type);
    }

    /**
     * Scope: Needs renewal
     */
    public function scopeNeedsRenewal($query)
    {
        return $query->where('status', 'active')
                    ->where('expires_at', '<=', now()->addDays(7))
                    ->where('auto_renewal', true);
    }

    /**
     * Get subscription summary
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->subscription_type,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'is_trial' => $this->isInTrial(),
            'expires_at' => $this->expires_at?->format('Y-m-d H:i:s'),
            'remaining_generations' => $this->getRemainingGenerations(),
            'features' => $this->features_enabled ?? [],
            'auto_renewal' => $this->auto_renewal,
        ];
    }

    /**
     * Get billing information
     */
    public function getBillingInfo(): array
    {
        return [
            'price' => $this->price,
            'currency' => $this->currency,
            'local_price' => $this->local_price,
            'local_currency' => $this->local_currency,
            'platform' => $this->platform,
            'product_id' => $this->platform_product_id,
            'transaction_id' => $this->platform_transaction_id,
            'receipt_valid' => $this->is_receipt_valid,
            'last_validation' => $this->last_validation_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get usage information
     */
    public function getUsageInfo(): array
    {
        return [
            'generations_included' => $this->generations_included,
            'generations_used' => $this->generations_used,
            'remaining_generations' => $this->getRemainingGenerations(),
            'usage_reset_at' => $this->usage_reset_at?->format('Y-m-d H:i:s'),
            'unlimited' => $this->hasFeature('unlimited_generations'),
        ];
    }

    /**
     * Static: Find active subscription for user
     */
    public static function findActiveForUser(int $userId): ?self
    {
        return self::where('user_id', $userId)
                  ->active()
                  ->first();
    }

    /**
     * Static: Get subscription analytics
     */
    public static function getAnalytics(int $days = 30): array
    {
        $subscriptions = self::where('created_at', '>=', now()->subDays($days))->get();
        
        $totalSubscriptions = $subscriptions->count();
        $activeSubscriptions = $subscriptions->where('status', 'active')->count();
        $cancelledSubscriptions = $subscriptions->where('status', 'cancelled')->count();
        $trialSubscriptions = $subscriptions->filter->isInTrial()->count();
        
        $typeBreakdown = $subscriptions->groupBy('subscription_type')->map->count();
        $platformBreakdown = $subscriptions->groupBy('platform')->map->count();

        return [
            'period_days' => $days,
            'total_subscriptions' => $totalSubscriptions,
            'active_subscriptions' => $activeSubscriptions,
            'cancelled_subscriptions' => $cancelledSubscriptions,
            'trial_subscriptions' => $trialSubscriptions,
            'type_breakdown' => $typeBreakdown->toArray(),
            'platform_breakdown' => $platformBreakdown->toArray(),
            'retention_rate' => $totalSubscriptions > 0 ? 
                round(($activeSubscriptions / $totalSubscriptions) * 100, 2) : 0,
        ];
    }
}