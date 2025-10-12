<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\AiMusicUser;
use App\Models\Generation;
use App\Jobs\CheckTaskStatusJob;
use App\Jobs\ThumbnailGenerationJob;
use Illuminate\Support\Facades\Log;
use Exception;

class MusicGenerationService extends TopMediaiBaseService
{
    protected GenerationModeService $modeService;
    protected TitleGenerationService $titleService;
    protected ErrorHandlingService $errorService;

    public function __construct()
    {
        parent::__construct();
        $this->modeService = new GenerationModeService();
        $this->titleService = new TitleGenerationService();
        $this->errorService = new ErrorHandlingService();
    }

    /**
     * Generate music using TopMediai V3 API with mode detection
     */
    public function generateMusic(array $params): array
    {
        // PHASE 3: Detect generation mode
        $mode = $this->modeService->detectMode($params);
        
        Log::info('Generation mode detected', [
            'mode' => $mode,
            'description' => $this->modeService->getModeDescription($mode),
            'params_keys' => array_keys($params)
        ]);

        // PHASE 3: Validate parameters for the detected mode
        $validationErrors = $this->modeService->validateModeParams($params, $mode);
        if (!empty($validationErrors)) {
            throw new Exception('Validation failed: ' . implode(', ', $validationErrors));
        }
        
        // Validate user exists
        if (isset($params['user_id'])) {
            $user = AiMusicUser::find($params['user_id']);
            if (!$user) {
                throw new Exception('User not found');
            }
        }

        // PHASE 2: Create generation record first
        $generation = $this->createGeneration($params, $mode);

        // PHASE 3: Build TopMediai request based on mode
        $requestData = $this->modeService->buildTopMediaiRequest($params, $mode);
        
        Log::info('Starting music generation', [
            'generation_id' => $generation->generation_id,
            'mode' => $mode,
            'user_id' => $params['user_id'] ?? null,
            'request_data' => $requestData
        ]);

        // Record generation request
        $generationRequest = $this->recordGenerationRequest($params, $requestData, $generation);

        try {
            // Call TopMediai V3 music endpoint
            $response = $this->post(config('topmediai.endpoints.music'), $requestData);
            
            Log::info('TopMediai API Response', [
                'status' => $response['status'] ?? 'unknown',
                'has_data' => isset($response['data']) && !empty($response['data']),
                'generation_id' => $generation->generation_id
            ]);
            
            // Check TopMediai response status
            $topMediaiStatus = $response['status'] ?? 0;
            
            if ($topMediaiStatus === 200) {
                // SUCCESS: Extract task IDs and save them
                return $this->handleSuccessfulResponse($response, $generationRequest, $params, $generation);
            } else {
                // ERROR: Return user-friendly error message
                return $this->handleErrorResponse($response, $generationRequest, $topMediaiStatus);
            }
            
        } catch (Exception $e) {
            return $this->handleExceptionResponse($e, $generationRequest, $params);
        }
    }

    /**
     * PHASE 2: Create generation record
     */
    protected function createGeneration(array $params, string $mode): Generation
    {
        $user = null;
        if (isset($params['user_id'])) {
            $user = AiMusicUser::find($params['user_id']);
        }

        return Generation::create([
            'device_id' => $params['device_id'] ?? 'unknown',
            'user_id' => $user ? $user->id : null,
            'mode' => $mode,
            'request_data' => $params,
            'estimated_time' => $this->calculateEstimatedTime($params, $mode),
            'status' => 'processing',
            'task_count' => 2, // TopMediai always returns 2 tasks
        ]);
    }

    /**
     * Calculate realistic estimated time based on audio duration and complexity
     */
    protected function calculateEstimatedTime(array $params, string $mode): string
    {
        $duration = $params['duration'] ?? 120; // Audio duration in seconds
        $complexity = $this->getComplexityScore($params, $mode);
        
        // Base formula: 3-8x the audio duration depending on complexity
        $multiplier = 3 + ($complexity * 1.5); // 3x to 8x multiplier
        $estimatedSeconds = $duration * $multiplier;
        
        // Add server load buffer (40% extra for TopMediai variability)
        $bufferMultiplier = 1.4;
        $totalSeconds = $estimatedSeconds * $bufferMultiplier;
        
        $minutes = ceil($totalSeconds / 60);
        
        return match(true) {
            $minutes <= 3 => "2-3 minutes",
            $minutes <= 6 => "4-6 minutes", 
            $minutes <= 10 => "7-10 minutes",
            $minutes <= 15 => "10-15 minutes",
            default => "15+ minutes"
        };
    }

    /**
     * Calculate complexity score based on generation parameters
     */
    protected function getComplexityScore(array $params, string $mode): float
    {
        $score = 0.0;
        
        // Base complexity by mode
        $score += match($mode) {
            'instrumental' => 1.0,      // Simplest
            'text_to_song' => 2.0,      // Medium complexity
            'lyrics_to_song' => 3.0,    // Most complex
            default => 2.0
        };
        
        // Instrument count complexity
        $instrumentCount = count($params['instruments'] ?? []);
        $score += min($instrumentCount * 0.3, 2.0); // Max 2 points for instruments
        
        // Duration complexity (longer = more processing)
        $duration = $params['duration'] ?? 120;
        if ($duration > 180) {
            $score += 0.5; // 3+ minutes = more complex
        }
        if ($duration > 240) {
            $score += 0.5; // 4+ minutes = even more complex
        }
        
        // Quality/environment complexity
        $environment = $params['recording_environment'] ?? '';
        if (in_array($environment, ['studio', 'concert_hall'])) {
            $score += 0.5; // High quality = more processing
        }
        
        return min($score, 5.0); // Cap at 5.0 for max 8x multiplier
    }

    /**
     * Record generation request with generation link
     */
    protected function recordGenerationRequest(array $params, array $requestData, Generation $generation): GenerationRequest
    {
        return GenerationRequest::create([
            'user_id' => $params['user_id'] ?? null,
            'endpoint_used' => 'v3_music',
            'request_payload' => array_merge($requestData, [
                'generation_id' => $generation->generation_id,
                'mode' => $generation->mode
            ]),
            'status' => 'initiated',
            'device_id' => $params['device_id'] ?? null,
            'ip_address' => $params['ip_address'] ?? null,
            'user_agent' => $params['user_agent'] ?? null,
            'counted_towards_quota' => true,
            'is_premium_request' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Handle successful TopMediai response (status 200) - PHASE 2 Enhanced
     */
    protected function handleSuccessfulResponse(array $response, GenerationRequest $generationRequest, array $params, Generation $generation): array
    {
        // Extract task IDs from the response data
        $taskIds = [];
        if (isset($response['data']['ids']) && is_array($response['data']['ids'])) {
            $taskIds = $response['data']['ids'];
        }

        // REQUIREMENTS: Always expect exactly 2 task IDs
        if (count($taskIds) !== 2) {
            Log::warning('TopMediai returned unexpected number of task IDs', [
                'expected' => 2,
                'actual' => count($taskIds),
                'task_ids' => $taskIds,
                'generation_id' => $generation->generation_id
            ]);
        }

        // Store the task IDs in the generation request
        $taskIdsString = !empty($taskIds) ? implode(',', $taskIds) : null;
        
        // Update generation request with success
        $generationRequest->update([
            'topmediai_task_id' => $taskIdsString,
            'response_data' => $response,
            'status' => 'pending',
            'request_sent_at' => now(),
            'response_received_at' => now(),
        ]);

        // PHASE 2: Create 2 generated content records linked to generation
        $generatedContents = $this->createTaskRecords($generation, $taskIds, $params, $response);

        Log::info('Music generation started successfully', [
            'generation_id' => $generation->generation_id,
            'mode' => $generation->mode,
            'task_ids' => $taskIds,
            'task_count' => count($taskIds),
            'content_ids' => $generatedContents->pluck('id')->toArray()
        ]);

        // REQUIREMENTS: Return exact format specified with thumbnail data
        return [
            'success' => true,
            'message' => 'Music generation started successfully',
            'data' => [
                'generation_id' => $generation->id, // Use numeric ID for frontend
                'task_ids' => $taskIds, // Exactly 2 task IDs
                'estimated_time' => '2-3 minutes',
                'status' => 'processing',
                'content_type' => 'song',
                'songs' => $generatedContents->map(function ($content) {
                    return [
                        'id' => $content->id,
                        'task_id' => $content->topmediai_task_id,
                        'title' => $content->title,
                        'status' => $content->status,
                        'content_url' => null, // Not available yet
                        'streaming_url' => null, // Not available yet
                        'thumbnail_url' => null, // TopMediai thumbnail not available yet
                        'custom_thumbnail_url' => $content->custom_thumbnail_url, // Will be null initially
                        'best_thumbnail_url' => $content->getBestThumbnailUrl(), // Will be null initially
                        'thumbnail_status' => $content->thumbnail_generation_status, // Will be 'pending'
                        'thumbnail_info' => [
                            'status' => $content->thumbnail_generation_status,
                            'is_generating' => $content->isThumbnailGenerating(),
                            'has_custom' => $content->hasCustomThumbnail(),
                            'has_failed' => $content->hasThumbnailFailed(),
                            'retry_count' => $content->thumbnail_retry_count,
                            'completed_at' => $content->thumbnail_completed_at,
                        ],
                        'created_at' => $content->created_at->toISOString(),
                    ];
                })->toArray()
            ]
        ];
    }

    /**
     * PHASE 2: Create task records (2 tasks per generation)
     */
    protected function createTaskRecords(Generation $generation, array $taskIds, array $params, array $response): \Illuminate\Support\Collection
    {
        $contents = collect();

        foreach ($taskIds as $index => $taskId) {
            $content = GeneratedContent::create([
                'user_id' => $generation->user_id,
                'generation_id' => $generation->id, // Link to generation
                'title' => $this->titleService->generateTitle($params, $generation->mode, $index + 1),
                'content_type' => $generation->mode === 'instrumental' ? 'instrumental' : 'song',
                'topmediai_task_id' => $taskId,
                'status' => 'pending',
                'prompt' => $params['prompt'] ?? $params['lyrics'] ?? '',
                'mood' => $params['mood'] ?? null,
                'genre' => $params['genre'] ?? null,
                'instruments' => $params['instruments'] ?? null,
                'language' => $params['language'] ?? 'english',
                'duration' => $params['duration'] ?? null,
                // Add thumbnail fields
                'thumbnail_generation_status' => 'pending',
                'thumbnail_retry_count' => 0,
                'metadata' => [
                    'generation_id' => $generation->generation_id,
                    'mode' => $generation->mode,
                    'task_index' => $index + 1,
                    'topmediai_response' => $response,
                    'generation_request_id' => $generationRequest->id ?? null,
                    'auto_status_check_enabled' => true,
                    'job_scheduled_at' => now()->toISOString()
                ],
                'started_at' => now(),
                'is_premium_generation' => $params['is_premium'] ?? false,
            ]);

            // TASK 2: Schedule automatic status checking for this task
            $this->scheduleStatusCheck($taskId, $content->id);
            
            // 🎯 OPTION A: Generate thumbnail immediately when music starts processing
            $this->scheduleThumbnailGeneration($content, $params);

            $contents->push($content);
        }

        return $contents;
    }

    /**
     * TASK 2: Schedule automatic status checking for a task
     */
    protected function scheduleStatusCheck(string $taskId, int $contentId): void
    {
        Log::info('Scheduling automatic status check', [
            'task_id' => $taskId,
            'content_id' => $contentId,
            'initial_delay' => '30 seconds'
        ]);

        try {
            // Schedule the first status check for 30 seconds from now (aggressive detection)
            CheckTaskStatusJob::dispatch($taskId)
                ->delay(now()->addSeconds(30));

            Log::info('Status check job scheduled successfully', [
                'task_id' => $taskId,
                'content_id' => $contentId,
                'scheduled_for' => now()->addSeconds(30)->toISOString()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to schedule status check job', [
                'task_id' => $taskId,
                'content_id' => $contentId,
                'error' => $e->getMessage()
            ]);
            
            // Don't throw exception - generation should still succeed
            // Manual status checking will still work via API endpoints
        }
    }

    /**
     * Schedule thumbnail generation immediately when music generation starts
     */
    protected function scheduleThumbnailGeneration(GeneratedContent $content, array $params): void
    {
        $musicPrompt = $params['prompt'] ?? $params['lyrics'] ?? '';
        $genre = $params['genre'] ?? null;
        $mood = $params['mood'] ?? null;
        
        // Skip if no prompt available
        if (empty($musicPrompt)) {
            Log::warning('Skipping thumbnail generation: no prompt available', [
                'content_id' => $content->id
            ]);
            return;
        }
        
        try {
            // Get task index from content metadata (1 or 2)
            $taskIndex = ($content->metadata['task_index'] ?? 1);
            
            // Dispatch thumbnail generation job immediately with small delay
            ThumbnailGenerationJob::dispatch($content->id, $musicPrompt, $genre, $mood, $taskIndex)
                ->onQueue('thumbnails')
                ->delay(now()->addSeconds(5)); // Small delay to avoid overwhelming Runware API
                
            Log::info('Thumbnail generation scheduled', [
                'content_id' => $content->id,
                'prompt' => $musicPrompt,
                'genre' => $genre,
                'mood' => $mood,
                'task_index' => $taskIndex,
                'scheduled_for' => now()->addSeconds(5)->toISOString()
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to schedule thumbnail generation job', [
                'content_id' => $content->id,
                'error' => $e->getMessage(),
                'prompt' => $musicPrompt
            ]);
            
            // Don't throw exception - music generation should still succeed
            // Thumbnail can be retried manually later if needed
        }
    }

    /**
     * Handle TopMediai error response (non-200 status) - TASK 3: Enhanced Error Handling
     */
    protected function handleErrorResponse(array $response, GenerationRequest $generationRequest, int $topMediaiStatus): array
    {
        // TASK 3: Use ErrorHandlingService for user-friendly messages
        $errorResponse = $this->errorService->handleTopMediaiError($topMediaiStatus, $response, 'music_generation');

        // Update generation request with enhanced error details
        $generationRequest->update([
            'response_data' => $response,
            'status' => 'failed',
            'error_message' => $response['message'] ?? 'TopMediai API error',
            'error_code' => $errorResponse['error_code'],
            'request_sent_at' => now(),
            'response_received_at' => now(),
        ]);

        Log::warning('Music generation failed - TopMediai error (Enhanced)', [
            'generation_request_id' => $generationRequest->id,
            'topmediai_status' => $topMediaiStatus,
            'topmediai_message' => $response['message'] ?? 'Unknown error',
            'error_code' => $errorResponse['error_code'],
            'category' => $errorResponse['category'],
            'user_message' => $errorResponse['message']
        ]);

        // Return enhanced error response with all user-friendly details
        return $errorResponse;
    }

    /**
     * Handle exception during API call - TASK 3: Enhanced Error Handling
     */
    protected function handleExceptionResponse(Exception $e, GenerationRequest $generationRequest, array $params): array
    {
        // TASK 3: Use ErrorHandlingService for context-aware exception handling
        $errorResponse = $this->errorService->handleException($e, 'music_generation', [
            'user_id' => $params['user_id'] ?? null,
            'generation_request_id' => $generationRequest->id,
            'device_id' => $params['device_id'] ?? null
        ]);

        // Update generation request with enhanced error details
        $generationRequest->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'error_code' => $errorResponse['error_code'],
            'response_received_at' => now(),
        ]);

        Log::error('Music generation failed - Exception (Enhanced)', [
            'user_id' => $params['user_id'] ?? null,
            'generation_request_id' => $generationRequest->id,
            'error_code' => $errorResponse['error_code'],
            'category' => $errorResponse['category'],
            'exception_type' => get_class($e),
            'error' => $e->getMessage(),
            'user_message' => $errorResponse['message']
        ]);

        // Return enhanced error response with user-friendly details
        return $errorResponse;
    }

    // TASK 3: getRetryDelay method removed - now handled by ErrorHandlingService

    /**
     * Check if user can generate music (credit-based check)
     */
    public function canUserGenerate(int $userId): array
    {
        $user = AiMusicUser::find($userId);
        if (!$user) {
            return [
                'can_generate' => false,
                'reason' => 'User not found'
            ];
        }

        $totalCredits = $user->totalCredits();
        
        if ($totalCredits <= 0) {
            return [
                'can_generate' => false,
                'reason' => 'No credits remaining',
                'subscription_credits' => $user->subscription_credits,
                'addon_credits' => $user->addon_credits,
                'total_credits' => $totalCredits,
                'has_active_subscription' => $user->hasActiveSubscription()
            ];
        }

        return [
            'can_generate' => true,
            'reason' => 'Sufficient credits available',
            'subscription_credits' => $user->subscription_credits,
            'addon_credits' => $user->addon_credits,
            'total_credits' => $totalCredits,
            'has_active_subscription' => $user->hasActiveSubscription()
        ];
    }

    /**
     * Decrement user credits after successful generation
     */
    public function decrementUserCredits(int $userId, int $amount = 1): bool
    {
        $user = AiMusicUser::find($userId);
        if (!$user) {
            return false;
        }

        if ($user->decrementCredits($amount)) {
            $user->update(['last_active_at' => now()]);
            return true;
        }

        return false;
    }

    /**
     * Increment user usage count (backward compatibility - deprecated)
     */
    public function incrementUsage(int $userId): void
    {
        // For backward compatibility, treat as 1 credit decrement
        $this->decrementUserCredits($userId, 1);
    }

    /**
     * Get user's generation history
     */
    public function getUserGenerations(int $userId, int $limit = 20): array
    {
        $generations = Generation::where('user_id', $userId)
            ->with('tasks')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $generations->map(function ($generation) {
            return [
                'generation_id' => $generation->generation_id,
                'mode' => $generation->mode,
                'status' => $generation->status,
                'task_count' => $generation->tasks->count(),
                'completed_tasks' => $generation->tasks->where('status', 'completed')->count(),
                'created_at' => $generation->created_at,
                'estimated_time' => $generation->estimated_time,
            ];
        })->toArray();
    }

    /**
     * Get generation statistics for user
     */
    public function getUserStats(int $userId): array
    {
        $user = AiMusicUser::find($userId);
        if (!$user) {
            return [];
        }

        $totalGenerations = Generation::where('user_id', $userId)->count();
        $completedGenerations = Generation::where('user_id', $userId)
            ->where('status', 'completed')->count();
        $failedGenerations = Generation::where('user_id', $userId)
            ->where('status', 'failed')->count();

        return [
            'total_generations' => $totalGenerations,
            'completed_generations' => $completedGenerations,
            'failed_generations' => $failedGenerations,
            'success_rate' => $totalGenerations > 0 ? round(($completedGenerations / $totalGenerations) * 100, 2) : 0,
            'subscription_credits' => $user->subscription_credits,
            'addon_credits' => $user->addon_credits,
            'total_credits' => $user->totalCredits(),
            'has_active_subscription' => $user->hasActiveSubscription(),
            'subscription_expires_at' => $user->subscription_expires_at,
            'last_active_at' => $user->last_active_at,
        ];
    }
}