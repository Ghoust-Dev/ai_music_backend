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
use Exception;

class CheckTaskStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $taskId;
    protected int $maxRetries;
    protected int $retryCount;

    /**
     * The number of seconds the job can run before timing out.
     * Increased to handle longer TopMediai generation times (up to 10+ minutes)
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     * Increased maxRetries to handle longer generation times (up to 25 attempts)
     */
    public function __construct(string $taskId, int $maxRetries = 25, int $retryCount = 0)
    {
        $this->taskId = $taskId;
        $this->maxRetries = $maxRetries;
        $this->retryCount = $retryCount;
    }

    /**
     * Execute the job.
     */
    public function handle(TaskStatusService $taskStatusService): void
    {
        Log::info('Checking task status', [
            'task_id' => $this->taskId,
            'retry_count' => $this->retryCount,
            'max_retries' => $this->maxRetries
        ]);

        try {
            // Find the content record
            $content = GeneratedContent::where('topmediai_task_id', $this->taskId)->first();
            
            if (!$content) {
                Log::warning('Task not found in database', ['task_id' => $this->taskId]);
                return;
            }

            // Skip if already completed or failed
            if (in_array($content->status, ['completed', 'failed'])) {
                Log::info('Task already in final status', [
                    'task_id' => $this->taskId,
                    'status' => $content->status
                ]);
                return;
            }

            // Check global rate limit before making API call
            if (!$this->checkRateLimit()) {
                $this->scheduleNextCheck();
                return;
            }

            // Check status with TopMediai
            $statusResult = $taskStatusService->checkTaskStatus($this->taskId);

            if ($statusResult['success']) {
                $taskData = $statusResult['tasks'][0] ?? null;
                
                if ($taskData) {
                    $this->updateContentStatus($content, $taskData);
                    
                    // If still processing, schedule next check
                    if (in_array($taskData['status'], ['pending', 'processing'])) {
                        $this->scheduleNextCheck();
                    }
                } else {
                    Log::warning('No task data returned from TopMediai', ['task_id' => $this->taskId]);
                    $this->scheduleNextCheck();
                }
            } else {
                Log::warning('Failed to check task status', [
                    'task_id' => $this->taskId,
                    'error' => $statusResult['message'] ?? 'Unknown error'
                ]);
                $this->scheduleNextCheck();
            }

        } catch (Exception $e) {
            // TASK 3: Use ErrorHandlingService for better exception handling
            $errorService = new ErrorHandlingService();
            $errorDetails = $errorService->handleStatusCheckError($this->taskId, $e, $this->retryCount);
            
            Log::error('Error checking task status (Enhanced)', [
                'task_id' => $this->taskId,
                'retry_count' => $this->retryCount,
                'error_category' => $errorDetails['category'],
                'should_retry' => $errorDetails['should_retry'] ?? true,
                'error' => $e->getMessage()
            ]);
            
            if ($errorDetails['should_retry'] ?? true) {
                $this->scheduleNextCheck();
            } else {
                // Mark as failed if we shouldn't retry
                $content = GeneratedContent::where('topmediai_task_id', $this->taskId)->first();
                if ($content && in_array($content->status, ['pending', 'processing'])) {
                    $content->update([
                        'status' => 'failed',
                        'error_message' => $errorDetails['message'],
                        'completed_at' => now()
                    ]);
                }
            }
        }
    }

    /**
     * Check global rate limit to protect TopMediai API
     */
    protected function checkRateLimit(): bool
    {
        $rateLimitKey = 'topmediai_api_calls_per_minute';
        $maxCallsPerMinute = 20; // Conservative limit
        
        $currentCalls = Cache::get($rateLimitKey, 0);
        
        if ($currentCalls >= $maxCallsPerMinute) {
            Log::info('Rate limit reached, delaying task check', [
                'task_id' => $this->taskId,
                'current_calls' => $currentCalls,
                'limit' => $maxCallsPerMinute
            ]);
            return false;
        }
        
        // Increment counter with 60-second expiry
        Cache::put($rateLimitKey, $currentCalls + 1, now()->addMinutes(1));
        
        return true;
    }

    /**
     * Update content status based on TopMediai response
     */
    protected function updateContentStatus(GeneratedContent $content, array $taskData): void
    {
        $updates = [
            'status' => $this->mapTopMediaiStatus($taskData['status']),
            'last_accessed_at' => now(),
        ];

        // Update progress if available
        if (isset($taskData['progress'])) {
            $updates['progress'] = (int) $taskData['progress'];
        }

        // If completed, update URLs and completion time
        if ($taskData['status'] === 'completed') {
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
            $metadata['completed_at'] = now()->toISOString();
            $updates['metadata'] = $metadata;

            Log::info('Task completed successfully', [
                'task_id' => $this->taskId,
                'content_id' => $content->id,
                'audio_url' => $taskData['audio_url'] ?? null,
                'retry_count' => $this->retryCount
            ]);
        }

        // If failed, update error message
        if ($taskData['status'] === 'failed') {
            $updates['error_message'] = $taskData['error_message'] ?? 'Generation failed';
            $updates['completed_at'] = now();
            
            Log::warning('Task failed', [
                'task_id' => $this->taskId,
                'content_id' => $content->id,
                'error' => $updates['error_message'],
                'retry_count' => $this->retryCount
            ]);
        }

        // Update the content record
        $content->update($updates);

        // Update generation status if this content belongs to a generation
        if ($content->generation_id) {
            $generation = Generation::find($content->generation_id);
            if ($generation) {
                $generation->updateStatus();
                
                Log::info('Updated generation status', [
                    'generation_id' => $generation->id,
                    'new_status' => $generation->status
                ]);
            }
        }
    }

    /**
     * Map TopMediai status to our status values
     */
    protected function mapTopMediaiStatus(string $topMediaiStatus): string
    {
        return match($topMediaiStatus) {
            'pending', 'queued', 'waiting' => 'pending',
            'processing', 'running', 'generating' => 'processing',
            'completed', 'success', 'done' => 'completed',
            'failed', 'error', 'cancelled' => 'failed',
            default => 'pending'
        };
    }

    /**
     * Schedule the next status check with progressive delay
     */
    protected function scheduleNextCheck(): void
    {
        if ($this->retryCount >= $this->maxRetries) {
            // TASK 3: Use ErrorHandlingService for timeout handling
            $errorService = new ErrorHandlingService();
            $totalMinutes = ($this->maxRetries * 2); // Approximate total time
            $timeoutResponse = $errorService->handleTaskTimeout($this->taskId, $this->maxRetries, $totalMinutes);
            
            Log::warning('Max retries reached for task (Enhanced)', [
                'task_id' => $this->taskId,
                'max_retries' => $this->maxRetries,
                'total_minutes' => $totalMinutes,
                'error_code' => $timeoutResponse['error_code']
            ]);
            
            // Mark as failed due to timeout with enhanced error message
            $content = GeneratedContent::where('topmediai_task_id', $this->taskId)->first();
            if ($content && in_array($content->status, ['pending', 'processing'])) {
                $content->update([
                    'status' => 'failed',
                    'error_message' => $timeoutResponse['message'],
                    'completed_at' => now()
                ]);
                
                Log::info('Task marked as failed due to timeout (Enhanced)', [
                    'task_id' => $this->taskId,
                    'content_id' => $content->id,
                    'user_message' => $timeoutResponse['display_message']
                ]);
            }
            
            return;
        }

        // Calculate delay based on retry count (progressive backoff)
        $delay = $this->calculateDelay($this->retryCount);
        
        Log::info('Scheduling next status check', [
            'task_id' => $this->taskId,
            'retry_count' => $this->retryCount + 1,
            'delay_seconds' => $delay
        ]);

        // Schedule next check
        self::dispatch($this->taskId, $this->maxRetries, $this->retryCount + 1)
            ->delay(now()->addSeconds($delay));
    }

    /**
     * Calculate delay between checks (AGGRESSIVE EARLY DETECTION)
     * Much more frequent checks in first 5 minutes when most songs complete
     */
    protected function calculateDelay(int $retryCount): int
    {
        return match(true) {
            $retryCount < 3  => 30,   // 30 seconds (rapid early detection)
            $retryCount < 6  => 45,   // 45 seconds (frequent early checks)
            $retryCount < 10 => 60,   // 1 minute (standard early checks)
            $retryCount < 15 => 90,   // 1.5 minutes (mid processing)
            $retryCount < 20 => 150,  // 2.5 minutes (later processing)
            $retryCount < 25 => 300,  // 5 minutes (long processing)
            default => 600            // 10 minutes (final attempts)
        };
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckTaskStatusJob failed permanently', [
            'task_id' => $this->taskId,
            'retry_count' => $this->retryCount,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Mark content as failed
        $content = GeneratedContent::where('topmediai_task_id', $this->taskId)->first();
        if ($content && in_array($content->status, ['pending', 'processing'])) {
            $content->update([
                'status' => 'failed',
                'error_message' => 'System error during status checking: ' . $exception->getMessage(),
                'completed_at' => now()
            ]);
            
            Log::info('Task marked as failed due to job failure', [
                'task_id' => $this->taskId,
                'content_id' => $content->id
            ]);
        }
    }
}
