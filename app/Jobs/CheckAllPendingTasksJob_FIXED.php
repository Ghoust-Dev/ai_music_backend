<?php

namespace App\Jobs;

use App\Models\GeneratedContent;
use App\Models\Generation;
use App\Services\TaskStatusService;
use App\Services\ErrorHandlingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Exception;

class CheckAllPendingTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $batchSize;
    protected int $maxAge;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(int $batchSize = 20, int $maxAge = 180)
    {
        $this->batchSize = $batchSize;
        $this->maxAge = $maxAge; // 3 hours in minutes
    }

    /**
     * Execute the job - Check all pending tasks in batches
     */
    public function handle(TaskStatusService $taskStatusService, ErrorHandlingService $errorService): void
    {
        Log::info('Starting bulk pending tasks check', [
            'batch_size' => $this->batchSize,
            'max_age_minutes' => $this->maxAge
        ]);

        try {
            // Check if we should run (rate limiting)
            if (!$this->shouldRun()) {
                Log::info('Bulk check skipped due to rate limiting');
                return;
            }

            // Get pending tasks in batches
            $pendingTasks = $this->getPendingTasks();
            
            if ($pendingTasks->isEmpty()) {
                Log::info('No pending tasks found');
                $this->scheduleNextRun();
                return;
            }

            Log::info('Found pending tasks for bulk check', [
                'total_tasks' => $pendingTasks->count(),
                'batch_size' => $this->batchSize
            ]);

            // Process tasks in batches
            $batches = $pendingTasks->chunk($this->batchSize);
            $totalUpdated = 0;
            $totalErrors = 0;

            foreach ($batches as $batchIndex => $batch) {
                Log::info('Processing batch', [
                    'batch_index' => $batchIndex + 1,
                    'batch_size' => $batch->count(),
                    'total_batches' => $batches->count()
                ]);

                $result = $this->processBatch($batch, $taskStatusService, $errorService);
                $totalUpdated += $result['updated'];
                $totalErrors += $result['errors'];

                // Add delay between batches to respect rate limits
                if ($batchIndex < $batches->count() - 1) {
                    sleep(2); // 2 second delay between batches
                }
            }

            // Update generation statuses after all tasks processed
            $this->updateGenerationStatuses($pendingTasks);

            Log::info('Bulk pending tasks check completed', [
                'total_tasks_checked' => $pendingTasks->count(),
                'total_updated' => $totalUpdated,
                'total_errors' => $totalErrors,
                'batches_processed' => $batches->count()
            ]);

            // Schedule next run
            $this->scheduleNextRun();

        } catch (Exception $e) {
            $errorResponse = $errorService->handleException($e, 'bulk_status_check');
            
            Log::error('Bulk pending tasks check failed', [
                'error' => $e->getMessage(),
                'error_code' => $errorResponse['error_code'],
                'trace' => $e->getTraceAsString()
            ]);

            // Schedule retry with delay
            $this->scheduleRetry();
        }
    }

    /**
     * Get pending tasks that need status checking
     */
    protected function getPendingTasks(): Collection
    {
        // Get tasks that are pending or processing and within age limit
        $cutoffTime = now()->subMinutes($this->maxAge);
        
        return GeneratedContent::where(function($query) {
                $query->where('status', 'pending')
                      ->orWhere('status', 'processing');
            })
            ->where('created_at', '>=', $cutoffTime)
            ->whereNotNull('topmediai_task_id')
            ->where('topmediai_task_id', '!=', '')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Process a batch of tasks
     */
    protected function processBatch(Collection $batch, TaskStatusService $taskStatusService, ErrorHandlingService $errorService): array
    {
        $taskIds = $batch->pluck('topmediai_task_id')->toArray();
        $updated = 0;
        $errors = 0;

        try {
            // Use batch status checking
            $statusResult = $taskStatusService->checkMultipleTaskStatus($taskIds);

            if ($statusResult['success']) {
                foreach ($statusResult['tasks'] as $taskData) {
                    $taskId = $taskData['task_id'];
                    $content = $batch->firstWhere('topmediai_task_id', $taskId);
                    
                    if ($content && $this->shouldUpdateContent($content, $taskData)) {
                        $this->updateContentFromTask($content, $taskData);
                        $updated++;
                        
                        Log::debug('Content updated from batch check', [
                            'task_id' => $taskId,
                            'content_id' => $content->id,
                            'old_status' => $content->status,
                            'new_status' => $this->mapTopMediaiStatus($taskData)
                        ]);
                    }
                }
            } else {
                Log::warning('Batch status check failed', [
                    'task_ids' => $taskIds,
                    'error' => $statusResult['message'] ?? 'Unknown error'
                ]);
                $errors += count($taskIds);
            }

        } catch (Exception $e) {
            Log::error('Error processing batch', [
                'task_ids' => $taskIds,
                'error' => $e->getMessage()
            ]);
            $errors += count($taskIds);
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Check if content should be updated based on task data
     */
    protected function shouldUpdateContent(GeneratedContent $content, array $taskData): bool
    {
        $newStatus = $this->mapTopMediaiStatus($taskData);
        
        // Only update if status actually changed or if we have new data
        return $content->status !== $newStatus || 
               ($newStatus === 'completed' && empty($content->content_url)) ||
               (isset($taskData['progress']) && $taskData['progress'] !== $content->progress);
    }

    /**
     * Update content from task data
     */
    protected function updateContentFromTask(GeneratedContent $content, array $taskData): void
    {
        $updates = [
            'status' => $this->mapTopMediaiStatus($taskData),
            'last_accessed_at' => now(),
        ];

        // Update progress if available
        if (isset($taskData['progress'])) {
            $updates['progress'] = (int) $taskData['progress'];
        }

        // If completed, update URLs and completion time
        if ($this->mapTopMediaiStatus($taskData) === 'completed') {
            $updates['completed_at'] = now();
            
            // Update URLs from TopMediai response
            if (isset($taskData['audio_url'])) {
                $updates['content_url'] = $taskData['audio_url'];
                $updates['download_url'] = $taskData['audio_url'];
                $updates['preview_url'] = $taskData['audio_url'];
            }
            
            if (isset($taskData['cover_url'])) {
                $updates['thumbnail_url'] = $taskData['cover_url'];
            }

            // Update metadata with completion data
            $metadata = $content->metadata ?? [];
            $metadata['topmediai_completion_data'] = $taskData;
            $metadata['bulk_check_completed_at'] = now()->toISOString();
            $updates['metadata'] = $metadata;
        }

        // If failed, update error message
        if ($this->mapTopMediaiStatus($taskData) === 'failed') {
            $updates['error_message'] = $taskData['error_message'] ?? 'Generation failed';
            $updates['completed_at'] = now();
        }

        // Update the content record
        $content->update($updates);
    }

    /**
     * Update generation statuses for all affected generations
     */
    protected function updateGenerationStatuses(Collection $pendingTasks): void
    {
        $generationIds = $pendingTasks->whereNotNull('generation_id')
                                     ->pluck('generation_id')
                                     ->unique();

        $updatedCount = 0;
        
        foreach ($generationIds as $generationId) {
            $generation = Generation::find($generationId);
            if ($generation) {
                $oldStatus = $generation->status;
                $generation->updateStatus();
                
                if ($oldStatus !== $generation->status) {
                    $updatedCount++;
                    
                    Log::info('Generation status updated from bulk check', [
                        'generation_id' => $generation->id,
                        'generation_uuid' => $generation->generation_id,
                        'old_status' => $oldStatus,
                        'new_status' => $generation->status
                    ]);
                }
            }
        }

        Log::info('Generation statuses updated', [
            'total_generations_checked' => $generationIds->count(),
            'generations_updated' => $updatedCount
        ]);
    }

    /**
     * Map TopMediai status to our status values using duration-based logic
     * This matches the logic in TaskStatusService::mapTopMediaiTaskStatus
     */
    protected function mapTopMediaiStatus(array $taskData): string
    {
        $statusCode = $taskData['status'] ?? -1;
        $duration = $taskData['duration'] ?? -1;
        $failCode = $taskData['fail_code'] ?? null;
        $failReason = $taskData['fail_reason'] ?? null;
        
        // Primary check: FAILED status (highest priority)
        if ($statusCode === 3 || $failCode !== null || $failReason !== null) {
            return 'failed';
        }
        
        // Primary check: DURATION determines completion (most reliable)
        if ($duration !== -1 && $duration > 0) {
            // Has real duration = COMPLETED (regardless of status code)
            Log::debug('Bulk check: Task completed - duration available', [
                'task_id' => $taskData['task_id'] ?? $taskData['id'] ?? 'unknown',
                'status_code' => $statusCode,
                'duration' => $duration
            ]);
            return 'completed';
        }
        
        // Duration = -1, check status code for processing state
        return match($statusCode) {
            1 => 'pending',         // Queued/waiting
            2 => 'processing',      // Processing  
            0 => 'processing',      // Status says complete but duration=-1, still processing
            default => 'processing' // Unknown = assume processing
        };
    }

    /**
     * Check if bulk check should run (rate limiting)
     */
    protected function shouldRun(): bool
    {
        $lockKey = 'bulk_status_check_running';
        $frequencyKey = 'bulk_status_check_last_run';
        
        // Check if already running
        if (Cache::has($lockKey)) {
            return false;
        }
        
        // Check minimum frequency (don't run more than once every 5 minutes)
        $lastRun = Cache::get($frequencyKey);
        if ($lastRun && now()->diffInMinutes($lastRun) < 5) {
            return false;
        }
        
        // Set lock for this run
        Cache::put($lockKey, true, now()->addMinutes(15));
        Cache::put($frequencyKey, now(), now()->addHours(1));
        
        return true;
    }

    /**
     * Schedule next bulk check run
     */
    protected function scheduleNextRun(): void
    {
        // Schedule next run in 10 minutes
        $delay = now()->addMinutes(10);
        
        self::dispatch($this->batchSize, $this->maxAge)
            ->delay($delay);
            
        Log::info('Next bulk check scheduled', [
            'scheduled_for' => $delay->toISOString(),
            'delay_minutes' => 10
        ]);

        // Clear the running lock
        Cache::forget('bulk_status_check_running');
    }

    /**
     * Schedule retry after error
     */
    protected function scheduleRetry(): void
    {
        // Schedule retry in 5 minutes
        $delay = now()->addMinutes(5);
        
        self::dispatch($this->batchSize, $this->maxAge)
            ->delay($delay);
            
        Log::info('Bulk check retry scheduled', [
            'scheduled_for' => $delay->toISOString(),
            'delay_minutes' => 5
        ]);

        // Clear the running lock
        Cache::forget('bulk_status_check_running');
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckAllPendingTasksJob failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Clear the running lock
        Cache::forget('bulk_status_check_running');

        // Schedule retry in 15 minutes
        self::dispatch($this->batchSize, $this->maxAge)
            ->delay(now()->addMinutes(15));
            
        Log::info('Emergency bulk check retry scheduled after failure', [
            'scheduled_for' => now()->addMinutes(15)->toISOString(),
            'delay_minutes' => 15
        ]);
    }
}