<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\AiMusicUser;
use Illuminate\Support\Facades\Log;
use Exception;

class SingerService extends TopMediaiBaseService
{
    /**
     * Generate vocals for existing music using TopMediai V3 Singer
     */
    public function generateSinger(array $params): array
    {
        // Validate required parameters
        $this->validateRequired($params, ['music_file_url', 'lyrics']);
        
        // Validate user exists
        if (isset($params['user_id'])) {
            $user = AiMusicUser::find($params['user_id']);
            if (!$user) {
                throw new Exception('User not found');
            }
        }

        // Prepare request data for TopMediai V3 Singer
        $requestData = $this->prepareRequestData($params);
        
        Log::info('Starting singer generation', [
            'user_id' => $params['user_id'] ?? null,
            'music_file_url' => $params['music_file_url'],
            'request_data' => $requestData
        ]);

        // Record generation request
        $generationRequest = $this->recordGenerationRequest($params, $requestData);

        try {
            // Call TopMediai V3 singer endpoint
            $response = $this->post(config('topmediai.endpoints.singer'), $requestData);
            
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
                'message' => 'Singer generation started successfully'
            ];

        } catch (Exception $e) {
            // Update generation request with error
            $generationRequest->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Singer generation failed', [
                'user_id' => $params['user_id'] ?? null,
                'error' => $e->getMessage(),
                'generation_request_id' => $generationRequest->id
            ]);

            throw $e;
        }
    }

    /**
     * Prepare request data for TopMediai V3 Singer
     */
    protected function prepareRequestData(array $params): array
    {
        $requestData = [
            'music_file_url' => $params['music_file_url'],
            'lyrics' => $params['lyrics']
        ];

        // Optional voice parameters
        if (isset($params['voice_id'])) {
            $requestData['voice_id'] = $params['voice_id'];
        }

        if (isset($params['voice_gender'])) {
            $requestData['voice_gender'] = $params['voice_gender']; // male, female
        }

        if (isset($params['voice_style'])) {
            $requestData['voice_style'] = $params['voice_style']; // pop, rock, classical, etc.
        }

        if (isset($params['vocal_intensity'])) {
            $requestData['vocal_intensity'] = $params['vocal_intensity']; // soft, medium, strong
        }

        if (isset($params['language'])) {
            $requestData['language'] = $params['language'];
        }

        if (isset($params['tempo_sync'])) {
            $requestData['tempo_sync'] = (bool) $params['tempo_sync'];
        }

        // Advanced vocal parameters
        if (isset($params['pitch_adjustment'])) {
            $requestData['pitch_adjustment'] = $params['pitch_adjustment']; // -12 to +12 semitones
        }

        if (isset($params['vibrato'])) {
            $requestData['vibrato'] = $params['vibrato']; // none, light, medium, heavy
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
            'endpoint_used' => 'v3_singer',
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
     * Create generated content record for vocals
     */
    protected function createGeneratedContent(array $params, array $response, GenerationRequest $generationRequest): GeneratedContent
    {
        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $params['title'] ?? $this->generateTitle($params),
            'content_type' => 'vocal',
            'topmediai_task_id' => $response['task_id'],
            'status' => 'pending',
            'prompt' => $this->generatePromptFromParams($params),
            'mood' => $params['mood'] ?? null,
            'genre' => $params['genre'] ?? null,
            'language' => $params['language'] ?? 'english',
            'metadata' => [
                'original_music_url' => $params['music_file_url'],
                'lyrics_text' => $params['lyrics'],
                'voice_config' => [
                    'voice_id' => $params['voice_id'] ?? null,
                    'voice_gender' => $params['voice_gender'] ?? null,
                    'voice_style' => $params['voice_style'] ?? null,
                    'vocal_intensity' => $params['vocal_intensity'] ?? null,
                    'pitch_adjustment' => $params['pitch_adjustment'] ?? null,
                    'vibrato' => $params['vibrato'] ?? null,
                ]
            ],
            'started_at' => now(),
            'is_premium_generation' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Generate title for vocal track
     */
    protected function generateTitle(array $params): string
    {
        $title = 'Vocal Track';
        
        if (isset($params['original_title'])) {
            $title = $params['original_title'] . ' - With Vocals';
        } elseif (isset($params['voice_style'])) {
            $title = ucwords($params['voice_style']) . ' Vocal Track';
        }
        
        // Limit to 50 characters
        if (strlen($title) > 50) {
            $title = substr($title, 0, 47) . '...';
        }

        return $title;
    }

    /**
     * Generate prompt description from parameters
     */
    protected function generatePromptFromParams(array $params): string
    {
        $parts = ['Add vocals to music track'];
        
        if (isset($params['voice_gender'])) {
            $parts[] = $params['voice_gender'] . ' voice';
        }
        
        if (isset($params['voice_style'])) {
            $parts[] = $params['voice_style'] . ' style';
        }
        
        if (isset($params['vocal_intensity'])) {
            $parts[] = $params['vocal_intensity'] . ' intensity';
        }

        return implode(', ', $parts);
    }

    /**
     * Get available voice options (if supported by TopMediai)
     */
    public function getAvailableVoices(): array
    {
        // This would call TopMediai API to get available voices
        // For now, return common voice options
        return [
            'male_voices' => [
                ['id' => 'male_pop_1', 'name' => 'Male Pop Voice 1', 'style' => 'pop'],
                ['id' => 'male_rock_1', 'name' => 'Male Rock Voice 1', 'style' => 'rock'],
                ['id' => 'male_classical_1', 'name' => 'Male Classical Voice 1', 'style' => 'classical'],
            ],
            'female_voices' => [
                ['id' => 'female_pop_1', 'name' => 'Female Pop Voice 1', 'style' => 'pop'],
                ['id' => 'female_rock_1', 'name' => 'Female Rock Voice 1', 'style' => 'rock'],
                ['id' => 'female_classical_1', 'name' => 'Female Classical Voice 1', 'style' => 'classical'],
            ],
            'voice_styles' => ['pop', 'rock', 'classical', 'jazz', 'country', 'r&b', 'electronic'],
            'vocal_intensities' => ['soft', 'medium', 'strong'],
            'vibrato_options' => ['none', 'light', 'medium', 'heavy'],
        ];
    }

    /**
     * Add vocals to existing generated content
     */
    public function addVocalsToContent(int $contentId, array $vocalsParams): array
    {
        $originalContent = GeneratedContent::find($contentId);
        if (!$originalContent) {
            throw new Exception('Original content not found');
        }

        if (!$originalContent->content_url) {
            throw new Exception('Original content does not have a valid audio URL');
        }

        // Prepare parameters for vocal generation
        $params = array_merge($vocalsParams, [
            'music_file_url' => $originalContent->content_url,
            'user_id' => $originalContent->user_id,
            'original_title' => $originalContent->title,
            'mood' => $originalContent->mood,
            'genre' => $originalContent->genre,
        ]);

        // Generate vocals
        $result = $this->generateSinger($params);

        // Link the new vocal content to the original
        if (isset($result['generated_content_id'])) {
            $vocalContent = GeneratedContent::find($result['generated_content_id']);
            if ($vocalContent) {
                $metadata = $vocalContent->metadata ?? [];
                $metadata['original_content_id'] = $contentId;
                $vocalContent->update(['metadata' => $metadata]);
            }
        }

        return $result;
    }

    /**
     * Get vocal generations for user
     */
    public function getUserVocalTracks(int $userId, int $limit = 20): array
    {
        $vocalTracks = GeneratedContent::where('user_id', $userId)
            ->where('content_type', 'vocal')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $vocalTracks->map(function ($track) {
            $metadata = $track->metadata ?? [];
            return [
                'id' => $track->id,
                'title' => $track->title,
                'status' => $track->status,
                'content_url' => $track->content_url,
                'thumbnail_url' => $track->thumbnail_url,
                'voice_config' => $metadata['voice_config'] ?? [],
                'original_music_url' => $metadata['original_music_url'] ?? null,
                'original_content_id' => $metadata['original_content_id'] ?? null,
                'lyrics_preview' => isset($metadata['lyrics_text']) ? 
                    substr($metadata['lyrics_text'], 0, 100) . '...' : null,
                'created_at' => $track->created_at,
                'completed_at' => $track->completed_at,
            ];
        })->toArray();
    }

    /**
     * Get vocal track details with full lyrics
     */
    public function getVocalTrackDetails(int $trackId): array
    {
        $track = GeneratedContent::where('id', $trackId)
            ->where('content_type', 'vocal')
            ->first();

        if (!$track) {
            throw new Exception('Vocal track not found');
        }

        $metadata = $track->metadata ?? [];
        
        return [
            'id' => $track->id,
            'title' => $track->title,
            'status' => $track->status,
            'content_url' => $track->content_url,
            'thumbnail_url' => $track->thumbnail_url,
            'download_url' => $track->download_url,
            'voice_config' => $metadata['voice_config'] ?? [],
            'original_music_url' => $metadata['original_music_url'] ?? null,
            'original_content_id' => $metadata['original_content_id'] ?? null,
            'lyrics_text' => $metadata['lyrics_text'] ?? '',
            'duration' => $track->duration,
            'mood' => $track->mood,
            'genre' => $track->genre,
            'language' => $track->language,
            'created_at' => $track->created_at,
            'completed_at' => $track->completed_at,
            'metadata' => $metadata,
        ];
    }

    /**
     * Preview vocals with different voice settings
     */
    public function previewVoice(array $params): array
    {
        // This could generate a short preview of the vocals
        // For now, return the configuration that would be used
        
        return [
            'preview_config' => $this->prepareRequestData($params),
            'estimated_preview_time' => 30, // seconds
            'voice_description' => $this->describeVoiceConfig($params),
        ];
    }

    /**
     * Describe voice configuration in human-readable format
     */
    protected function describeVoiceConfig(array $params): string
    {
        $description = [];
        
        if (isset($params['voice_gender'])) {
            $description[] = ucwords($params['voice_gender']);
        }
        
        if (isset($params['voice_style'])) {
            $description[] = ucwords($params['voice_style']) . ' style';
        }
        
        if (isset($params['vocal_intensity'])) {
            $description[] = $params['vocal_intensity'] . ' intensity';
        }
        
        if (isset($params['vibrato']) && $params['vibrato'] !== 'none') {
            $description[] = $params['vibrato'] . ' vibrato';
        }

        return implode(', ', $description) ?: 'Standard voice';
    }
}