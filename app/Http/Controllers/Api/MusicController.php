<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Models\Generation;
use App\Models\GeneratedContent;
use App\Services\MusicGenerationService;
use App\Services\GenerationModeService;
use App\Services\LyricsGenerationService;
use App\Services\SingerService;
use App\Services\ConversionService;
use App\Services\TaskStatusService;
use App\Jobs\CheckTaskStatusJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MusicController extends Controller
{
    protected MusicGenerationService $musicService;
    protected GenerationModeService $modeService;
    protected LyricsGenerationService $lyricsService;
    protected SingerService $singerService;
    protected ConversionService $conversionService;
    protected TaskStatusService $taskStatusService;

    public function __construct(
        MusicGenerationService $musicService,
        LyricsGenerationService $lyricsService,
        SingerService $singerService,
        ConversionService $conversionService,
        TaskStatusService $taskStatusService
    ) {
        $this->musicService = $musicService;
        $this->modeService = new GenerationModeService();
        $this->lyricsService = $lyricsService;
        $this->singerService = $singerService;
        $this->conversionService = $conversionService;
        $this->taskStatusService = $taskStatusService;
    }

    /**
     * PHASE 3: Generate music with mode detection and validation
     */
    public function generateMusic(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // PHASE 3: Detect mode first to get appropriate validation rules
            $mode = $this->modeService->detectMode($request->all());
            $validationRules = $this->modeService->getValidationRules($mode);

            Log::info('Music generation request', [
                'device_id' => $deviceId,
                'detected_mode' => $mode,
                'mode_description' => $this->modeService->getModeDescription($mode)
            ]);

            // PHASE 3: Validate with mode-specific rules
            $validator = Validator::make($request->all(), $validationRules);

            if ($validator->fails()) {
                Log::warning('Music generation validation failed', [
                    'device_id' => $deviceId,
                    'mode' => $mode,
                    'errors' => $validator->errors()->toArray(),
                    'request_data' => $request->except(['lyrics']) // Exclude lyrics to avoid logging sensitive data
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'detected_mode' => $mode,
                    'mode_description' => $this->modeService->getModeDescription($mode)
                ], 422);
            }

            // PHASE 2: Auto-register device if not found
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, [
                'platform' => $request->input('platform') ?? $request->header('X-Platform') ?? 'unknown',
                'app_version' => $request->input('version') ?? $request->header('X-App-Version') ?? 'unknown',
                'device_model' => $request->input('model') ?? $request->header('X-Device-Model') ?? 'unknown',
                'os_version' => $request->input('os_version') ?? $request->header('X-OS-Version') ?? 'unknown',
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'auto_created_at' => now()->toISOString(),
            ]);

            // Check if user can generate
            $canGenerate = $this->musicService->canUserGenerate($user->id);
            if (!$canGenerate['can_generate']) {
                return response()->json([
                    'success' => false,
                    'message' => $canGenerate['reason'],
                    'data' => $canGenerate
                ], 403);
            }

            // Prepare parameters for generation
            $params = array_merge($request->all(), [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // PHASE 3: Generate music with mode detection
            $result = $this->musicService->generateMusic($params);

            // Decrement user credits if successful
            if ($result['success']) {
                $this->musicService->decrementUserCredits($user->id, 2);
            }

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Music generation failed in controller', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Can you try again', // PHASE 1: Consistent error message
                'error_code' => 'GENERATION_FAILED'
            ], 500);
        }
    }

    /**
     * Generate lyrics using TopMediai V1 API
     */
    public function generateLyrics(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'prompt' => 'required|string|min:10', // No max limit - TopMediai accepts long lyrics
                'genre' => 'nullable|string|max:50',
                'mood' => 'nullable|string|max:50',
                'language' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Auto-register device if not found
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, [
                'platform' => 'auto',
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'auto_created_at' => now()->toISOString(),
            ]);

            // Prepare parameters
            $params = array_merge($request->all(), [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Generate lyrics
            $result = $this->lyricsService->generateLyrics($params);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Lyrics generation failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Can you try again',
                'error_code' => 'LYRICS_GENERATION_FAILED'
            ], 500);
        }
    }

    /**
     * Add vocals to existing music using TopMediai V3 Singer API
     */
    public function addVocals(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'content_id' => 'required|integer|exists:generated_content,id',
                'singer_id' => 'required|string',
                'lyrics' => 'nullable|string', // No max limit - TopMediai accepts long lyrics
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Auto-register device if not found
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, [
                'platform' => 'auto',
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'auto_created_at' => now()->toISOString(),
            ]);

            // Prepare parameters
            $params = array_merge($request->all(), [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Add vocals using correct method name
            $result = $this->singerService->generateSinger($params);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Vocal addition failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Can you try again',
                'error_code' => 'VOCAL_ADDITION_FAILED'
            ], 500);
        }
    }

    /**
     * Smart retry for failed generations - checks current status before creating new generation
     * Handles both songs from the same task properly
     */
    public function smartRetryGeneration(Request $request, $contentId): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Find the specific content with user relationship
            $content = GeneratedContent::with('user')->findOrFail($contentId);
            
            Log::info('Smart retry initiated', [
                'content_id' => $contentId,
                'task_id' => $content->topmediai_task_id,
                'generation_id' => $content->generation_id,
                'device_id' => $deviceId,
                'current_status' => $content->status
            ]);
            
            // SCENARIO 1: PRE-CHECK - If content already has URLs in DB, fix status immediately
            if ($content->content_url && $content->status !== 'completed') {
                Log::info('Smart retry: Scenario 1 - Local DB has URLs, fixing status', [
                    'content_id' => $contentId,
                    'current_status' => $content->status,
                    'has_content_url' => !empty($content->content_url),
                    'has_duration' => !empty($content->duration)
                ]);

                // Update status to completed since we have the content URLs
                $content->update([
                    'status' => 'completed',
                    'completed_at' => $content->completed_at ?? now(),
                    'metadata' => array_merge($content->metadata ?? [], [
                        'smart_retry_status_fix' => now()->toISOString(),
                        'previous_status' => $content->status,
                        'scenario' => 'local_db_has_urls'
                    ])
                ]);

                // Return immediately - no need to check API
                return response()->json([
                    'success' => true,
                    'action' => 'already_completed',
                    'message' => 'Great! Your song is now ready!',
                    'data' => [
                        'id' => $content->id,
                        'user_id' => $content->user_id,
                        'generation_id' => $content->generation_id,
                        'title' => $content->title,
                        'content_type' => $content->content_type,
                        'topmediai_task_id' => $content->topmediai_task_id,
                        'topmediai_song_id' => $content->topmediai_song_id,
                        'status' => 'completed',
                        'prompt' => $content->prompt,
                        'mood' => $content->mood,
                        'genre' => $content->genre,
                        'instruments' => $content->instruments,
                        'language' => $content->language,
                        'duration' => $content->duration,
                        'content_url' => $content->content_url,
                        'streaming_url' => null, // Always null when completed
                        'thumbnail_url' => $content->thumbnail_url,
                        'custom_thumbnail_url' => $content->custom_thumbnail_url,  // ✅ High-res Runware thumbnail
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(),  // ✅ Helper method
                        'thumbnail_status' => $content->thumbnail_generation_status,  // ✅ Thumbnail status
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                        'metadata' => $content->metadata,
                        'created_at' => $content->created_at->toISOString(),
                        'updated_at' => $content->updated_at->toISOString(),
                        'completed_at' => $content->completed_at?->toISOString(),
                        'fixed_status' => true,
                        'scenario' => 'local_db_fix',
                        'ready_at' => now()->toISOString()
                    ]
                ]);
            }
            
            if (!in_array($content->status, ['failed', 'pending', 'processing'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed, pending, or processing content can be retried'
                ], 400);
            }

            // Verify ownership by device_id through user relationship
            $contentUser = $content->user;
            if (!$contentUser || $contentUser->device_id !== $deviceId) {
                Log::warning('Device ID mismatch for smart retry', [
                    'content_id' => $contentId,
                    'request_device_id' => $deviceId,
                    'content_user_device_id' => $contentUser->device_id ?? 'null',
                    'content_status' => $content->status,
                    'user_exists' => $contentUser ? 'yes' : 'no'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found or access denied'
                ], 404);
            }

            // SCENARIO 2: Check TopMediai API status for this specific song
            $singleTaskStatus = $this->checkSingleTaskStatusFromTopMediai($content->topmediai_task_id);

            // Check if TopMediai shows this song as truly completed
            $isTopMediaiCompleted = (
                $singleTaskStatus['status'] === 0 && // TopMediai status = completed
                $singleTaskStatus['duration'] !== -1 && // Duration calculated
                !empty($singleTaskStatus['audio_url']) && // Audio URL exists
                !empty($singleTaskStatus['cover_url']) // Thumbnail exists
            );

            if ($isTopMediaiCompleted) {
                Log::info('Smart retry: Scenario 2 - TopMediai shows song completed, updating single song', [
                    'content_id' => $contentId,
                    'task_id' => $content->topmediai_task_id,
                    'topmediai_status' => $singleTaskStatus['status'],
                    'duration' => $singleTaskStatus['duration']
                ]);

                // Update ONLY this single song to completed
                $content->update([
                    'status' => 'completed',
                    'content_url' => $singleTaskStatus['audio_url'],
                    'thumbnail_url' => $singleTaskStatus['cover_url'], 
                    'duration' => $singleTaskStatus['duration'],
                    'completed_at' => now(),
                    'metadata' => array_merge($content->metadata ?? [], [
                        'smart_retry_api_completion' => now()->toISOString(),
                        'topmediai_task_data' => $singleTaskStatus,
                        'scenario' => 'single_song_api_check'
                    ])
                ]);

                return response()->json([
                    'success' => true,
                    'action' => 'single_song_completed',
                    'message' => 'Great! Your song is now ready!',
                    'data' => [
                        'id' => $content->id,
                        'user_id' => $content->user_id,
                        'generation_id' => $content->generation_id,
                        'title' => $content->title,
                        'content_type' => $content->content_type,
                        'topmediai_task_id' => $content->topmediai_task_id,
                        'topmediai_song_id' => $content->topmediai_song_id,
                        'status' => 'completed',
                        'prompt' => $content->prompt,
                        'mood' => $content->mood,
                        'genre' => $content->genre,
                        'instruments' => $content->instruments,
                        'language' => $content->language,
                        'duration' => $singleTaskStatus['duration'],
                        'content_url' => $singleTaskStatus['audio_url'],
                        'streaming_url' => null, // Always null when completed
                        'thumbnail_url' => $singleTaskStatus['cover_url'],
                        'custom_thumbnail_url' => $content->custom_thumbnail_url,  // ✅ High-res Runware thumbnail
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(),  // ✅ Helper method
                        'thumbnail_status' => $content->thumbnail_generation_status,  // ✅ Thumbnail status
                        'download_url' => $content->download_url,
                        'preview_url' => $content->preview_url,
                        'metadata' => $content->metadata,
                        'created_at' => $content->created_at->toISOString(),
                        'updated_at' => $content->updated_at->toISOString(),
                        'completed_at' => $content->completed_at?->toISOString(),
                        'scenario' => 'single_song_completed',
                        'topmediai_verification' => true,
                        'ready_at' => now()->toISOString()
                    ]
                ]);
            }

            // SCENARIO 3: Song is still processing/failed - need to regenerate entire generation
            Log::info('Smart retry: Scenario 3 - Song not completed, regenerating entire generation', [
                'content_id' => $contentId,
                'task_id' => $content->topmediai_task_id,
                'generation_id' => $content->generation_id,
                'topmediai_status' => $singleTaskStatus['status'],
                'duration' => $singleTaskStatus['duration']
            ]);

            // Find all songs with the same generation_id (typically 2 songs)
            $allSongsInGeneration = GeneratedContent::where('generation_id', $content->generation_id)->get();

            return $this->startNewGenerationForRetry($content, $request, $allSongsInGeneration);

        } catch (\Exception $e) {
            Log::error('Smart retry failed', [
                'content_id' => $contentId,
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Smart retry failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to start a new generation during a smart retry.
     * Deletes all old failed songs from the generation and creates fresh generation.
     */
    private function startNewGenerationForRetry(GeneratedContent $content, Request $request, $allSongsInGeneration = null): JsonResponse
    {
        // If allSongsInGeneration is not provided, fetch them by generation_id
        if ($allSongsInGeneration === null) {
            $allSongsInGeneration = GeneratedContent::where('generation_id', $content->generation_id)->get();
        }

        Log::info('Smart retry: Creating new generation for failed content', [
            'content_id' => $content->id,
            'generation_id' => $content->generation_id,
            'songs_to_delete' => $allSongsInGeneration->pluck('id')->toArray(),
            'task_ids_to_delete' => $allSongsInGeneration->pluck('topmediai_task_id')->toArray()
        ]);

        $user = $content->user;

        // Check if user can generate
        $canGenerate = $this->musicService->canUserGenerate($user->id);
        if (!$canGenerate['can_generate']) {
            return response()->json([
                'success' => false,
                'message' => $canGenerate['reason'],
                'data' => $canGenerate
            ], 403);
        }

        $originalParams = $this->extractOriginalParams($content, $user, $request);
        
        // Store metadata about the deleted songs for logging
        $deletedSongsInfo = $allSongsInGeneration->map(function ($song) {
            return [
                'id' => $song->id,
                'topmediai_task_id' => $song->topmediai_task_id,
                'topmediai_song_id' => $song->topmediai_song_id,
                'title' => $song->title,
                'status' => $song->status,
                'created_at' => $song->created_at->toISOString()
            ];
        })->toArray();

        // DELETE all old failed songs from the generation
        $deletedCount = GeneratedContent::where('generation_id', $content->generation_id)->delete();
        
        Log::info('Smart retry: Deleted old failed generation', [
            'generation_id' => $content->generation_id,
            'deleted_count' => $deletedCount,
            'deleted_songs' => $deletedSongsInfo
        ]);

        // Generate new music
        $result = $this->musicService->generateMusic($originalParams);

        // Decrement user credits if successful
        if ($result['success']) {
            $this->musicService->decrementUserCredits($user->id, 2);
            
            Log::info('Smart retry: New generation started successfully', [
                'old_generation_id' => $content->generation_id,
                'new_generation_id' => $result['generation_request_id'] ?? null,
                'new_task_ids' => $result['task_ids'] ?? [],
                'deleted_songs_count' => $deletedCount
            ]);
        }

        return response()->json([
            'success' => true,
            'action' => 'new_generation',
            'message' => 'Starting new generation...',
            'data' => array_merge($result, [
                'deleted_old_songs' => $deletedCount,
                'deleted_song_ids' => $allSongsInGeneration->pluck('id')->toArray(),
                'retry_info' => [
                    'original_generation_id' => $content->generation_id,
                    'retry_reason' => 'smart_retry_with_cleanup'
                ]
            ])
        ]);
    }

    /**
     * Check single task status from TopMediai API for Scenario 2
     */
    private function checkSingleTaskStatusFromTopMediai(string $taskId): array
    {
        try {
            Log::info('Checking single task status from TopMediai for Scenario 2', [
                'task_id' => $taskId
            ]);

            // Call TopMediai API directly for this single task
            $statusResponse = $this->taskStatusService->checkTaskStatus($taskId);

            if (isset($statusResponse['data']) && !empty($statusResponse['data'])) {
                // Get the first song data (we're checking single task)
                $songData = $statusResponse['data'][0];
                
                Log::info('Single task status retrieved from TopMediai', [
                    'task_id' => $taskId,
                    'status' => $songData['status'] ?? 'unknown',
                    'duration' => $songData['duration'] ?? -1,
                    'has_audio_url' => !empty($songData['audio_url']),
                    'has_cover_url' => !empty($songData['cover_url'])
                ]);

                return [
                    'task_id' => $taskId,
                    'song_id' => $songData['song_id'] ?? null,
                    'title' => $songData['title'] ?? null,
                    'status' => $songData['status'] ?? 2, // TopMediai status code
                    'duration' => $songData['duration'] ?? -1,
                    'audio_url' => $songData['audio_url'] ?? null,
                    'cover_url' => $songData['cover_url'] ?? null,
                    'fail_code' => $songData['fail_code'] ?? null,
                    'fail_reason' => $songData['fail_reason'] ?? null
                ];
                
            } else {
                Log::warning('No task data found in TopMediai response', [
                    'task_id' => $taskId,
                    'response' => $statusResponse
                ]);

                return [
                    'task_id' => $taskId,
                    'status' => 1, // Failed status
                    'duration' => -1,
                    'audio_url' => null,
                    'cover_url' => null,
                    'error' => 'Task not found in TopMediai response'
                ];
            }

        } catch (\Exception $e) {
            Log::error('Single task status check from TopMediai failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            return [
                'task_id' => $taskId,
                'status' => 1, // Failed status
                'duration' => -1,
                'audio_url' => null,
                'cover_url' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check task status from TopMediai API and handle both songs properly (DEPRECATED)
     */
    private function checkTaskStatusFromTopMediai(string $taskId): array
    {
        try {
            Log::info('Checking task status from TopMediai', [
                'task_id' => $taskId
            ]);

            // Call TopMediai API directly for this task
            $statusResponse = $this->taskStatusService->checkTaskStatus($taskId);

            if (isset($statusResponse['data']) && !empty($statusResponse['data'])) {
                $songs = [];
                $overallStatus = 'completed';
                $streamingUrl = null;
                
                // Process each song in the response (TopMediai returns array of songs)
                foreach ($statusResponse['data'] as $songData) {
                    $songs[] = [
                        'song_id' => $songData['song_id'] ?? null,
                        'title' => $songData['title'] ?? null,
                        'audio_url' => $songData['audio_url'] ?? null,
                        'cover_url' => $songData['cover_url'] ?? null,
                        'duration' => $songData['duration'] ?? -1,
                        'status' => $songData['status'] ?? 2,
                        'fail_code' => $songData['fail_code'] ?? null,
                        'fail_reason' => $songData['fail_reason'] ?? null
                    ];
                    
                    // Determine overall status based on TopMediai status codes
                    $songStatus = $this->mapTopMediaiStatus($songData['status'] ?? 2);
                    
                    // If any song is still processing or failed, update overall status
                    if ($songStatus === 'processing' || $songStatus === 'pending') {
                        $overallStatus = 'processing';
                        // Use streaming URL if available
                        if (!empty($songData['audio_url'])) {
                            $streamingUrl = $songData['audio_url'];
                        }
                    } elseif ($songStatus === 'failed') {
                        $overallStatus = 'failed';
                    }
                }
                
                Log::info('Task status retrieved from TopMediai', [
                    'task_id' => $taskId,
                    'overall_status' => $overallStatus,
                    'songs_count' => count($songs),
                    'has_streaming_url' => !empty($streamingUrl)
                ]);

                return [
                    'task_id' => $taskId,
                    'overall_status' => $overallStatus,
                    'songs' => $songs,
                    'streaming_url' => $streamingUrl,
                    'raw_response' => $statusResponse
                ];
                
            } else {
                Log::warning('No task data found in TopMediai response', [
                    'task_id' => $taskId,
                    'response' => $statusResponse
                ]);

                return [
                    'task_id' => $taskId,
                    'overall_status' => 'failed',
                    'songs' => [],
                    'streaming_url' => null,
                    'error' => 'Task not found in TopMediai response'
                ];
            }

        } catch (\Exception $e) {
            Log::error('Task status check from TopMediai failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            return [
                'task_id' => $taskId,
                'overall_status' => 'failed',
                'songs' => [],
                'streaming_url' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Map TopMediai status codes to our internal status
     */
    private function mapTopMediaiStatus(int $statusCode): string
    {
        switch ($statusCode) {
            case 0:
                return 'completed';
            case 1:
                return 'failed';
            case 2:
                return 'processing';
            default:
                return 'unknown';
        }
    }

    /**
     * Check single task status directly from TopMediai API (SIMPLIFIED APPROACH - DEPRECATED)
     */
    private function checkSingleTaskStatus(string $taskId): array
    {
        try {
            Log::info('Checking single task status', [
                'task_id' => $taskId
            ]);

            // Call TopMediai API directly for this single task
            $statusResponse = $this->taskStatusService->checkTaskStatus($taskId);

            if (isset($statusResponse['tasks']) && !empty($statusResponse['tasks'])) {
                $taskData = $statusResponse['tasks'][0]; // Get the first (and only) task
                
                Log::info('Single task status retrieved', [
                    'task_id' => $taskId,
                    'status' => $taskData['status'] ?? 'unknown',
                    'has_audio_url' => !empty($taskData['audio_url'])
                ]);

                return $taskData;
            } else {
                Log::warning('No task data found in response', [
                    'task_id' => $taskId,
                    'response' => $statusResponse
                ]);

                return [
                    'task_id' => $taskId,
                    'status' => 'failed',
                    'error' => 'Task not found in TopMediai response'
                ];
            }

        } catch (\Exception $e) {
            Log::error('Single task status check failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            return [
                'task_id' => $taskId,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check current generation status by triggering fresh status checks (DEPRECATED - keeping for compatibility)
     */
    private function checkGenerationStatus(string $generationId): array
    {
        
        try {
            $generation = Generation::where('generation_id', $generationId)->firstOrFail();
            dd($generation); 
            // Get all task IDs for this generation
            $taskIds = $generation->tasks()->pluck('task_id')->toArray();
            
            if (empty($taskIds)) {
                Log::warning('No tasks found for generation', ['generation_id' => $generationId]);
                return [
                    'generation_id' => $generationId,
                    'overall_status' => 'failed',
                    'tasks' => [],
                    'error' => 'No tasks found'
                ];
            }

            // Trigger fresh status check from TopMediai
            $statusResponse = $this->taskStatusService->checkMultipleTaskStatus($taskIds);
            
            // Wait for database updates to complete and refresh
            sleep(1); // Give time for async updates
            $generation->refresh();
            $generation->load('tasks'); // Reload tasks with fresh data
            
            // Force status recalculation
            $generation->updateStatus();
            $generation->refresh();
            
            Log::info('Fresh status check completed', [
                'generation_id' => $generationId,
                'task_count' => count($taskIds),
                'old_status_response' => $statusResponse,
                'updated_generation_status' => $generation->status,
                'task_statuses' => $generation->tasks->pluck('status', 'task_id')->toArray()
            ]);

            return [
                'generation_id' => $generationId,
                'overall_status' => $generation->status,
                'estimated_time' => $generation->estimated_time,
                'tasks' => $generation->tasks->map(function ($task) {
                    return [
                        'task_id' => $task->task_id,
                        'status' => $task->status,
                        'title' => $task->title,
                        'audio_url' => $task->content_url,
                        'streaming_url' => $task->streaming_url,
                        'thumbnail_url' => $task->thumbnail_url,
                        'duration' => $task->duration,
                        'progress' => $task->progress ?? 0
                    ];
                })->toArray(),
                'status_check_result' => $statusResponse
            ];

        } catch (\Exception $e) {
            Log::error('Status check failed', [
                'generation_id' => $generationId,
                'error' => $e->getMessage()
            ]);

            return [
                'generation_id' => $generationId,
                'overall_status' => 'failed',
                'tasks' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract original parameters from failed content for retry
     */
    private function extractOriginalParams(GeneratedContent $failedContent, AiMusicUser $user, Request $request): array
    {
        // Get the original generation parameters
        $generation = $failedContent->generation;
        $originalRequestData = $generation->request_data ?? [];

        // Build retry parameters with original values
        $params = [
            'user_id' => $user->id,
            'device_id' => $user->device_id, // Fixed: Use user's device_id instead of failedContent->device_id
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            
            // Core generation parameters
            'prompt' => $failedContent->prompt ?? $originalRequestData['prompt'] ?? '',
            'mood' => $failedContent->mood ?? $originalRequestData['mood'] ?? null,
            'genre' => $failedContent->genre ?? $originalRequestData['genre'] ?? null,
            'duration' => $failedContent->duration ?? $originalRequestData['duration'] ?? 120,
            'language' => $failedContent->language ?? $originalRequestData['language'] ?? 'english',
            
            // Advanced parameters from original request
            'is_instrumental' => $originalRequestData['is_instrumental'] ?? false,
            'instruments' => $originalRequestData['instruments'] ?? [],
            'vocals' => $originalRequestData['vocals'] ?? null,
            'gender' => $originalRequestData['gender'] ?? null,
            'recording_environment' => $originalRequestData['recording_environment'] ?? null,
            'tempo' => $originalRequestData['tempo'] ?? null,
            'lyrics' => $originalRequestData['lyrics'] ?? null,
            
            // Retry metadata
            'is_retry' => true,
            'original_generation_id' => $generation->generation_id,
            'original_content_id' => $failedContent->id,
            'retry_timestamp' => now()->toISOString()
        ];

        Log::info('Extracted retry parameters', [
            'original_generation_id' => $generation->generation_id,
            'original_content_id' => $failedContent->id,
            'mode' => $generation->mode,
            'has_prompt' => !empty($params['prompt']),
            'has_lyrics' => !empty($params['lyrics']),
            'duration' => $params['duration'],
            'device_id' => $params['device_id'], // Added: Log device_id for debugging
            'user_id' => $params['user_id']
        ]);

        return $params;
    }
}