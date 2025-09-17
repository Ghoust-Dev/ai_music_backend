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
        'subscription_status',
        'usage_count',
        'monthly_usage',
        'usage_reset_date',
        'last_active_at',
        'device_info',
    ];

    protected $casts = [
        'device_info' => 'array',
        'last_active_at' => 'datetime',
        'usage_reset_date' => 'date',
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
     * Get subscriptions for this user
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    /**
     * Get active subscription
     */
    public function activeSubscription()
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Check if user is premium
     */
    public function isPremium(): bool
    {
        return $this->subscription_status === 'premium' || $this->activeSubscription() !== null;
    }

    /**
     * Check if user can generate content
     */
    public function canGenerate(): bool
    {
        if ($this->isPremium()) {
            return true;
        }

        $freeLimit = config('topmediai.subscription.free_tier_limit', 10);
        return $this->usage_count < $freeLimit;
    }

    /**
     * Get remaining free generations
     */
    public function remainingGenerations(): int
    {
        if ($this->isPremium()) {
            return -1; // Unlimited
        }

        $freeLimit = config('topmediai.subscription.free_tier_limit', 10);
        return max(0, $freeLimit - $this->usage_count);
    }

    /**
     * Reset monthly usage if needed
     */
    public function resetMonthlyUsageIfNeeded(): void
    {
        if ($this->usage_reset_date < now()->startOfMonth()) {
            $this->update([
                'monthly_usage' => 0,
                'usage_reset_date' => now()->startOfMonth()->toDateString(),
            ]);
        }
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
                'subscription_status' => 'free',
                'usage_count' => 0,
                'monthly_usage' => 0,
                'usage_reset_date' => now()->startOfMonth()->toDateString(),
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
}