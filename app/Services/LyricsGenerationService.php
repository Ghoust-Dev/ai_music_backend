<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\AiMusicUser;
use Illuminate\Support\Facades\Log;
use Exception;

class LyricsGenerationService extends TopMediaiBaseService
{
    /**
     * Generate lyrics using TopMediai V1 API
     */
    public function generateLyrics(array $params): array
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

        // Prepare request data for TopMediai V1 lyrics
        $requestData = $this->prepareRequestData($params);
        
        Log::info('Starting lyrics generation', [
            'user_id' => $params['user_id'] ?? null,
            'prompt' => $params['prompt'],
            'request_data' => $requestData
        ]);

        // Record generation request
        $generationRequest = $this->recordGenerationRequest($params, $requestData);

        try {
            // Call TopMediai V1 lyrics endpoint
            $response = $this->post(config('topmediai.endpoints.lyrics'), $requestData);
            
            // Update generation request with response
            $generationRequest->update([
                'response_data' => $response,
                'status' => 'completed', // V1 lyrics is synchronous
                'request_sent_at' => now(),
                'response_received_at' => now(),
                'processing_time_seconds' => 1, // Approximate for sync response
            ]);

            // Create generated content record
            $generatedContent = $this->createGeneratedContent($params, $response, $generationRequest);

            return [
                'success' => true,
                'lyrics' => $response, // V1 returns lyrics directly
                'generation_request_id' => $generationRequest->id,
                'generated_content_id' => $generatedContent->id,
                'message' => 'Lyrics generated successfully'
            ];

        } catch (Exception $e) {
            // Update generation request with error
            $generationRequest->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'response_received_at' => now(),
            ]);

            Log::error('Lyrics generation failed', [
                'user_id' => $params['user_id'] ?? null,
                'error' => $e->getMessage(),
                'generation_request_id' => $generationRequest->id
            ]);

            throw $e;
        }
    }

    /**
     * Prepare request data for TopMediai V1 lyrics
     */
    protected function prepareRequestData(array $params): array
    {
        $requestData = [
            'prompt' => $params['prompt']
        ];

        // V1 lyrics endpoint might support additional parameters
        if (isset($params['mood'])) {
            $requestData['mood'] = $params['mood'];
        }

        if (isset($params['genre'])) {
            $requestData['genre'] = $params['genre'];
        }

        if (isset($params['language'])) {
            $requestData['language'] = $params['language'];
        }

        if (isset($params['style'])) {
            $requestData['style'] = $params['style'];
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
            'endpoint_used' => 'v1_lyrics',
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
     * Create generated content record for lyrics
     */
    protected function createGeneratedContent(array $params, $response, GenerationRequest $generationRequest): GeneratedContent
    {
        // For lyrics, we store the text content in metadata since there's no URL
        $lyricsText = is_string($response) ? $response : json_encode($response);
        
        return GeneratedContent::create([
            'user_id' => $params['user_id'] ?? null,
            'title' => $params['title'] ?? $this->generateTitle($params['prompt']),
            'content_type' => 'lyrics',
            'topmediai_task_id' => 'lyrics_' . time() . '_' . rand(1000, 9999), // Generate unique ID for lyrics
            'status' => 'completed',
            'prompt' => $params['prompt'],
            'mood' => $params['mood'] ?? null,
            'genre' => $params['genre'] ?? null,
            'language' => $params['language'] ?? 'english',
            'metadata' => [
                'lyrics_text' => $lyricsText,
                'word_count' => str_word_count($lyricsText),
                'generation_type' => 'lyrics_only',
                'api_response' => $response
            ],
            'started_at' => now(),
            'completed_at' => now(),
            'is_premium_generation' => $params['is_premium'] ?? false,
        ]);
    }

    /**
     * Generate a title from the prompt
     */
    protected function generateTitle(string $prompt): string
    {
        // Simple title generation from prompt for lyrics
        $title = ucwords(strtolower($prompt));
        
        // Add "Lyrics" suffix
        if (!str_contains(strtolower($title), 'lyrics')) {
            $title .= ' - Lyrics';
        }
        
        // Limit to 50 characters
        if (strlen($title) > 50) {
            $title = substr($title, 0, 47) . '...';
        }

        return $title;
    }

    /**
     * Get lyrics content by ID
     */
    public function getLyricsContent(int $contentId): array
    {
        $content = GeneratedContent::where('id', $contentId)
            ->where('content_type', 'lyrics')
            ->first();

        if (!$content) {
            throw new Exception('Lyrics content not found');
        }

        $metadata = $content->metadata ?? [];
        
        return [
            'id' => $content->id,
            'title' => $content->title,
            'prompt' => $content->prompt,
            'lyrics_text' => $metadata['lyrics_text'] ?? '',
            'word_count' => $metadata['word_count'] ?? 0,
            'mood' => $content->mood,
            'genre' => $content->genre,
            'language' => $content->language,
            'created_at' => $content->created_at,
            'metadata' => $metadata
        ];
    }

    /**
     * Search lyrics by content
     */
    public function searchLyrics(int $userId, string $searchTerm, int $limit = 10): array
    {
        $contents = GeneratedContent::where('user_id', $userId)
            ->where('content_type', 'lyrics')
            ->where(function ($query) use ($searchTerm) {
                $query->where('title', 'like', "%{$searchTerm}%")
                      ->orWhere('prompt', 'like', "%{$searchTerm}%")
                      ->orWhereJsonContains('metadata->lyrics_text', $searchTerm);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $contents->map(function ($content) {
            $metadata = $content->metadata ?? [];
            return [
                'id' => $content->id,
                'title' => $content->title,
                'prompt' => $content->prompt,
                'lyrics_preview' => substr($metadata['lyrics_text'] ?? '', 0, 100) . '...',
                'word_count' => $metadata['word_count'] ?? 0,
                'mood' => $content->mood,
                'genre' => $content->genre,
                'created_at' => $content->created_at,
            ];
        })->toArray();
    }

    /**
     * Get user's lyrics statistics
     */
    public function getUserLyricsStats(int $userId): array
    {
        $totalLyrics = GeneratedContent::where('user_id', $userId)
            ->where('content_type', 'lyrics')->count();
        
        $totalWords = GeneratedContent::where('user_id', $userId)
            ->where('content_type', 'lyrics')
            ->get()
            ->sum(function ($content) {
                return $content->metadata['word_count'] ?? 0;
            });

        $genreStats = GeneratedContent::where('user_id', $userId)
            ->where('content_type', 'lyrics')
            ->selectRaw('genre, count(*) as count')
            ->whereNotNull('genre')
            ->groupBy('genre')
            ->pluck('count', 'genre')
            ->toArray();

        return [
            'total_lyrics' => $totalLyrics,
            'total_words' => $totalWords,
            'average_words_per_lyrics' => $totalLyrics > 0 ? round($totalWords / $totalLyrics, 2) : 0,
            'genre_breakdown' => $genreStats,
            'most_popular_genre' => !empty($genreStats) ? array_keys($genreStats, max($genreStats))[0] : null,
        ];
    }

    /**
     * Export lyrics to different formats
     */
    public function exportLyrics(int $contentId, string $format = 'txt'): string
    {
        $content = $this->getLyricsContent($contentId);
        
        switch ($format) {
            case 'txt':
                return $this->exportToTxt($content);
            case 'json':
                return json_encode($content, JSON_PRETTY_PRINT);
            case 'md':
                return $this->exportToMarkdown($content);
            default:
                throw new Exception("Unsupported export format: {$format}");
        }
    }

    /**
     * Export lyrics to plain text
     */
    protected function exportToTxt(array $content): string
    {
        $output = "Title: {$content['title']}\n";
        $output .= "Prompt: {$content['prompt']}\n";
        $output .= "Genre: {$content['genre']}\n";
        $output .= "Mood: {$content['mood']}\n";
        $output .= "Language: {$content['language']}\n";
        $output .= "Created: {$content['created_at']}\n";
        $output .= "\n--- LYRICS ---\n\n";
        $output .= $content['lyrics_text'];
        
        return $output;
    }

    /**
     * Export lyrics to Markdown
     */
    protected function exportToMarkdown(array $content): string
    {
        $output = "# {$content['title']}\n\n";
        $output .= "**Prompt:** {$content['prompt']}\n";
        $output .= "**Genre:** {$content['genre']}\n";
        $output .= "**Mood:** {$content['mood']}\n";
        $output .= "**Language:** {$content['language']}\n";
        $output .= "**Created:** {$content['created_at']}\n\n";
        $output .= "## Lyrics\n\n";
        $output .= $content['lyrics_text'];
        
        return $output;
    }
}