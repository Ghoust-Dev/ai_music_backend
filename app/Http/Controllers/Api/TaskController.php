<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Services\TaskStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    protected TaskStatusService $taskService;

    public function __construct(TaskStatusService $taskService)
    {
        $this->taskService = $taskService;
    }

    /**
     * Get status of a specific task
     */
    public function getStatus(Request $request, string $taskId): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            // Find the content by task ID and verify ownership
            $content = GeneratedContent::where('topmediai_task_id', $taskId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found or not accessible'
                ], 404);
            }

            // Get fresh status from TopMediai if still processing
            if (in_array($content->status, ['pending', 'processing'])) {
                try {
                    $freshStatusResponse = $this->taskService->checkTaskStatus($taskId);
                    
                    // The new service returns multiple tasks, find our specific task
                    if ($freshStatusResponse['success'] && !empty($freshStatusResponse['tasks'])) {
                        $taskData = collect($freshStatusResponse['tasks'])
                            ->firstWhere('task_id', $taskId);
                        
                        if ($taskData) {
                            // Content is already updated by the service, just refresh
                            $content->refresh();
                            
                            Log::info('Task status updated', [
                                'task_id' => $taskId,
                                'old_status' => $content->status,
                                'new_status' => $taskData['status'],
                                'has_audio' => !empty($taskData['audio_url'] ?? null)
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to check fresh task status', [
                        'task_id' => $taskId,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with cached status if API call fails
                }
            }

            // Update last accessed
            $content->update(['last_accessed_at' => now()]);

            return response()->json([
                'success' => true,
                'data' => [
                    'task_id' => $content->topmediai_task_id,
                    'content_id' => $content->id,
                    'status' => $content->status,
                    'content_type' => $content->content_type,
                    'title' => $content->title,
                    'prompt' => $content->prompt,
                    'progress' => $this->calculateProgress($content),
                    'estimated_completion' => $this->getEstimatedCompletion($content),
                    'content_urls' => [
                        'content_url' => $content->content_url,
                        'streaming_url' => $content->streaming_url,
                        'thumbnail_url' => $content->thumbnail_url,
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                    ],
                    'metadata' => $content->metadata,
                    'error_message' => $content->error_message,
                    'created_at' => $content->created_at,
                    'completed_at' => $content->completed_at,
                    'last_accessed_at' => $content->last_accessed_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Task status check failed', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get task status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get status for multiple tasks at once
     */
    public function getMultipleStatus(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'task_ids' => 'required|array|min:1|max:10',
                'task_ids.*' => 'required|string'
            ]);

            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            $taskIds = $request->input('task_ids');
            
            // Find all content belonging to this user
            $contents = GeneratedContent::whereIn('topmediai_task_id', $taskIds)
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('topmediai_task_id');

            // Check if we need to fetch fresh status for any processing tasks
            $processingTaskIds = $contents->filter(function ($content) {
                return in_array($content->status, ['pending', 'processing']);
            })->pluck('topmediai_task_id')->toArray();

            if (!empty($processingTaskIds)) {
                try {
                    $freshStatusResponse = $this->taskService->checkTaskStatus(implode(',', $processingTaskIds));
                    
                    if ($freshStatusResponse['success']) {
                        // Refresh the content models to get updated data
                        $contents = GeneratedContent::whereIn('topmediai_task_id', $taskIds)
                            ->where('user_id', $user->id)
                            ->get()
                            ->keyBy('topmediai_task_id');
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to check fresh status for multiple tasks', [
                        'task_ids' => $processingTaskIds,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Prepare response data
            $tasks = [];
            $completedCount = 0;
            $failedCount = 0;
            $processingCount = 0;

            foreach ($taskIds as $taskId) {
                $content = $contents->get($taskId);
                
                if (!$content) {
                    $tasks[] = [
                        'task_id' => $taskId,
                        'status' => 'not_found',
                        'message' => 'Task not found or not accessible'
                    ];
                    continue;
                }

                // Update last accessed
                $content->update(['last_accessed_at' => now()]);

                $taskData = [
                    'task_id' => $content->topmediai_task_id,
                    'content_id' => $content->id,
                    'status' => $content->status,
                    'title' => $content->title,
                    'content_type' => $content->content_type,
                    'prompt' => $content->prompt,
                    'progress' => $this->calculateProgress($content),
                    'created_at' => $content->created_at,
                ];

                // Add completion data if completed
                if ($content->status === 'completed') {
                    $taskData = array_merge($taskData, [
                        'duration' => $content->duration,
                        'audio_url' => $content->content_url,
                        'streaming_url' => null, // Always null when completed
                        'cover_url' => $content->thumbnail_url,
                        'custom_thumbnail_url' => $content->custom_thumbnail_url,  // ✅ High-res Runware thumbnail
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(),  // ✅ Helper method
                        'thumbnail_status' => $content->thumbnail_generation_status,  // ✅ Thumbnail status
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                        'completed_at' => $content->completed_at,
                    ]);

                    // Extract additional data from metadata
                    $metadata = $content->metadata ?? [];
                    if (isset($metadata['lyrics'])) {
                        $taskData['lyrics'] = $metadata['lyrics'];
                    }
                    if (isset($metadata['song_id'])) {
                        $taskData['song_id'] = $metadata['song_id'];
                    }
                    if (isset($metadata['style'])) {
                        $taskData['style'] = $metadata['style'];
                    }

                    $completedCount++;
                } elseif ($content->status === 'failed') {
                    $taskData['error_message'] = $content->error_message;
                    $failedCount++;
                } elseif (in_array($content->status, ['pending', 'processing'])) {
                    $taskData['estimated_completion'] = $this->getEstimatedCompletion($content);
                    $taskData['streaming_url'] = $content->streaming_url; // Include streaming URL for processing tasks
                    // Add thumbnail info for processing tasks too
                    $taskData['custom_thumbnail_url'] = $content->custom_thumbnail_url;  // ✅ High-res Runware thumbnail
                    $taskData['best_thumbnail_url'] = $content->getBestThumbnailUrl();  // ✅ Helper method
                    $taskData['thumbnail_status'] = $content->thumbnail_generation_status;  // ✅ Thumbnail status
                    $processingCount++;
                }

                $tasks[] = $taskData;
            }

            // Determine overall status
            $overallStatus = $this->determineOverallStatus($tasks);

            return response()->json([
                'success' => true,
                'overall_status' => $overallStatus,
                'tasks' => $tasks,
                'summary' => [
                    'total_tasks' => count($tasks),
                    'completed_tasks' => $completedCount,
                    'failed_tasks' => $failedCount,
                    'processing_tasks' => $processingCount,
                    'not_found_tasks' => count($taskIds) - count($contents)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Multiple task status check failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get task status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Determine overall status from task array
     */
    private function determineOverallStatus(array $tasks): string
    {
        $statuses = array_column($tasks, 'status');
        
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
     * Calculate task progress percentage
     */
    private function calculateProgress(GeneratedContent $content): int
    {
        switch ($content->status) {
            case 'pending':
                return 0;
            case 'processing':
                // Estimate based on time elapsed
                if ($content->started_at) {
                    $elapsed = now()->diffInSeconds($content->started_at);
                    $estimated = 120; // 2 minutes average
                    return min(90, intval(($elapsed / $estimated) * 100));
                }
                return 10;
            case 'completed':
                return 100;
            case 'failed':
                return 0;
            default:
                return 0;
        }
    }

    /**
     * Get estimated completion time
     */
    private function getEstimatedCompletion(GeneratedContent $content): ?string
    {
        if ($content->status !== 'processing' || !$content->started_at) {
            return null;
        }

        $elapsed = now()->diffInSeconds($content->started_at);
        $estimated = 120; // 2 minutes average
        $remaining = max(0, $estimated - $elapsed);

        return now()->addSeconds($remaining)->toISOString();
    }
}