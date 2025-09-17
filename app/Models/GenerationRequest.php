<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'topmediai_task_id',
        'endpoint_used',
        'request_payload',
        'response_data',
        'status',
        'error_message',
        'error_code',
        'retry_count',
        'last_retry_at',
        'request_sent_at',
        'response_received_at',
        'processing_time_seconds',
        'estimated_completion_time',
        'counted_towards_quota',
        'is_premium_request',
        'api_cost',
        'device_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_data' => 'array',
        'last_retry_at' => 'datetime',
        'request_sent_at' => 'datetime',
        'response_received_at' => 'datetime',
        'counted_towards_quota' => 'boolean',
        'is_premium_request' => 'boolean',
        'api_cost' => 'decimal:4',
    ];

    /**
     * Get the user that made this request
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AiMusicUser::class, 'user_id');
    }

    /**
     * Get the generated content for this request
     */
    public function generatedContent(): BelongsTo
    {
        return $this->belongsTo(GeneratedContent::class, 'topmediai_task_id', 'topmediai_task_id');
    }

    /**
     * Check if request is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if request has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if request is still processing
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['initiated', 'pending', 'processing']);
    }

    /**
     * Check if request can be retried
     */
    public function canRetry(): bool
    {
        return $this->hasFailed() && $this->retry_count < config('topmediai.queue.tries', 3);
    }

    /**
     * Get processing duration in seconds
     */
    public function getProcessingDuration(): ?int
    {
        if (!$this->request_sent_at || !$this->response_received_at) {
            return null;
        }

        return $this->response_received_at->diffInSeconds($this->request_sent_at);
    }

    /**
     * Get formatted processing time
     */
    public function getFormattedProcessingTime(): string
    {
        $seconds = $this->processing_time_seconds ?? $this->getProcessingDuration();
        
        if (!$seconds) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes}m {$remainingSeconds}s";
    }

    /**
     * Increment retry count
     */
    public function incrementRetry(): void
    {
        $this->increment('retry_count');
        $this->update(['last_retry_at' => now()]);
    }

    /**
     * Mark as failed with error
     */
    public function markAsFailed(string $errorMessage, ?string $errorCode = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'response_received_at' => now(),
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(array $responseData = []): void
    {
        $updateData = [
            'status' => 'completed',
            'response_received_at' => now(),
        ];

        if (!empty($responseData)) {
            $updateData['response_data'] = array_merge($this->response_data ?? [], $responseData);
        }

        if ($this->request_sent_at) {
            $updateData['processing_time_seconds'] = now()->diffInSeconds($this->request_sent_at);
        }

        $this->update($updateData);
    }

    /**
     * Scope: Filter by endpoint
     */
    public function scopeForEndpoint($query, string $endpoint)
    {
        return $query->where('endpoint_used', $endpoint);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter completed requests
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: Filter failed requests
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: Filter processing requests
     */
    public function scopeProcessing($query)
    {
        return $query->whereIn('status', ['initiated', 'pending', 'processing']);
    }

    /**
     * Scope: Filter by user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Filter by device
     */
    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope: Recent requests
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Quota counting requests
     */
    public function scopeCountingTowardsQuota($query)
    {
        return $query->where('counted_towards_quota', true);
    }

    /**
     * Scope: Premium requests
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium_request', true);
    }

    /**
     * Scope: Free requests
     */
    public function scopeFree($query)
    {
        return $query->where('is_premium_request', false);
    }

    /**
     * Get request summary for analytics
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint_used,
            'status' => $this->status,
            'processing_time' => $this->getFormattedProcessingTime(),
            'retry_count' => $this->retry_count,
            'is_premium' => $this->is_premium_request,
            'counted_towards_quota' => $this->counted_towards_quota,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get error details
     */
    public function getErrorDetails(): array
    {
        return [
            'error_message' => $this->error_message,
            'error_code' => $this->error_code,
            'retry_count' => $this->retry_count,
            'last_retry_at' => $this->last_retry_at?->format('Y-m-d H:i:s'),
            'can_retry' => $this->canRetry(),
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'processing_time_seconds' => $this->processing_time_seconds,
            'estimated_completion_time' => $this->estimated_completion_time,
            'actual_vs_estimated' => $this->estimated_completion_time && $this->processing_time_seconds 
                ? $this->processing_time_seconds - $this->estimated_completion_time 
                : null,
            'request_sent_at' => $this->request_sent_at?->format('Y-m-d H:i:s'),
            'response_received_at' => $this->response_received_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Static method: Get analytics for endpoint
     */
    public static function getEndpointAnalytics(string $endpoint, int $days = 30): array
    {
        $requests = self::forEndpoint($endpoint)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $total = $requests->count();
        $completed = $requests->where('status', 'completed')->count();
        $failed = $requests->where('status', 'failed')->count();
        $avgProcessingTime = $requests->where('status', 'completed')->avg('processing_time_seconds');

        return [
            'endpoint' => $endpoint,
            'period_days' => $days,
            'total_requests' => $total,
            'completed_requests' => $completed,
            'failed_requests' => $failed,
            'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'average_processing_time_seconds' => $avgProcessingTime ? round($avgProcessingTime, 2) : null,
        ];
    }

    /**
     * Static method: Get user usage analytics
     */
    public static function getUserUsageAnalytics(int $userId, int $days = 30): array
    {
        $requests = self::forUser($userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $totalRequests = $requests->count();
        $quotaRequests = $requests->where('counted_towards_quota', true)->count();
        $premiumRequests = $requests->where('is_premium_request', true)->count();
        $endpointBreakdown = $requests->groupBy('endpoint_used')->map->count();

        return [
            'user_id' => $userId,
            'period_days' => $days,
            'total_requests' => $totalRequests,
            'quota_requests' => $quotaRequests,
            'premium_requests' => $premiumRequests,
            'free_requests' => $totalRequests - $premiumRequests,
            'endpoint_breakdown' => $endpointBreakdown->toArray(),
        ];
    }
}