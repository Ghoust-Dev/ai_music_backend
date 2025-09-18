<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use Illuminate\Support\Facades\Log;
use Exception;

class TaskStatusService extends TopMediaiBaseService
{
    /**
     * Check task status from TopMediai V3
     * Can handle single task ID or comma-separated multiple task IDs
     */
    public function checkTaskStatus(string $taskIds): array
    {
        try {
            // Split task IDs if comma-separated
            $taskIdArray = array_filter(explode(',', $taskIds));
            
            Log::info('Checking task status', [
                'task_ids' => $taskIds,
                'task_count' => count($taskIdArray)
            ]);

            // Call TopMediai V3 music tasks endpoint with ids parameter
            $endpoint = config('topmediai.endpoints.music_status');
            $response = $this->get($endpoint, [
                'ids' => $taskIds  // TopMediai expects comma-separated string
            ]);

            Log::info('TopMediai task status response', [
                'task_ids' => $taskIds,
                'response_count' => is_array($response) ? count($response) : 0,
                'has_data' => !empty($response)
            ]);

            // Process and return clean response
            return $this->processTaskStatusResponse($response, $taskIdArray);

        } catch (Exception $e) {
            Log::error('Task status check failed', [
                'task_ids' => $taskIds,
                'error' => $e->getMessage()
            ]);

            // Try to update database with error status
            foreach (explode(',', $taskIds) as $taskId) {
                $this->updateTaskError(trim($taskId), $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Process TopMediai task status response and return clean frontend data
     */
    protected function processTaskStatusResponse(array $response, array $requestedTaskIds): array
    {
        $results = [];
        $allCompleted = true;
        $anyFailed = false;

        // TopMediai returns array of task objects
        foreach ($response as $taskData) {
            $taskId = $taskData['id'] ?? 'unknown';
            $status = $this->mapTopMediaiTaskStatus($taskData['status'] ?? 0);
            
            // Update our database records
            $this->updateDatabaseRecordsV3($taskId, $taskData);

            // Prepare clean response data
            $cleanTaskData = $this->prepareCleanTaskData($taskData, $status);
            $results[] = $cleanTaskData;

            // Track overall status
            if ($status !== 'completed') {
                $allCompleted = false;
            }
            if ($status === 'failed') {
                $anyFailed = true;
            }
        }

        // Handle missing tasks (not returned by TopMediai)
        $returnedTaskIds = array_column($response, 'id');
        $missingTaskIds = array_diff($requestedTaskIds, $returnedTaskIds);
        
        foreach ($missingTaskIds as $missingId) {
            $results[] = [
                'task_id' => $missingId,
                'status' => 'not_found',
                'message' => 'Task not found in TopMediai'
            ];
            $this->updateTaskError($missingId, 'Task not found in TopMediai response');
        }

        // Determine overall status
        $overallStatus = $this->determineOverallStatus($results);

        return [
            'success' => true,
            'overall_status' => $overallStatus,
            'tasks' => $results,
            'summary' => [
                'total_tasks' => count($results),
                'completed_tasks' => count(array_filter($results, fn($task) => $task['status'] === 'completed')),
                'failed_tasks' => count(array_filter($results, fn($task) => $task['status'] === 'failed')),
                'processing_tasks' => count(array_filter($results, fn($task) => in_array($task['status'], ['pending', 'processing'])))
            ]
        ];
    }

    /**
     * Map TopMediai V3 numeric status codes to our internal status
     */
    protected function mapTopMediaiTaskStatus(int $statusCode): string
    {
        return match($statusCode) {
            0, 1 => 'pending',      // Queued, Waiting
            2 => 'completed',       // Completed
            3 => 'failed',          // Failed
            default => 'processing'  // In progress or unknown
        };
    }

    /**
     * Prepare clean task data for frontend
     */
    protected function prepareCleanTaskData(array $taskData, string $status): array
    {
        $clean = [
            'task_id' => $taskData['id'] ?? 'unknown',
            'status' => $status,
            'title' => $taskData['title'] ?? 'Untitled',
            'style' => $taskData['style'] ?? null,
            'duration' => $taskData['duration'] ?? null,
        ];

        // Add URLs if completed
        if ($status === 'completed') {
            $clean['audio_url'] = $taskData['audio_url'] ?? null;
            $clean['cover_url'] = $taskData['cover_url'] ?? null;
            $clean['lyrics'] = $taskData['lyric'] ?? null;
            $clean['song_id'] = $taskData['song_id'] ?? null;
        }

        // Add error info if failed
        if ($status === 'failed') {
            $clean['fail_code'] = $taskData['fail_code'] ?? null;
            $clean['fail_reason'] = $taskData['fail_reason'] ?? 'Generation failed';
            $clean['error_message'] = $taskData['fail_reason'] ?? 'Music generation failed';
        }

        return $clean;
    }

    /**
     * Determine overall status from individual task results
     */
    protected function determineOverallStatus(array $results): string
    {
        $statuses = array_column($results, 'status');
        
        if (in_array('failed', $statuses)) {
            return 'failed';
        }
        
        if (in_array('processing', $statuses) || in_array('pending', $statuses)) {
            return 'processing';
        }
        
        if (!empty($statuses) && array_unique($statuses) === ['completed']) {
            return 'completed';
        }
        
        return 'mixed';
    }

    /**
     * Update database records with TopMediai V3 task data
     */
    protected function updateDatabaseRecordsV3(string $taskId, array $taskData): void
    {
        $status = $this->mapTopMediaiTaskStatus($taskData['status'] ?? 0);

        // Update generation request (may have multiple task IDs)
        $generationRequest = GenerationRequest::where('topmediai_task_id', 'LIKE', "%{$taskId}%")->first();
        if ($generationRequest) {
            $this->updateGenerationRequestV3($generationRequest, $taskData, $status);
        }

        // Update generated content
        $generatedContent = GeneratedContent::where('topmediai_task_id', $taskId)->first();
        if ($generatedContent) {
            $this->updateGeneratedContentV3($generatedContent, $taskData, $status);
        }
    }

    /**
     * Update generation request with V3 task data
     */
    protected function updateGenerationRequestV3(GenerationRequest $request, array $taskData, string $status): void
    {
        $updateData = [
            'response_data' => array_merge($request->response_data ?? [], [
                'task_status_update' => $taskData,
                'last_status_check' => now()->toISOString()
            ]),
            'status' => $status,
        ];

        // If completed, record timing
        if ($status === 'completed') {
            $updateData['response_received_at'] = now();
            
            if ($request->request_sent_at) {
                $updateData['processing_time_seconds'] = now()->diffInSeconds($request->request_sent_at);
            }
        }

        // If failed, record error
        if ($status === 'failed') {
            $updateData['error_message'] = $taskData['fail_reason'] ?? 'Generation failed';
            $updateData['error_code'] = (string)($taskData['fail_code'] ?? 'unknown');
        }

        $request->update($updateData);
        
        Log::info('Updated generation request V3', [
            'request_id' => $request->id,
            'task_id' => $taskData['id'] ?? 'unknown',
            'status' => $status
        ]);
    }

    /**
     * Update generated content with V3 task data
     */
    protected function updateGeneratedContentV3(GeneratedContent $content, array $taskData, string $status): void
    {
        $updateData = [
            'status' => $status,
            'metadata' => array_merge($content->metadata ?? [], [
                'topmediai_task_data' => $taskData,
                'last_status_check' => now()->toISOString()
            ])
        ];

        // If completed, extract URLs and metadata
        if ($status === 'completed') {
            $updateData['content_url'] = $taskData['audio_url'] ?? null;
            $updateData['thumbnail_url'] = $taskData['cover_url'] ?? null;
            $updateData['download_url'] = $taskData['audio_url'] ?? null;
            $updateData['preview_url'] = $taskData['audio_url'] ?? null;
            $updateData['completed_at'] = now();
            
            // Extract additional metadata
            if (isset($taskData['duration'])) {
                $updateData['duration'] = $taskData['duration'];
            }
            
            // Update title if provided
            if (!empty($taskData['title']) && $taskData['title'] !== 'Untitled') {
                $updateData['title'] = $taskData['title'];
            }
            
            // Merge additional metadata
            $updateData['metadata'] = array_merge($updateData['metadata'], [
                'song_id' => $taskData['song_id'] ?? null,
                'style' => $taskData['style'] ?? null,
                'lyrics' => $taskData['lyric'] ?? null,
                'topmediai_urls' => [
                    'audio_url' => $taskData['audio_url'] ?? null,
                    'cover_url' => $taskData['cover_url'] ?? null,
                ]
            ]);
        }

        // If failed, record error
        if ($status === 'failed') {
            $updateData['error_message'] = $taskData['fail_reason'] ?? 'Generation failed';
        }

        $content->update($updateData);
        
        Log::info('Updated generated content V3', [
            'content_id' => $content->id,
            'task_id' => $taskData['id'] ?? 'unknown',
            'status' => $status,
            'has_urls' => isset($updateData['content_url'])
        ]);
    }

    /**
     * Update database records with task status (legacy method)
     */
    protected function updateDatabaseRecords(string $taskId, array $response): void
    {
        // Update generation request
        $generationRequest = GenerationRequest::where('topmediai_task_id', $taskId)->first();
        if ($generationRequest) {
            $this->updateGenerationRequest($generationRequest, $response);
        }

        // Update generated content
        $generatedContent = GeneratedContent::where('topmediai_task_id', $taskId)->first();
        if ($generatedContent) {
            $this->updateGeneratedContent($generatedContent, $response);
        }
    }

    /**
     * Update generation request with status response
     */
    protected function updateGenerationRequest(GenerationRequest $request, array $response): void
    {
        $status = $this->mapTopMediaiStatus($response['status'] ?? 'unknown');
        
        $updateData = [
            'response_data' => array_merge($request->response_data ?? [], $response),
            'status' => $status,
        ];

        // If completed, record timing
        if ($status === 'completed') {
            $updateData['response_received_at'] = now();
            
            if ($request->request_sent_at) {
                $updateData['processing_time_seconds'] = now()->diffInSeconds($request->request_sent_at);
            }
        }

        $request->update($updateData);
        
        Log::info('Updated generation request', [
            'request_id' => $request->id,
            'task_id' => $request->topmediai_task_id,
            'status' => $status
        ]);
    }

    /**
     * Update generated content with status response
     */
    protected function updateGeneratedContent(GeneratedContent $content, array $response): void
    {
        $status = $this->mapTopMediaiStatus($response['status'] ?? 'unknown');
        
        $updateData = [
            'status' => $status,
            'metadata' => array_merge($content->metadata ?? [], [
                'topmediai_response' => $response,
                'last_status_check' => now()->toISOString()
            ])
        ];

        // If completed, extract URLs and metadata
        if ($status === 'completed' && isset($response['result'])) {
            $result = $response['result'];
            
            $updateData['content_url'] = $result['audio_url'] ?? $result['music_url'] ?? null;
            $updateData['thumbnail_url'] = $result['thumbnail_url'] ?? $result['cover_url'] ?? null;
            $updateData['download_url'] = $result['download_url'] ?? $updateData['content_url'];
            $updateData['preview_url'] = $result['preview_url'] ?? null;
            $updateData['completed_at'] = now();
            
            // Extract additional metadata
            if (isset($result['duration'])) {
                $updateData['duration'] = $result['duration'];
            }
            
            // Merge result metadata
            $updateData['metadata'] = array_merge($updateData['metadata'], [
                'file_size' => $result['file_size'] ?? null,
                'format' => $result['format'] ?? 'mp3',
                'bitrate' => $result['bitrate'] ?? null,
                'sample_rate' => $result['sample_rate'] ?? null,
            ]);
        }

        // If failed, record error
        if ($status === 'failed') {
            $updateData['error_message'] = $response['error'] ?? $response['message'] ?? 'Generation failed';
        }

        $content->update($updateData);
        
        Log::info('Updated generated content', [
            'content_id' => $content->id,
            'task_id' => $content->topmediai_task_id,
            'status' => $status,
            'has_urls' => isset($updateData['content_url'])
        ]);
    }

    /**
     * Map TopMediai status to our internal status
     */
    protected function mapTopMediaiStatus(string $topMediaiStatus): string
    {
        return match (strtolower($topMediaiStatus)) {
            'pending', 'queued', 'waiting' => 'pending',
            'processing', 'generating', 'in_progress' => 'processing',
            'completed', 'finished', 'success' => 'completed',
            'failed', 'error', 'cancelled' => 'failed',
            default => 'pending'
        };
    }

    /**
     * Update task with error status
     */
    protected function updateTaskError(string $taskId, string $errorMessage): void
    {
        // Update generation request
        GenerationRequest::where('topmediai_task_id', $taskId)
            ->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'response_received_at' => now(),
            ]);

        // Update generated content
        GeneratedContent::where('topmediai_task_id', $taskId)
            ->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
            ]);
    }

    /**
     * Get all pending tasks for status checking
     */
    public function getPendingTasks(): array
    {
        $pendingContent = GeneratedContent::whereIn('status', ['pending', 'processing'])
            ->whereNotNull('topmediai_task_id')
            ->where('created_at', '>', now()->subHours(24)) // Only check recent tasks
            ->get();

        return $pendingContent->map(function ($content) {
            return [
                'task_id' => $content->topmediai_task_id,
                'content_id' => $content->id,
                'user_id' => $content->user_id,
                'status' => $content->status,
                'created_at' => $content->created_at,
                'started_at' => $content->started_at,
            ];
        })->toArray();
    }

    /**
     * Bulk check status for multiple tasks
     */
    public function bulkCheckStatus(array $taskIds): array
    {
        $results = [];
        
        foreach ($taskIds as $taskId) {
            try {
                $result = $this->checkTaskStatus($taskId);
                $results[$taskId] = $result;
            } catch (Exception $e) {
                $results[$taskId] = [
                    'success' => false,
                    'task_id' => $taskId,
                    'error' => $e->getMessage()
                ];
                
                // Continue with other tasks even if one fails
                Log::warning('Bulk status check failed for task', [
                    'task_id' => $taskId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Get task progress summary for user
     */
    public function getUserTasksSummary(int $userId): array
    {
        $tasks = GeneratedContent::where('user_id', $userId)
            ->whereNotNull('topmediai_task_id')
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentTasks = GeneratedContent::where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'title', 'status', 'topmediai_task_id', 'created_at'])
            ->toArray();

        return [
            'status_summary' => $tasks,
            'total_tasks' => array_sum($tasks),
            'active_tasks' => ($tasks['pending'] ?? 0) + ($tasks['processing'] ?? 0),
            'recent_active_tasks' => $recentTasks
        ];
    }

    /**
     * Get detailed task information
     */
    public function getTaskDetails(string $taskId): array
    {
        $content = GeneratedContent::where('topmediai_task_id', $taskId)->first();
        $request = GenerationRequest::where('topmediai_task_id', $taskId)->first();

        if (!$content) {
            throw new Exception('Task not found');
        }

        return [
            'task_id' => $taskId,
            'content' => [
                'id' => $content->id,
                'title' => $content->title,
                'content_type' => $content->content_type,
                'status' => $content->status,
                'prompt' => $content->prompt,
                'content_url' => $content->content_url,
                'thumbnail_url' => $content->thumbnail_url,
                'metadata' => $content->metadata,
                'created_at' => $content->created_at,
                'started_at' => $content->started_at,
                'completed_at' => $content->completed_at,
                'error_message' => $content->error_message,
            ],
            'request' => $request ? [
                'id' => $request->id,
                'endpoint_used' => $request->endpoint_used,
                'request_payload' => $request->request_payload,
                'response_data' => $request->response_data,
                'status' => $request->status,
                'processing_time_seconds' => $request->processing_time_seconds,
                'retry_count' => $request->retry_count,
                'error_message' => $request->error_message,
            ] : null
        ];
    }

    /**
     * Cancel a pending task (if supported by TopMediai)
     */
    public function cancelTask(string $taskId): array
    {
        // Note: Implement if TopMediai supports task cancellation
        // For now, just mark as cancelled in our database
        
        $content = GeneratedContent::where('topmediai_task_id', $taskId)->first();
        $request = GenerationRequest::where('topmediai_task_id', $taskId)->first();

        if (!$content) {
            throw new Exception('Task not found');
        }

        if (!in_array($content->status, ['pending', 'processing'])) {
            throw new Exception('Task cannot be cancelled in current status: ' . $content->status);
        }

        // Update our records
        $content->update([
            'status' => 'cancelled',
            'error_message' => 'Task cancelled by user'
        ]);

        if ($request) {
            $request->update([
                'status' => 'cancelled',
                'error_message' => 'Task cancelled by user'
            ]);
        }

        Log::info('Task cancelled', [
            'task_id' => $taskId,
            'content_id' => $content->id
        ]);

        return [
            'success' => true,
            'task_id' => $taskId,
            'message' => 'Task cancelled successfully'
        ];
    }
}