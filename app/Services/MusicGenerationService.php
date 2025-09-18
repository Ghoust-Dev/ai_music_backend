<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\AiMusicUser;
use Illuminate\Support\Facades\Log;
use Exception;

class MusicGenerationService extends TopMediaiBaseService
{
    /**
     * Generate music using TopMediai V3 API
     */
    public function generateMusic(array $params): array
    {
        // Validate required parameters
        $this->validateRequired($params, ['prompt']);
        
        // Validate user exists
        if (isset($params['user_id'])) {
            $user = AiMusicUser::find($params['user_id']);
            if (!$user) {
                throw new Exception('User not found');
            }
        }

        // Prepare request data for TopMediai V3
        $requestData = $this->prepareRequestData($params);
        
        Log::info('Starting music generation', [
            'user_id' => $params['user_id'] ?? null,
            'prompt' => $params['prompt'],
            'request_data' => $requestData
        ]);

        // Record generation request
        $generationRequest = $this->recordGenerationRequest($params, $requestData);

        try {
            // Call TopMediai V3 music endpoint
            $response = $this->post(config('topmediai.endpoints.music'), $requestData);
            
            Log::info('TopMediai API Response', [
                'status' => $response['status'] ?? 'unknown',
                'has_data' => isset($response['data']) && !empty($response['data']),
                'generation_request_id' => $generationRequest->id
            ]);
            
            // Check TopMediai response status
            $topMediaiStatus = $response['status'] ?? 0;
            
            if ($topMediaiStatus === 200) {
                // SUCCESS: Extract task IDs and save them
                return $this->handleSuccessfulResponse($response, $generationRequest, $params);
            } else {
                // ERROR: Return user-friendly error message
                return $this->handleErrorResponse($response, $generationRequest, $topMediaiStatus);
            }
            
        } catch (Exception $e) {
            return $this->handleExceptionResponse($e, $generationRequest, $params);
        }
    }

    /**
     * Prepare request data for TopMediai V3
     */
    protected function prepareRequestData(array $params): array
    {
        // Based on TopMediai V3 official documentation
        // https://docs.topmediai.com/api-reference/ai-music-generator/v3-generate-music
        
        // For auto generation (style-based)
        if (isset($params['use_auto']) && $params['use_auto']) {
            $requestData = [
                'action' => 'auto',
                'style' => $params['prompt'], // Use prompt as style description
                'mv' => $params['model_version'] ?? 'v4.0',
                'instrumental' => isset($params['is_instrumental']) && $params['is_instrumental'] ? 1 : 0,
                'gender' => $params['gender'] ?? 'male'
            ];
        } else {
            // For custom generation (lyrics + style)
            $requestData = [
                'action' => 'custom',
                'style' => $this->generateStyleFromPrompt($params),
                'lyrics' => $params['lyrics'] ?? $params['prompt'],
                'mv' => $params['model_version'] ?? 'v4.0',
                'instrumental' => isset($params['is_instrumental']) && $params['is_instrumental'] ? 1 : 0,
                'gender' => $params['gender'] ?? 'male'
            ];
        }

        Log::info('TopMediai Request Data (Official Format)', $requestData);

        return $requestData;
    }

    /**
     * Generate style description from prompt, mood, and genre
     */
    protected function generateStyleFromPrompt(array $params): string
    {
        $style = $params['prompt'];
        
        if (isset($params['mood']) && !empty($params['mood'])) {
            $style .= " in a " . $params['mood'] . " mood";
        }
        
        if (isset($params['genre']) && !empty($params['genre'])) {
            $style .= " in " . $params['genre'] . " style";
        }
        
        return $style;
    }

    /**
     * Record generation request in database
     */
    protected function recordGenerationRequest(array $params, array $requestData): GenerationRequest
    {
        return GenerationRequest::create([
            'user_id' => $params['user_id'] ?? null,
            'endpoint_used' => 'v3_music',
            'request_payload' => $requestData,
            'status' => 'initiated',
            'device_id' => $params['device_id'] ?? null,
            'ip_address' => $params['ip_address'] ?? null,
            'user_agent' => $params['user_agent'] ?? null,
            'counted_towards_quota' => true,
            'is_premium_request' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Create generated content record
     */
    protected function createGeneratedContent(array $params, array $response, GenerationRequest $generationRequest): GeneratedContent
    {
        // Extract task ID from various possible response keys
        $taskId = $response['task_id'] ?? $response['id'] ?? $response['taskId'] ?? $response['request_id'] ?? null;
        
        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $params['title'] ?? $this->generateTitle($params['prompt']),
            'content_type' => $params['content_type'] ?? 'song',
            'topmediai_task_id' => $taskId ?? 'unknown_' . uniqid(),  // Fallback ID if no task_id
            'status' => 'pending',  // Always use 'pending' as it's a valid enum value
            'prompt' => $params['prompt'],
            'mood' => $params['mood'] ?? null,
            'genre' => $params['genre'] ?? null,
            'instruments' => isset($params['instruments']) ? $params['instruments'] : null,
            'language' => $params['language'] ?? 'english',
            'duration' => $params['duration'] ?? null,
            'metadata' => $response,  // Store the full response for debugging
            'started_at' => now(),
            'is_premium_generation' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Generate a title from the prompt
     */
    protected function generateTitle(string $prompt): string
    {
        // Simple title generation from prompt
        $title = ucwords(strtolower($prompt));
        
        // Limit to 50 characters
        if (strlen($title) > 50) {
            $title = substr($title, 0, 47) . '...';
        }

        return $title;
    }

    /**
     * Check if user can generate music (quota/subscription check)
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

        // Check subscription status
        if ($user->subscription_status === 'premium') {
            return [
                'can_generate' => true,
                'reason' => 'Premium user - unlimited generations'
            ];
        }

        // Check free tier limits
        $freeLimit = config('topmediai.subscription.free_tier_limit', 10);
        
        if ($user->usage_count >= $freeLimit) {
            return [
                'can_generate' => false,
                'reason' => 'Free tier limit exceeded',
                'current_usage' => $user->usage_count,
                'limit' => $freeLimit
            ];
        }

        return [
            'can_generate' => true,
            'reason' => 'Within free tier limits',
            'current_usage' => $user->usage_count,
            'limit' => $freeLimit,
            'remaining' => $freeLimit - $user->usage_count
        ];
    }

    /**
     * Increment user usage count
     */
    public function incrementUsage(int $userId): void
    {
        $user = AiMusicUser::find($userId);
        if ($user) {
            $user->increment('usage_count');
            $user->increment('monthly_usage');
            $user->update(['last_active_at' => now()]);
        }
    }

    /**
     * Get user's generation history
     */
    public function getUserGenerations(int $userId, int $limit = 20): array
    {
        $generations = GeneratedContent::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $generations->map(function ($generation) {
            return [
                'id' => $generation->id,
                'title' => $generation->title,
                'content_type' => $generation->content_type,
                'status' => $generation->status,
                'prompt' => $generation->prompt,
                'mood' => $generation->mood,
                'genre' => $generation->genre,
                'duration' => $generation->duration,
                'content_url' => $generation->content_url,
                'thumbnail_url' => $generation->thumbnail_url,
                'created_at' => $generation->created_at,
                'completed_at' => $generation->completed_at,
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

        $totalGenerations = GeneratedContent::where('user_id', $userId)->count();
        $completedGenerations = GeneratedContent::where('user_id', $userId)
            ->where('status', 'completed')->count();
        $failedGenerations = GeneratedContent::where('user_id', $userId)
            ->where('status', 'failed')->count();

        return [
            'total_generations' => $totalGenerations,
            'completed_generations' => $completedGenerations,
            'failed_generations' => $failedGenerations,
            'success_rate' => $totalGenerations > 0 ? round(($completedGenerations / $totalGenerations) * 100, 2) : 0,
            'subscription_status' => $user->subscription_status,
            'usage_count' => $user->usage_count,
            'monthly_usage' => $user->monthly_usage,
            'last_active_at' => $user->last_active_at,
        ];
    }

    /**
     * Handle successful TopMediai response (status 200)
     */
    protected function handleSuccessfulResponse(array $response, GenerationRequest $generationRequest, array $params): array
    {
        // Extract task IDs from the response data
        $taskIds = [];
        if (isset($response['data']['ids']) && is_array($response['data']['ids'])) {
            $taskIds = $response['data']['ids'];
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

        // Create generated content record
        $generatedContent = $this->createGeneratedContentFromSuccess($params, $response, $generationRequest, $taskIds);

        Log::info('Music generation started successfully', [
            'generation_request_id' => $generationRequest->id,
            'generated_content_id' => $generatedContent->id,
            'task_ids' => $taskIds,
            'task_count' => count($taskIds)
        ]);

        return [
            'success' => true,
            'message' => 'Music generation started successfully!',
            'data' => [
                'task_ids' => $taskIds,
                'generation_id' => $generationRequest->id,
                'content_id' => $generatedContent->id,
                'estimated_time' => $this->getEstimatedTime(),
                'status' => 'processing'
            ]
        ];
    }

    /**
     * Handle TopMediai error response (non-200 status)
     */
    protected function handleErrorResponse(array $response, GenerationRequest $generationRequest, int $topMediaiStatus): array
    {
        // Map TopMediai error codes to user-friendly messages
        $errorMessage = $this->getErrorMessage($topMediaiStatus);
        $retryAfter = $this->getRetryDelay($topMediaiStatus);

        // Update generation request with error
        $generationRequest->update([
            'response_data' => $response,
            'status' => 'failed',
            'error_message' => $response['message'] ?? 'TopMediai API error',
            'error_code' => (string)$topMediaiStatus,
            'request_sent_at' => now(),
            'response_received_at' => now(),
        ]);

        Log::warning('Music generation failed - TopMediai error', [
            'generation_request_id' => $generationRequest->id,
            'topmediai_status' => $topMediaiStatus,
            'topmediai_message' => $response['message'] ?? 'Unknown error',
            'user_message' => $errorMessage
        ]);

        return [
            'success' => false,
            'message' => $errorMessage,
            'error_code' => 'GENERATION_FAILED',
            'retry_after' => $retryAfter
        ];
    }

    /**
     * Handle exception during API call
     */
    protected function handleExceptionResponse(Exception $e, GenerationRequest $generationRequest, array $params): array
    {
        // Update generation request with exception
        $generationRequest->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'response_received_at' => now(),
        ]);

        Log::error('Music generation failed - Exception', [
            'user_id' => $params['user_id'] ?? null,
            'generation_request_id' => $generationRequest->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return [
            'success' => false,
            'message' => 'Unable to connect to music generation service. Please try again.',
            'error_code' => 'CONNECTION_FAILED',
            'retry_after' => 60
        ];
    }

    /**
     * Create generated content record for successful response
     */
    protected function createGeneratedContentFromSuccess(array $params, array $response, GenerationRequest $generationRequest, array $taskIds): GeneratedContent
    {
        // Use the first task ID as the primary ID, or create a combined ID
        $primaryTaskId = !empty($taskIds) ? $taskIds[0] : 'unknown_' . uniqid();
        
        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $params['title'] ?? $this->generateTitle($params['prompt']),
            'content_type' => $params['content_type'] ?? 'song',
            'topmediai_task_id' => $primaryTaskId,
            'status' => 'pending',
            'prompt' => $params['prompt'],
            'mood' => $params['mood'] ?? null,
            'genre' => $params['genre'] ?? null,
            'instruments' => isset($params['instruments']) ? $params['instruments'] : null,
            'language' => $params['language'] ?? 'english',
            'duration' => $params['duration'] ?? null,
            'metadata' => [
                'all_task_ids' => $taskIds,
                'topmediai_response' => $response,
                'generation_request_id' => $generationRequest->id
            ],
            'started_at' => now(),
            'is_premium_generation' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Get user-friendly error message based on TopMediai status code
     */
    protected function getErrorMessage(int $statusCode): string
    {
        return match($statusCode) {
            400015 => 'Unable to generate music at the moment. Please try again later.',
            401 => 'Authentication failed. Please try again.',
            403 => 'Access denied. Please check your subscription.',
            429 => 'Too many requests. Please wait a moment before trying again.',
            500, 502, 503 => 'Music generation service is temporarily unavailable. Please try again.',
            default => 'Unable to generate music at the moment. Please try again.'
        };
    }

    /**
     * Get retry delay in seconds based on error type
     */
    protected function getRetryDelay(int $statusCode): int
    {
        return match($statusCode) {
            400015 => 300,  // 5 minutes for insufficient balance
            429 => 120,     // 2 minutes for rate limiting
            500, 502, 503 => 180,  // 3 minutes for server errors
            default => 60   // 1 minute for other errors
        };
    }

    /**
     * Get estimated completion time for music generation
     */
    protected function getEstimatedTime(): string
    {
        return '2-3 minutes';
    }
}