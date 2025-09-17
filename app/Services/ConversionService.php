<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\AiMusicUser;
use Illuminate\Support\Facades\Log;
use Exception;

class ConversionService extends TopMediaiBaseService
{
    /**
     * Convert audio to MP4 format using TopMediai V3
     */
    public function convertToMp4(array $params): array
    {
        return $this->convertFormat($params, 'mp4');
    }

    /**
     * Convert audio to WAV format using TopMediai V3
     */
    public function convertToWav(array $params): array
    {
        return $this->convertFormat($params, 'wav');
    }

    /**
     * Convert audio to specified format
     */
    public function convertFormat(array $params, string $targetFormat): array
    {
        // Validate required parameters
        $this->validateRequired($params, ['audio_url']);
        
        if (!in_array($targetFormat, ['mp4', 'wav'])) {
            throw new Exception('Unsupported target format: ' . $targetFormat);
        }

        // Validate user exists
        if (isset($params['user_id'])) {
            $user = AiMusicUser::find($params['user_id']);
            if (!$user) {
                throw new Exception('User not found');
            }
        }

        // Prepare request data
        $requestData = $this->prepareRequestData($params, $targetFormat);
        
        Log::info('Starting format conversion', [
            'user_id' => $params['user_id'] ?? null,
            'audio_url' => $params['audio_url'],
            'target_format' => $targetFormat,
            'request_data' => $requestData
        ]);

        // Record generation request
        $generationRequest = $this->recordGenerationRequest($params, $requestData, $targetFormat);

        try {
            // Select the appropriate endpoint
            $endpoint = $targetFormat === 'mp4' ? 
                config('topmediai.endpoints.convert_mp4') : 
                config('topmediai.endpoints.convert_wav');

            // Call TopMediai V3 conversion endpoint
            $response = $this->post($endpoint, $requestData);
            
            // Update generation request with response
            $generationRequest->update([
                'topmediai_task_id' => $response['task_id'] ?? null,
                'response_data' => $response,
                'status' => 'pending',
                'request_sent_at' => now(),
            ]);

            // Create or update generated content record
            $generatedContent = $this->createConvertedContent($params, $response, $generationRequest, $targetFormat);

            return [
                'success' => true,
                'task_id' => $response['task_id'],
                'generation_request_id' => $generationRequest->id,
                'generated_content_id' => $generatedContent->id,
                'target_format' => $targetFormat,
                'estimated_time' => $response['estimated_time'] ?? null,
                'message' => "Conversion to {$targetFormat} started successfully"
            ];

        } catch (Exception $e) {
            // Update generation request with error
            $generationRequest->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Format conversion failed', [
                'user_id' => $params['user_id'] ?? null,
                'target_format' => $targetFormat,
                'error' => $e->getMessage(),
                'generation_request_id' => $generationRequest->id
            ]);

            throw $e;
        }
    }

    /**
     * Prepare request data for TopMediai conversion
     */
    protected function prepareRequestData(array $params, string $targetFormat): array
    {
        $requestData = [
            'audio_url' => $params['audio_url']
        ];

        // Format-specific parameters
        if ($targetFormat === 'mp4') {
            // MP4 conversion parameters
            if (isset($params['video_quality'])) {
                $requestData['video_quality'] = $params['video_quality']; // 720p, 1080p
            }
            
            if (isset($params['include_visualizer'])) {
                $requestData['include_visualizer'] = (bool) $params['include_visualizer'];
            }
            
            if (isset($params['background_image'])) {
                $requestData['background_image'] = $params['background_image'];
            }
        } elseif ($targetFormat === 'wav') {
            // WAV conversion parameters
            if (isset($params['sample_rate'])) {
                $requestData['sample_rate'] = $params['sample_rate']; // 44100, 48000
            }
            
            if (isset($params['bit_depth'])) {
                $requestData['bit_depth'] = $params['bit_depth']; // 16, 24, 32
            }
        }

        // Common audio parameters
        if (isset($params['bitrate'])) {
            $requestData['bitrate'] = $params['bitrate'];
        }

        if (isset($params['quality'])) {
            $requestData['quality'] = $params['quality']; // low, medium, high
        }

        return $requestData;
    }

    /**
     * Record generation request in database
     */
    protected function recordGenerationRequest(array $params, array $requestData, string $targetFormat): GenerationRequest
    {
        $endpoint = $targetFormat === 'mp4' ? 'v3_convert_mp4' : 'v3_convert_wav';
        
        return GenerationRequest::create([
            'user_id' => $params['user_id'] ?? null,
            'endpoint_used' => $endpoint,
            'request_payload' => $requestData,
            'status' => 'initiated',
            'device_id' => $params['device_id'] ?? null,
            'ip_address' => $params['ip_address'] ?? null,
            'user_agent' => $params['user_agent'] ?? null,
            'counted_towards_quota' => $params['count_towards_quota'] ?? false, // Conversions might not count
            'is_premium_request' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Create generated content record for conversion
     */
    protected function createConvertedContent(array $params, array $response, GenerationRequest $generationRequest, string $targetFormat): GeneratedContent
    {
        // Check if this is a conversion of existing content
        $originalContent = null;
        if (isset($params['original_content_id'])) {
            $originalContent = GeneratedContent::find($params['original_content_id']);
        }

        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $this->generateConversionTitle($params, $targetFormat, $originalContent),
            'content_type' => $originalContent ? $originalContent->content_type : 'song',
            'topmediai_task_id' => $response['task_id'],
            'status' => 'pending',
            'prompt' => $this->generateConversionPrompt($params, $targetFormat, $originalContent),
            'mood' => $originalContent->mood ?? $params['mood'] ?? null,
            'genre' => $originalContent->genre ?? $params['genre'] ?? null,
            'instruments' => $originalContent->instruments ?? $params['instruments'] ?? null,
            'language' => $originalContent->language ?? $params['language'] ?? 'english',
            'duration' => $originalContent->duration ?? $params['duration'] ?? null,
            'metadata' => [
                'conversion_type' => $targetFormat,
                'original_audio_url' => $params['audio_url'],
                'original_content_id' => $params['original_content_id'] ?? null,
                'conversion_params' => array_diff_key($params, array_flip(['audio_url', 'user_id', 'device_id'])),
            ],
            'started_at' => now(),
            'is_premium_generation' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Generate title for converted content
     */
    protected function generateConversionTitle(array $params, string $targetFormat, ?GeneratedContent $originalContent): string
    {
        if ($originalContent) {
            return $originalContent->title . ' (' . strtoupper($targetFormat) . ')';
        }
        
        return 'Converted Audio (' . strtoupper($targetFormat) . ')';
    }

    /**
     * Generate prompt description for conversion
     */
    protected function generateConversionPrompt(array $params, string $targetFormat, ?GeneratedContent $originalContent): string
    {
        $prompt = "Convert audio to {$targetFormat} format";
        
        if ($originalContent) {
            $prompt = "Convert '{$originalContent->title}' to {$targetFormat} format";
        }
        
        return $prompt;
    }

    /**
     * Batch convert multiple files
     */
    public function batchConvert(array $files, string $targetFormat, array $commonParams = []): array
    {
        $results = [];
        $errors = [];
        
        foreach ($files as $index => $fileParams) {
            try {
                $params = array_merge($commonParams, $fileParams);
                $result = $this->convertFormat($params, $targetFormat);
                $results[] = $result;
                
                Log::info('Batch conversion item succeeded', [
                    'index' => $index,
                    'task_id' => $result['task_id']
                ]);
                
            } catch (Exception $e) {
                $error = [
                    'index' => $index,
                    'file_params' => $fileParams,
                    'error' => $e->getMessage()
                ];
                $errors[] = $error;
                
                Log::error('Batch conversion item failed', $error);
            }
        }
        
        return [
            'total_files' => count($files),
            'successful_conversions' => count($results),
            'failed_conversions' => count($errors),
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Get conversion history for user
     */
    public function getUserConversions(int $userId, int $limit = 20): array
    {
        $conversions = GeneratedContent::where('user_id', $userId)
            ->whereJsonContains('metadata->conversion_type', ['mp4', 'wav'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $conversions->map(function ($conversion) {
            $metadata = $conversion->metadata ?? [];
            return [
                'id' => $conversion->id,
                'title' => $conversion->title,
                'status' => $conversion->status,
                'conversion_type' => $metadata['conversion_type'] ?? 'unknown',
                'original_audio_url' => $metadata['original_audio_url'] ?? null,
                'original_content_id' => $metadata['original_content_id'] ?? null,
                'content_url' => $conversion->content_url,
                'download_url' => $conversion->download_url,
                'created_at' => $conversion->created_at,
                'completed_at' => $conversion->completed_at,
            ];
        })->toArray();
    }

    /**
     * Get supported conversion formats and options
     */
    public function getSupportedFormats(): array
    {
        return [
            'target_formats' => ['mp4', 'wav'],
            'mp4_options' => [
                'video_qualities' => ['720p', '1080p'],
                'visualizer_types' => ['spectrum', 'waveform', 'bars'],
                'background_options' => ['solid_color', 'gradient', 'custom_image']
            ],
            'wav_options' => [
                'sample_rates' => [44100, 48000, 96000],
                'bit_depths' => [16, 24, 32],
                'qualities' => ['low', 'medium', 'high', 'lossless']
            ],
            'common_options' => [
                'bitrates' => ['128kbps', '192kbps', '256kbps', '320kbps'],
                'qualities' => ['low', 'medium', 'high']
            ]
        ];
    }

    /**
     * Estimate conversion time and file size
     */
    public function estimateConversion(array $params, string $targetFormat): array
    {
        // Basic estimation logic (would be more sophisticated in real implementation)
        $duration = $params['duration'] ?? 180; // Default 3 minutes
        
        $estimatedTime = match ($targetFormat) {
            'mp4' => max(30, $duration * 0.5), // Video conversion takes longer
            'wav' => max(15, $duration * 0.2), // WAV is faster
            default => 30
        };
        
        $estimatedSize = match ($targetFormat) {
            'mp4' => $duration * 2, // ~2MB per minute for MP4
            'wav' => $duration * 10, // ~10MB per minute for WAV
            default => $duration * 1
        };
        
        return [
            'estimated_time_seconds' => (int) $estimatedTime,
            'estimated_file_size_mb' => round($estimatedSize, 1),
            'target_format' => $targetFormat,
            'source_duration' => $duration
        ];
    }

    /**
     * Check conversion quota for user
     */
    public function checkConversionQuota(int $userId): array
    {
        $user = AiMusicUser::find($userId);
        if (!$user) {
            return [
                'can_convert' => false,
                'reason' => 'User not found'
            ];
        }

        // Premium users get unlimited conversions
        if ($user->subscription_status === 'premium') {
            return [
                'can_convert' => true,
                'reason' => 'Premium user - unlimited conversions'
            ];
        }

        // Check monthly conversion limits for free users
        $monthlyConversions = GenerationRequest::where('user_id', $userId)
            ->whereIn('endpoint_used', ['v3_convert_mp4', 'v3_convert_wav'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $monthlyLimit = config('topmediai.conversion.free_monthly_limit', 5);

        if ($monthlyConversions >= $monthlyLimit) {
            return [
                'can_convert' => false,
                'reason' => 'Monthly conversion limit exceeded',
                'current_usage' => $monthlyConversions,
                'limit' => $monthlyLimit
            ];
        }

        return [
            'can_convert' => true,
            'reason' => 'Within monthly conversion limits',
            'current_usage' => $monthlyConversions,
            'limit' => $monthlyLimit,
            'remaining' => $monthlyLimit - $monthlyConversions
        ];
    }
}