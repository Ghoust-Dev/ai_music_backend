<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'generation_id',
        'device_id',
        'user_id',
        'mode',
        'request_data',
        'estimated_time',
        'status',
        'task_count',
        'metadata',
        'completed_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Boot the model and set up event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate generation_id when creating
        static::creating(function ($generation) {
            if (empty($generation->generation_id)) {
                $generation->generation_id = 'gen_' . Str::random(12);
            }
        });
    }

    /**
     * Get the user that owns this generation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AiMusicUser::class, 'user_id');
    }

    /**
     * Get all tasks (generated content) for this generation
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(GeneratedContent::class);
    }

    /**
     * Get completed tasks only
     */
    public function completedTasks(): HasMany
    {
        return $this->hasMany(GeneratedContent::class)->where('status', 'completed');
    }

    /**
     * Get failed tasks only
     */
    public function failedTasks(): HasMany
    {
        return $this->hasMany(GeneratedContent::class)->where('status', 'failed');
    }

    /**
     * Get processing tasks only
     */
    public function processingTasks(): HasMany
    {
        return $this->hasMany(GeneratedContent::class)->whereIn('status', ['pending', 'processing']);
    }

    /**
     * Update generation status based on task statuses - TASK 4: Enhanced with events
     */
    public function updateStatus(): void
    {
        $tasks = $this->tasks;
        
        if ($tasks->isEmpty()) {
            $this->updateToStatus('processing', 'No tasks found');
            return;
        }

        $taskStatuses = $tasks->pluck('status')->toArray();
        $oldStatus = $this->status;
        
        // Determine overall status
        $newStatus = $this->calculateNewStatus($taskStatuses);
        
        // Only update if status changed
        if ($oldStatus !== $newStatus) {
            $this->updateToStatus($newStatus, $this->getStatusChangeReason($taskStatuses));
            
            // TASK 4: Log status change event
            Log::info('Generation status updated', [
                'generation_id' => $this->id,
                'generation_uuid' => $this->generation_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'task_count' => $tasks->count(),
                'completed_tasks' => $tasks->where('status', 'completed')->count(),
                'failed_tasks' => $tasks->where('status', 'failed')->count(),
                'processing_tasks' => $tasks->whereIn('status', ['pending', 'processing'])->count(),
                'trigger' => 'auto_update',
                'timestamp' => now()->toISOString()
            ]);
            
            // TASK 4: Update completion time if generation completed
            if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                $this->completed_at = now();
                $this->save();
                
                Log::info('Generation completed', [
                    'generation_id' => $this->id,
                    'generation_uuid' => $this->generation_id,
                    'started_at' => $this->created_at->toISOString(),
                    'completed_at' => $this->completed_at->toISOString(),
                    'duration_minutes' => $this->created_at->diffInMinutes($this->completed_at),
                    'total_tasks' => $tasks->count()
                ]);
            }
        }
    }

    /**
     * TASK 4: Calculate new status based on task statuses
     */
    protected function calculateNewStatus(array $taskStatuses): string
    {
        $uniqueStatuses = array_unique($taskStatuses);
        $statusCounts = array_count_values($taskStatuses);
        
        // If any tasks are still processing or pending, generation is processing
        if (in_array('processing', $taskStatuses) || in_array('pending', $taskStatuses)) {
            return 'processing';
        }
        
        // If all tasks are completed, generation is completed
        if (count($uniqueStatuses) === 1 && $uniqueStatuses[0] === 'completed') {
            return 'completed';
        }
        
        // If all tasks are failed, generation is failed
        if (count($uniqueStatuses) === 1 && $uniqueStatuses[0] === 'failed') {
            return 'failed';
        }
        
        // If we have both completed and failed tasks, it's mixed
        if (isset($statusCounts['completed']) && isset($statusCounts['failed'])) {
            // If majority completed, consider it completed
            if ($statusCounts['completed'] > $statusCounts['failed']) {
                return 'completed';
            }
            return 'mixed';
        }
        
        // Default to processing
        return 'processing';
    }

    /**
     * TASK 4: Get reason for status change
     */
    protected function getStatusChangeReason(array $taskStatuses): string
    {
        $statusCounts = array_count_values($taskStatuses);
        
        if (isset($statusCounts['completed']) && !isset($statusCounts['failed']) && !isset($statusCounts['pending']) && !isset($statusCounts['processing'])) {
            return "All {$statusCounts['completed']} tasks completed successfully";
        }
        
        if (isset($statusCounts['failed']) && !isset($statusCounts['completed']) && !isset($statusCounts['pending']) && !isset($statusCounts['processing'])) {
            return "All {$statusCounts['failed']} tasks failed";
        }
        
        if (isset($statusCounts['processing']) || isset($statusCounts['pending'])) {
            $pendingCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['processing'] ?? 0);
            return "{$pendingCount} tasks still processing";
        }
        
        if (isset($statusCounts['completed']) && isset($statusCounts['failed'])) {
            return "{$statusCounts['completed']} completed, {$statusCounts['failed']} failed";
        }
        
        return 'Status updated based on task changes';
    }

    /**
     * TASK 4: Update to specific status with logging
     */
    protected function updateToStatus(string $newStatus, string $reason): void
    {
        $this->status = $newStatus;
        $this->save();
        
        // Store status change in metadata for history
        $metadata = $this->metadata ?? [];
        $metadata['status_changes'] = $metadata['status_changes'] ?? [];
        $metadata['status_changes'][] = [
            'status' => $newStatus,
            'reason' => $reason,
            'timestamp' => now()->toISOString(),
            'trigger' => 'auto_update'
        ];
        
        // Keep only last 10 status changes
        if (count($metadata['status_changes']) > 10) {
            $metadata['status_changes'] = array_slice($metadata['status_changes'], -10);
        }
        
        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Get generation progress as percentage
     */
    public function getProgress(): int
    {
        $tasks = $this->tasks;
        
        if ($tasks->isEmpty()) {
            return 0;
        }

        $completedCount = $tasks->where('status', 'completed')->count();
        $totalCount = $tasks->count();

        return intval(($completedCount / $totalCount) * 100);
    }

    /**
     * Check if generation is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if generation has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if generation is still processing
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if generation has mixed results
     */
    public function hasMixedResults(): bool
    {
        return $this->status === 'mixed';
    }

    /**
     * Get summary statistics for this generation
     */
    public function getSummary(): array
    {
        $tasks = $this->tasks;
        
        return [
            'generation_id' => $this->generation_id,
            'mode' => $this->mode,
            'status' => $this->status,
            'progress' => $this->getProgress(),
            'total_tasks' => $tasks->count(),
            'completed_tasks' => $tasks->where('status', 'completed')->count(),
            'failed_tasks' => $tasks->where('status', 'failed')->count(),
            'processing_tasks' => $tasks->whereIn('status', ['pending', 'processing'])->count(),
            'estimated_time' => $this->estimated_time,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Scope: Filter by device
     */
    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope: Filter by user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Filter by mode
     */
    public function scopeByMode($query, string $mode)
    {
        return $query->where('mode', $mode);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Recent generations
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Find generation by generation_id
     */
    public static function findByGenerationId(string $generationId): ?self
    {
        return static::where('generation_id', $generationId)->first();
    }
}