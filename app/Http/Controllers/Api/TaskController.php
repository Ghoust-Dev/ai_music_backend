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
                $freshStatus = $this->taskService->checkTaskStatus($taskId);
                
                // Update content with fresh status
                if ($freshStatus) {
                    $content->update([
                        'status' => $freshStatus['status'] ?? $content->status,
                        'content_url' => $freshStatus['content_url'] ?? $content->content_url,
                        'thumbnail_url' => $freshStatus['thumbnail_url'] ?? $content->thumbnail_url,
                        'download_url' => $freshStatus['download_url'] ?? $content->download_url,
                        'metadata' => array_merge($content->metadata ?? [], $freshStatus['metadata'] ?? []),
                        'completed_at' => $freshStatus['status'] === 'completed' ? now() : $content->completed_at,
                        'error_message' => $freshStatus['error_message'] ?? $content->error_message,
                    ]);
                    
                    $content->refresh();
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