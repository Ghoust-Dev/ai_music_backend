<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiMusicUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'device_fingerprint',
        'subscription_credits',
        'addon_credits',
        'subscription_expires_at',
        'last_active_at',
        'device_info',
    ];

    protected $casts = [
        'device_info' => 'array',
        'last_active_at' => 'datetime',
        'subscription_expires_at' => 'datetime',
    ];

    /**
     * Get generated content for this user
     */
    public function generatedContent(): HasMany
    {
        return $this->hasMany(GeneratedContent::class, 'user_id');
    }

    /**
     * Get generation requests for this user
     */
    public function generationRequests(): HasMany
    {
        return $this->hasMany(GenerationRequest::class, 'user_id');
    }

    /**
     * Get purchases for this user
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'user_id');
    }

    /**
     * Check if user has an active subscription
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription_expires_at !== null && $this->subscription_expires_at->isFuture();
    }

    /**
     * Get total available credits
     */
    public function totalCredits(): int
    {
        return $this->subscription_credits + $this->addon_credits;
    }

    /**
     * Check if user can generate content (has any credits)
     */
    public function canGenerate(): bool
    {
        return $this->totalCredits() > 0;
    }

    /**
     * Get remaining credits (backward compatibility)
     */
    public function remainingCredits(): int
    {
        return $this->totalCredits();
    }

    /**
     * Decrement credits with priority: subscription first, then addon
     */
    public function decrementCredits(int $amount = 1): bool
    {
        if ($this->totalCredits() < $amount) {
            return false;
        }

        $remaining = $amount;
        
        // First consume subscription credits
        if ($this->subscription_credits > 0) {
            $consumeFromSubscription = min($this->subscription_credits, $remaining);
            $this->decrement('subscription_credits', $consumeFromSubscription);
            $remaining -= $consumeFromSubscription;
        }
        
        // Then consume addon credits if needed
        if ($remaining > 0 && $this->addon_credits > 0) {
            $this->decrement('addon_credits', $remaining);
        }
        
        return true;
    }

    /**
     * Add subscription credits (expire with subscription)
     */
    public function addSubscriptionCredits(int $amount): void
    {
        $this->increment('subscription_credits', $amount);
    }

    /**
     * Add addon credits (lifetime)
     */
    public function addAddonCredits(int $amount): void
    {
        $this->increment('addon_credits', $amount);
    }

    /**
     * Add credits to user balance (backward compatibility)
     */
    public function addCredits(int $amount): void
    {
        $this->addSubscriptionCredits($amount);
    }

    /**
     * Check if user is premium (backward compatibility)
     */
    public function isPremium(): bool
    {
        return $this->hasActiveSubscription();
    }

    /**
     * Get active subscription (backward compatibility)
     */
    public function activeSubscription()
    {
        // For backward compatibility, return a mock subscription object
        // This will be replaced when we implement the full purchase system
        if ($this->hasActiveSubscription()) {
            return (object) [
                'id' => 'credit_based',
                'subscription_type' => 'credit_based',
                'status' => 'active',
                'platform' => 'app',
                'starts_at' => $this->created_at,
                'expires_at' => $this->subscription_expires_at,
                'auto_renewal' => false,
                'generations_included' => $this->subscription_credits,
                'generations_used' => 0,
                'features_enabled' => ['music_generation'],
                'subscription_expires_at' => $this->subscription_expires_at,
            ];
        }
        return null;
    }

    /**
     * Get remaining generations (backward compatibility)
     */
    public function remainingGenerations(): int
    {
        return $this->totalCredits();
    }

    /**
     * Set subscription expiration (for testing purposes)
     */
    public function setSubscriptionExpiration(\DateTime|string|null $expiresAt): void
    {
        $this->update([
            'subscription_expires_at' => $expiresAt
        ]);
    }

    /**
     * Check if subscription is expired
     */
    public function isSubscriptionExpired(): bool
    {
        return $this->subscription_expires_at !== null && $this->subscription_expires_at->isPast();
    }

    /**
     * Find user by device ID
     */
    public static function findByDeviceId(string $deviceId): ?self
    {
        return self::where('device_id', $deviceId)->first();
    }

    /**
     * Create or find user by device ID
     */
    public static function findOrCreateByDeviceId(string $deviceId, array $deviceInfo = []): self
    {
        $user = self::findByDeviceId($deviceId);
        
        if (!$user) {
            $user = self::create([
                'device_id' => $deviceId,
                'device_fingerprint' => md5($deviceId . config('topmediai.device_tracking.salt', 'default')),
                'subscription_credits' => 0, // New users start with 0 credits (must purchase subscription)
                'addon_credits' => 0,
                'subscription_expires_at' => null,
                'last_active_at' => now(),
                'device_info' => $deviceInfo,
            ]);
        } else {
            // Update last active time and device info
            $user->update([
                'last_active_at' => now(),
                'device_info' => array_merge($user->device_info ?? [], $deviceInfo),
            ]);
        }

        return $user;
    }

    /**
     * Reset monthly usage if needed
     * 
     * Note: Currently a no-op as monthly usage tracking is handled
     * at the subscription level in the subscriptions table.
     * The ai_music_users table only stores current credit balances.
     */
    public function resetMonthlyUsageIfNeeded(): void
    {
        // No-op: Monthly usage tracking is handled by the subscriptions table
        // The ai_music_users table only tracks current credits, not monthly limits
    }
}