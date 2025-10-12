<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Qwen AI Integration Service
 * 
 * Uses Qwen 3 Flash model for creative content generation:
 * - Random song prompts
 * - Random lyrics
 * - Custom lyrics from description
 * - Random instrumental prompts
 */
class QwenAiService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected int $timeout;
    protected int $maxRetries;

    public function __construct()
    {
        $this->apiKey = config('services.qwen.api_key');
        // Model Studio Singapore region endpoint (OpenAI-compatible)
        $this->baseUrl = config('services.qwen.base_url', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions');
        $this->model = config('services.qwen.model', 'qwen-turbo'); // Qwen 3 Flash equivalent
        $this->timeout = config('services.qwen.timeout', 30);
        $this->maxRetries = config('services.qwen.max_retries', 3);
    }

    /**
     * Generate random song prompt
     * 
     * @param array $options Optional parameters: mood, genre, style
     * @return array ['success' => bool, 'prompt' => string, 'metadata' => array]
     */
    public function generateRandomSongPrompt(array $options = []): array
    {
        try {
            $mood = $options['mood'] ?? $this->getRandomMood();
            $genre = $options['genre'] ?? $this->getRandomGenre();
            $maxLength = $options['max_length'] ?? 200; // Character limit
            
            $systemPrompt = "You are a creative music prompt generator. Generate concise, creative prompts for an AI music generator.";
            
            $userPrompt = "Generate a creative music prompt with the following characteristics:\n";
            $userPrompt .= "- Mood: {$mood}\n";
            $userPrompt .= "- Genre: {$genre}\n";
            $userPrompt .= "\nIMPORTANT: Keep the prompt under {$maxLength} characters.";
            $userPrompt .= "\nProvide ONLY the prompt text, no explanations or additional text.";

            // Limit tokens to ensure shorter response
            $result = $this->callQwenApi($systemPrompt, $userPrompt, [
                'max_tokens' => 100 // Roughly 75-100 characters
            ]);

            // Ensure the prompt doesn't exceed max length
            $generatedPrompt = trim($result['text']);
            if (mb_strlen($generatedPrompt) > $maxLength) {
                $generatedPrompt = mb_substr($generatedPrompt, 0, $maxLength - 3) . '...';
            }

            return [
                'success' => true,
                'prompt' => $generatedPrompt,
                'metadata' => [
                    'mood' => $mood,
                    'genre' => $genre,
                    'generation_type' => 'random_song_prompt',
                    'model_used' => $this->model,
                    'character_count' => mb_strlen($generatedPrompt),
                    'max_length' => $maxLength,
                    'tokens_used' => $result['usage'] ?? null,
                ]
            ];

        } catch (Exception $e) {
            Log::error('Qwen AI: Failed to generate random song prompt', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fallback_prompt' => $this->getFallbackSongPrompt($options)
            ];
        }
    }

    /**
     * Generate random lyrics
     * 
     * @param array $options Optional: mood, genre, theme, length
     * @return array ['success' => bool, 'lyrics' => string, 'metadata' => array]
     */
    public function generateRandomLyrics(array $options = []): array
    {
        try {
            $mood = $options['mood'] ?? $this->getRandomMood();
            $genre = $options['genre'] ?? $this->getRandomGenre();
            $theme = $options['theme'] ?? $this->getRandomTheme();
            $length = $options['length'] ?? 'medium'; // short, medium, long
            
            $systemPrompt = "You are a talented songwriter. Generate creative, emotional, and well-structured song lyrics.";
            
            $userPrompt = "Write complete song lyrics with the following specifications:\n";
            $userPrompt .= "- Mood: {$mood}\n";
            $userPrompt .= "- Genre: {$genre}\n";
            $userPrompt .= "- Theme: {$theme}\n";
            $userPrompt .= "- Length: {$length}\n";
            $userPrompt .= "\nInclude verse, chorus, and bridge. Make it creative and emotionally engaging.";
            $userPrompt .= "\nProvide ONLY the lyrics, no explanations.";

            $result = $this->callQwenApi($systemPrompt, $userPrompt, [
                'max_tokens' => $this->getLengthTokens($length)
            ]);

            return [
                'success' => true,
                'lyrics' => trim($result['text']),
                'metadata' => [
                    'mood' => $mood,
                    'genre' => $genre,
                    'theme' => $theme,
                    'length' => $length,
                    'word_count' => str_word_count($result['text']),
                    'generation_type' => 'random_lyrics',
                    'model_used' => $this->model,
                    'tokens_used' => $result['usage'] ?? null,
                ]
            ];

        } catch (Exception $e) {
            Log::error('Qwen AI: Failed to generate random lyrics', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate lyrics from user description and language
     * 
     * @param string $description User's description of desired lyrics
     * @param string $language Target language for lyrics
     * @param array $options Optional: mood, genre, style
     * @return array ['success' => bool, 'lyrics' => string, 'metadata' => array]
     */
    public function generateLyricsFromDescription(string $description, string $language = 'english', array $options = []): array
    {
        try {
            $mood = $options['mood'] ?? null;
            $genre = $options['genre'] ?? null;
            $style = $options['style'] ?? null;
            
            $systemPrompt = "You are a professional multilingual songwriter. Generate high-quality song lyrics in the requested language based on user descriptions.";
            
            $userPrompt = "Write song lyrics based on this description: {$description}\n\n";
            $userPrompt .= "Requirements:\n";
            $userPrompt .= "- Language: {$language}\n";
            
            if ($mood) {
                $userPrompt .= "- Mood: {$mood}\n";
            }
            if ($genre) {
                $userPrompt .= "- Genre: {$genre}\n";
            }
            if ($style) {
                $userPrompt .= "- Style: {$style}\n";
            }
            
            $userPrompt .= "\nInclude verse, chorus, and bridge sections.";
            $userPrompt .= "\nMake sure the lyrics are in {$language} language.";
            $userPrompt .= "\nProvide ONLY the lyrics, no explanations or translations.";

            $result = $this->callQwenApi($systemPrompt, $userPrompt, [
                'max_tokens' => 800
            ]);

            return [
                'success' => true,
                'lyrics' => trim($result['text']),
                'metadata' => [
                    'description' => $description,
                    'language' => $language,
                    'mood' => $mood,
                    'genre' => $genre,
                    'style' => $style,
                    'word_count' => str_word_count($result['text']),
                    'generation_type' => 'custom_lyrics',
                    'model_used' => $this->model,
                    'tokens_used' => $result['usage'] ?? null,
                ]
            ];

        } catch (Exception $e) {
            Log::error('Qwen AI: Failed to generate lyrics from description', [
                'error' => $e->getMessage(),
                'description' => $description,
                'language' => $language
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate random instrumental prompt
     * 
     * @param array $options Optional: mood, genre, instruments
     * @return array ['success' => bool, 'prompt' => string, 'metadata' => array]
     */
    public function generateRandomInstrumentalPrompt(array $options = []): array
    {
        try {
            $mood = $options['mood'] ?? $this->getRandomMood();
            $genre = $options['genre'] ?? $this->getRandomGenre();
            $instruments = $options['instruments'] ?? $this->getRandomInstruments();
            $maxLength = $options['max_length'] ?? 200; // Character limit
            
            $systemPrompt = "You are an expert in instrumental music composition. Generate concise prompts for instrumental music.";
            
            $userPrompt = "Generate a creative prompt for instrumental music with these characteristics:\n";
            $userPrompt .= "- Mood: {$mood}\n";
            $userPrompt .= "- Genre: {$genre}\n";
            $userPrompt .= "- Main Instruments: " . implode(', ', $instruments) . "\n";
            $userPrompt .= "\nIMPORTANT: Keep the prompt under {$maxLength} characters.";
            $userPrompt .= "\nDescribe the atmosphere, tempo, and musical elements concisely.";
            $userPrompt .= "\nProvide ONLY the prompt text, no explanations.";

            // Limit tokens to ensure shorter response
            $result = $this->callQwenApi($systemPrompt, $userPrompt, [
                'max_tokens' => 100 // Roughly 75-100 characters
            ]);

            // Ensure the prompt doesn't exceed max length
            $generatedPrompt = trim($result['text']);
            if (mb_strlen($generatedPrompt) > $maxLength) {
                $generatedPrompt = mb_substr($generatedPrompt, 0, $maxLength - 3) . '...';
            }

            return [
                'success' => true,
                'prompt' => $generatedPrompt,
                'metadata' => [
                    'mood' => $mood,
                    'genre' => $genre,
                    'instruments' => $instruments,
                    'generation_type' => 'random_instrumental_prompt',
                    'is_instrumental' => true,
                    'character_count' => mb_strlen($generatedPrompt),
                    'max_length' => $maxLength,
                    'model_used' => $this->model,
                    'tokens_used' => $result['usage'] ?? null,
                ]
            ];

        } catch (Exception $e) {
            Log::error('Qwen AI: Failed to generate random instrumental prompt', [
                'error' => $e->getMessage(),
                'options' => $options
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fallback_prompt' => $this->getFallbackInstrumentalPrompt($options)
            ];
        }
    }

    /**
     * Call Qwen API (Alibaba Cloud Model Studio - OpenAI Compatible)
     * 
     * @param string $systemPrompt System instruction
     * @param string $userPrompt User request
     * @param array $options Additional options
     * @return array ['text' => string, 'usage' => array]
     */
    protected function callQwenApi(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        $maxTokens = $options['max_tokens'] ?? 500;
        $temperature = $options['temperature'] ?? 0.85;
        
        // Model Studio uses OpenAI-compatible format
        $requestData = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt
                ]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'top_p' => 0.9,
        ];

        Log::info('Qwen AI: Sending request (Model Studio)', [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system_prompt_length' => strlen($systemPrompt),
            'user_prompt_length' => strlen($userPrompt)
        ]);

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                // Model Studio uses standard OpenAI-compatible format
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->baseUrl, $requestData);

                if (!$response->successful()) {
                    throw new Exception("Qwen API error: HTTP {$response->status()} - {$response->body()}");
                }

                $data = $response->json();

                // Model Studio returns OpenAI-compatible format
                if (!isset($data['choices'][0]['message']['content'])) {
                    throw new Exception('Invalid Qwen API response format: ' . json_encode($data));
                }

                $generatedText = $data['choices'][0]['message']['content'];

                Log::info('Qwen AI: Request successful', [
                    'response_length' => strlen($generatedText),
                    'tokens_used' => $data['usage'] ?? null
                ]);

                return [
                    'text' => $generatedText,
                    'usage' => $data['usage'] ?? null
                ];

            } catch (Exception $e) {
                $attempt++;
                $lastException = $e;
                
                Log::warning('Qwen AI: Request failed', [
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage()
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep(pow(2, $attempt)); // Exponential backoff
                }
            }
        }

        throw $lastException ?? new Exception('Qwen API request failed after all retries');
    }

    /**
     * Get random mood from predefined list
     */
    protected function getRandomMood(): string
    {
        $moods = [
            'happy', 'sad', 'energetic', 'calm', 'romantic', 'melancholic',
            'uplifting', 'dreamy', 'dark', 'mysterious', 'hopeful', 'nostalgic',
            'peaceful', 'intense', 'playful', 'dramatic', 'relaxing', 'aggressive'
        ];
        
        return $moods[array_rand($moods)];
    }

    /**
     * Get random genre from predefined list
     */
    protected function getRandomGenre(): string
    {
        $genres = [
            'pop', 'rock', 'hip-hop', 'electronic', 'jazz', 'classical',
            'folk', 'country', 'r&b', 'soul', 'reggae', 'indie', 'metal',
            'blues', 'funk', 'disco', 'ambient', 'techno', 'house'
        ];
        
        return $genres[array_rand($genres)];
    }

    /**
     * Get random theme for lyrics
     */
    protected function getRandomTheme(): string
    {
        $themes = [
            'love', 'heartbreak', 'freedom', 'adventure', 'dreams', 'friendship',
            'hope', 'loneliness', 'celebration', 'memories', 'journey', 'change',
            'nature', 'city life', 'overcoming challenges', 'self-discovery',
            'summer nights', 'lost love', 'new beginnings', 'inner strength'
        ];
        
        return $themes[array_rand($themes)];
    }

    /**
     * Get random instruments for instrumental music
     */
    protected function getRandomInstruments(): array
    {
        $allInstruments = [
            'piano', 'guitar', 'violin', 'drums', 'bass', 'saxophone',
            'flute', 'cello', 'synthesizer', 'trumpet', 'harp', 'clarinet',
            'electric guitar', 'acoustic guitar', 'keyboard', 'percussion'
        ];
        
        // Return 2-4 random instruments
        $count = rand(2, 4);
        $selected = array_rand(array_flip($allInstruments), $count);
        
        return is_array($selected) ? $selected : [$selected];
    }

    /**
     * Get token count based on length
     */
    protected function getLengthTokens(string $length): int
    {
        return match($length) {
            'short' => 300,
            'medium' => 500,
            'long' => 800,
            default => 500
        };
    }

    /**
     * Get fallback song prompt when AI fails
     */
    protected function getFallbackSongPrompt(array $options): string
    {
        $mood = $options['mood'] ?? 'upbeat';
        $genre = $options['genre'] ?? 'pop';
        
        return "A {$mood} {$genre} song with catchy melodies and memorable hooks";
    }

    /**
     * Get fallback instrumental prompt when AI fails
     */
    protected function getFallbackInstrumentalPrompt(array $options): string
    {
        $mood = $options['mood'] ?? 'atmospheric';
        $genre = $options['genre'] ?? 'ambient';
        
        return "An {$mood} {$genre} instrumental piece with rich textures and dynamic progression";
    }

    /**
     * Test Qwen API connection
     */
    public function testConnection(): array
    {
        try {
            $result = $this->callQwenApi(
                "You are a helpful assistant.",
                "Say 'Connection successful!' and nothing else.",
                ['max_tokens' => 50]
            );

            return [
                'success' => true,
                'message' => 'Qwen AI connection successful',
                'response' => $result['text']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Qwen AI connection failed',
                'error' => $e->getMessage()
            ];
        }
    }
}