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
                'show_trashed' => 'nullable|string|in:only,include,exclude',  // ✅ NEW: trash filter
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

            $query = GeneratedContent::with('generation')->where('user_id', $user->id);

            // ✅ NEW: Filter by trash status (default: exclude trashed)
            $showTrashed = $request->input('show_trashed', 'exclude');
            switch ($showTrashed) {
                case 'only':
                    // Show only trashed songs
                    $query->where('is_trashed', true);
                    break;
                case 'include':
                    // Show both trashed and non-trashed songs
                    // No filter needed
                    break;
                case 'exclude':
                default:
                    // Show only non-trashed songs (default)
                    $query->where('is_trashed', false);
                    break;
            }

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
                    'generation_mode' => $content->generation?->mode,  // ✅ NEW: text_to_song, lyrics_to_song, instrumental
                    'status' => $content->status,
                    'prompt' => $content->prompt,
                    'mood' => $content->mood,
                    'genre' => $content->genre,
                    'language' => $content->language,
                    'duration' => $content->duration,
                    'instruments' => $content->instruments,
                    'content_urls' => [
                        'content_url' => $content->content_url,
                        'streaming_url' => $content->streaming_url,
                        'thumbnail_url' => $content->thumbnail_url,
                        'custom_thumbnail_url' => $content->custom_thumbnail_url,  // ✅ High-res Runware thumbnail
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(),  // ✅ Helper method
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                    ],
                    'thumbnail_info' => [  // ✅ Detailed thumbnail status
                        'status' => $content->thumbnail_generation_status,
                        'is_generating' => $content->isThumbnailGenerating(),
                        'has_custom' => $content->hasCustomThumbnail(),
                        'has_failed' => $content->hasThumbnailFailed(),
                        'retry_count' => $content->thumbnail_retry_count,
                        'completed_at' => $content->thumbnail_completed_at,
                    ],
                    'progress' => $this->calculateProgress($content),
                    'is_premium_generation' => $content->is_premium_generation,
                    'is_trashed' => $content->is_trashed,  // ✅ NEW: Trash status
                    'trashed_at' => $content->trashed_at,  // ✅ NEW: When trashed
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
     * Get full details of a single content item
     */
    public function show(Request $request, int $contentId): JsonResponse
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

            // Find the content with generation relationship
            $content = GeneratedContent::with('generation')
                ->where('id', $contentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found or not accessible'
                ], 404);
            }

            // Update last accessed time
            $content->update(['last_accessed_at' => now()]);

            // Return full details
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $content->id,
                    'task_id' => $content->topmediai_task_id,
                    'generation_id' => $content->generation_id,
                    'title' => $content->title,
                    'content_type' => $content->content_type,
                    'generation_mode' => $content->generation?->mode,  // ✅ text_to_song, lyrics_to_song, instrumental
                    'status' => $content->status,
                    'prompt' => $content->prompt,
                    'mood' => $content->mood,
                    'genre' => $content->genre,
                    'language' => $content->language,
                    'duration' => $content->duration,
                    'instruments' => $content->instruments,
                    'content_urls' => [
                        'content_url' => $content->content_url,
                        'streaming_url' => $content->streaming_url,
                        'thumbnail_url' => $content->thumbnail_url,
                        'custom_thumbnail_url' => $content->custom_thumbnail_url,
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(),
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                    ],
                    'thumbnail_info' => [
                        'status' => $content->thumbnail_generation_status,
                        'is_generating' => $content->isThumbnailGenerating(),
                        'has_custom' => $content->hasCustomThumbnail(),
                        'has_failed' => $content->hasThumbnailFailed(),
                        'retry_count' => $content->thumbnail_retry_count,
                        'completed_at' => $content->thumbnail_completed_at,
                    ],
                    'progress' => $this->calculateProgress($content),
                    'is_premium_generation' => $content->is_premium_generation,
                    'is_trashed' => $content->is_trashed,  // ✅ NEW: Trash status
                    'trashed_at' => $content->trashed_at,  // ✅ NEW: When trashed
                    'metadata' => $content->metadata,
                    'error_message' => $content->error_message,
                    'retry_count' => $content->retry_count,
                    'created_at' => $content->created_at,
                    'started_at' => $content->started_at,
                    'completed_at' => $content->completed_at,
                    'last_accessed_at' => $content->last_accessed_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Content show failed', [
                'error' => $e->getMessage(),
                'content_id' => $contentId,
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve content details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update song title
     * 
     * POST /api/content
     * Headers: id, device_id
     * Body: { "title": "New Title" }
     */
    public function updateTitle(Request $request): JsonResponse
    {
        try {
            // Get headers
            $contentId = $request->header('id');
            $deviceId = $request->header('device_id');
            
            // Validate headers
            if (!$contentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content ID is required in headers'
                ], 400);
            }
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required in headers'
                ], 400);
            }

            // Validate request body
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|min:1|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find user by device_id
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered. User does not exist.'
                ], 404);
            }

            // Find the content and verify ownership
            $content = GeneratedContent::where('id', $contentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song not found or you do not have permission to update it'
                ], 404);
            }

            // Update the title
            $oldTitle = $content->title;
            $newTitle = $request->input('title');
            
            $content->update([
                'title' => $newTitle
            ]);

            Log::info('Song title updated successfully', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'old_title' => $oldTitle,
                'new_title' => $newTitle
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Song title updated successfully',
                'data' => [
                    'id' => $content->id,
                    'title' => $content->title,
                    'old_title' => $oldTitle,
                    'updated_at' => $content->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Update title failed', [
                'error' => $e->getMessage(),
                'content_id' => $contentId ?? 'unknown',
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update song title',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete a song
     * 
     * POST /api/content/delete
     * Headers: id, device_id
     */
    public function deleteSong(Request $request): JsonResponse
    {
        try {
            // Get headers
            $contentId = $request->header('id');
            $deviceId = $request->header('device_id');
            
            // Validate headers
            if (!$contentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content ID is required in headers'
                ], 400);
            }
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required in headers'
                ], 400);
            }

            // Find user by device_id
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered. User does not exist.'
                ], 404);
            }

            // Find the content and verify ownership
            $content = GeneratedContent::where('id', $contentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song not found or you do not have permission to delete it'
                ], 404);
            }

            // Store info before deletion for logging
            $deletedSongInfo = [
                'id' => $content->id,
                'title' => $content->title,
                'content_type' => $content->content_type,
                'status' => $content->status,
                'created_at' => $content->created_at
            ];

            // Delete the content
            $content->delete();

            Log::info('Song deleted successfully', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'song_info' => $deletedSongInfo
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Song deleted successfully',
                'data' => [
                    'deleted_song' => $deletedSongInfo
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Delete song failed', [
                'error' => $e->getMessage(),
                'content_id' => $contentId ?? 'unknown',
                'device_id' => $deviceId ?? 'unknown',
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete song',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Move song to trash (soft delete)
     * 
     * POST /api/content/trash
     * Headers: id, device_id
     */
    public function moveToTrash(Request $request): JsonResponse
    {
        try {
            // Get headers
            $contentId = $request->header('id');
            $deviceId = $request->header('device_id');
            
            // Validate headers
            if (!$contentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content ID is required in headers'
                ], 400);
            }
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required in headers'
                ], 400);
            }

            // Find user by device_id
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered. User does not exist.'
                ], 404);
            }

            // Find the content and verify ownership
            $content = GeneratedContent::where('id', $contentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song not found or you do not have permission to trash it'
                ], 404);
            }

            // Check if already trashed
            if ($content->is_trashed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song is already in trash'
                ], 400);
            }

            // Move to trash
            $content->update([
                'is_trashed' => true,
                'trashed_at' => now()
            ]);

            Log::info('Song moved to trash', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'title' => $content->title,
                'trashed_at' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Song moved to trash successfully',
                'data' => [
                    'id' => $content->id,
                    'title' => $content->title,
                    'is_trashed' => $content->is_trashed,
                    'trashed_at' => $content->trashed_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Move to trash failed', [
                'error' => $e->getMessage(),
                'content_id' => $contentId ?? 'unknown',
                'device_id' => $deviceId ?? 'unknown',
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to move song to trash',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Restore song from trash
     * 
     * POST /api/content/restore
     * Headers: id, device_id
     */
    public function restoreFromTrash(Request $request): JsonResponse
    {
        try {
            // Get headers
            $contentId = $request->header('id');
            $deviceId = $request->header('device_id');
            
            // Validate headers
            if (!$contentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content ID is required in headers'
                ], 400);
            }
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required in headers'
                ], 400);
            }

            // Find user by device_id
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered. User does not exist.'
                ], 404);
            }

            // Find the content and verify ownership
            $content = GeneratedContent::where('id', $contentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song not found or you do not have permission to restore it'
                ], 404);
            }

            // Check if not in trash
            if (!$content->is_trashed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Song is not in trash'
                ], 400);
            }

            // Restore from trash
            $content->update([
                'is_trashed' => false,
                'trashed_at' => null
            ]);

            Log::info('Song restored from trash', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'title' => $content->title
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Song restored successfully',
                'data' => [
                    'id' => $content->id,
                    'title' => $content->title,
                    'is_trashed' => $content->is_trashed,
                    'restored_at' => now()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Restore from trash failed', [
                'error' => $e->getMessage(),
                'content_id' => $contentId ?? 'unknown',
                'device_id' => $deviceId ?? 'unknown',
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore song',
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
