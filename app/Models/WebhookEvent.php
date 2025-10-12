<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WebhookEvent Model
 * 
 * Stores all incoming webhook events from RevenueCat for:
 * - Audit trail
 * - Debugging
 * - Analytics
 * - Replay protection
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'app_user_id',
        'product_id',
        'platform',
        'transaction_id',
        'event_data',
        'processed',
        'processing_result',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'event_data' => 'array',
        'processed' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Check if this event has been processed
     */
    public function isProcessed(): bool
    {
        return $this->processed;
    }

    /**
     * Mark event as processed
     */
    public function markAsProcessed(string $result): void
    {
        $this->update([
            'processed' => true,
            'processed_at' => now(),
            'processing_result' => $result,
        ]);
    }

    /**
     * Get user associated with this webhook event
     */
    public function user()
    {
        return $this->belongsTo(AiMusicUser::class, 'app_user_id', 'device_id');
    }

    /**
     * Scope: Get unprocessed events
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    /**
     * Scope: Get events by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope: Get events for specific user
     */
    public function scopeForUser($query, string $deviceId)
    {
        return $query->where('app_user_id', $deviceId);
    }

    /**
     * Scope: Get recent events
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('received_at', '>=', now()->subHours($hours));
    }
}
