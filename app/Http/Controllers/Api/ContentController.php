<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Models\GeneratedContent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ContentController extends Controller
{
    /**
     * List user's generated content
     */
    public function list(Request $request): JsonResponse
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

            // Validate request parameters
            $validator = Validator::make($request->all(), [
                'content_type' => 'nullable|string|in:song,lyrics,instrumental,vocal',
                'status' => 'nullable|string|in:pending,processing,completed,failed',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'sort_by' => 'nullable|string|in:created_at,completed_at,title,status',
                'sort_order' => 'nullable|string|in:asc,desc',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = GeneratedContent::where('user_id', $user->id);

            // Apply filters
            if ($request->has('content_type')) {
                $query->where('content_type', $request->input('content_type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            // Apply sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->input('per_page', 20);
            $contents = $query->paginate($perPage);

            $formattedContents = $contents->map(function ($content) {
                return [
                    'id' => $content->id,
                    'task_id' => $content->topmediai_task_id,
                    'title' => $content->title,
                    'content_type' => $content->content_type,
                    'status' => $content->status,
                    'prompt' => $content->prompt,
                    'mood' => $content->mood,
                    'genre' => $content->genre,
                    'language' => $content->language,
                    'duration' => $content->duration,
                    'instruments' => $content->instruments,
                    'content_urls' => [
                        'content_url' => $content->content_url,
                        'thumbnail_url' => $content->thumbnail_url,
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                    ],
                    'progress' => $this->calculateProgress($content),
                    'is_premium_generation' => $content->is_premium_generation,
                    'created_at' => $content->created_at,
                    'completed_at' => $content->completed_at,
                    'last_accessed_at' => $content->last_accessed_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'contents' => $formattedContents,
                    'pagination' => [
                        'current_page' => $contents->currentPage(),
                        'per_page' => $contents->perPage(),
                        'total' => $contents->total(),
                        'last_page' => $contents->lastPage(),
                        'has_more' => $contents->hasMorePages(),
                    ],
                    'filters' => [
                        'content_type' => $request->input('content_type'),
                        'status' => $request->input('status'),
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Content list failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve content list',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Calculate content progress percentage
     */
    private function calculateProgress(GeneratedContent $content): int
    {
        switch ($content->status) {
            case 'pending':
                return 0;
            case 'processing':
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
}
