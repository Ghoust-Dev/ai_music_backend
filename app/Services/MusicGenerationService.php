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
            
            // Update generation request with response
            $generationRequest->update([
                'topmediai_task_id' => $response['task_id'] ?? null,
                'response_data' => $response,
                'status' => 'pending',
                'request_sent_at' => now(),
            ]);

            // Create generated content record
            $generatedContent = $this->createGeneratedContent($params, $response, $generationRequest);

            return [
                'success' => true,
                'task_id' => $response['task_id'],
                'generation_request_id' => $generationRequest->id,
                'generated_content_id' => $generatedContent->id,
                'estimated_time' => $response['estimated_time'] ?? null,
                'message' => 'Music generation started successfully'
            ];

        } catch (Exception $e) {
            // Update generation request with error
            $generationRequest->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Music generation failed', [
                'user_id' => $params['user_id'] ?? null,
                'error' => $e->getMessage(),
                'generation_request_id' => $generationRequest->id
            ]);

            throw $e;
        }
    }

    /**
     * Prepare request data for TopMediai V3
     */
    protected function prepareRequestData(array $params): array
    {
        $requestData = [
            'prompt' => $params['prompt']
        ];

        // Optional parameters
        if (isset($params['mood'])) {
            $requestData['mood'] = $params['mood'];
        }

        if (isset($params['genre'])) {
            $requestData['genre'] = $params['genre'];
        }

        if (isset($params['instruments']) && is_array($params['instruments'])) {
            $requestData['instruments'] = $params['instruments'];
        }

        if (isset($params['language'])) {
            $requestData['language'] = $params['language'];
        }

        if (isset($params['duration'])) {
            $requestData['duration'] = (int) $params['duration'];
        }

        // Additional V3 parameters if supported
        if (isset($params['style'])) {
            $requestData['style'] = $params['style'];
        }

        if (isset($params['tempo'])) {
            $requestData['tempo'] = $params['tempo'];
        }

        return $requestData;
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
        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $params['title'] ?? $this->generateTitle($params['prompt']),
            'content_type' => $params['content_type'] ?? 'song',
            'topmediai_task_id' => $response['task_id'],
            'status' => 'pending',
            'prompt' => $params['prompt'],
            'mood' => $params['mood'] ?? null,
            'genre' => $params['genre'] ?? null,
            'instruments' => isset($params['instruments']) ? $params['instruments'] : null,
            'language' => $params['language'] ?? 'english',
            'duration' => $params['duration'] ?? null,
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
}