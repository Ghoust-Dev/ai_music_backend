<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Qwen AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Alibaba Cloud's Qwen AI (DashScope) integration
    | Used for creative content generation: prompts, lyrics, etc.
    |
    */

    // API credentials
    'api_key' => env('QWEN_API_KEY', ''),
    
    // API endpoint - Global endpoint (region determined by API key)
    'base_url' => env('QWEN_BASE_URL', 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation'),
    
    // Model configuration
    'model' => env('QWEN_MODEL', 'qwen-turbo'), // qwen-turbo is the fast model (Flash equivalent)
    // Available models: qwen-turbo, qwen-plus, qwen-max
    
    // Timeout settings (seconds)
    'timeout' => env('QWEN_TIMEOUT', 30),
    
    // Retry configuration
    'max_retries' => env('QWEN_MAX_RETRIES', 3),
    
    // Cache configuration
    'cache_enabled' => env('QWEN_CACHE_ENABLED', false),
    'cache_ttl' => env('QWEN_CACHE_TTL', 3600), // seconds
    
    // Rate limiting
    'rate_limit' => [
        'enabled' => env('QWEN_RATE_LIMIT_ENABLED', true),
        'max_requests_per_minute' => env('QWEN_RATE_LIMIT_RPM', 60),
    ],
    
    // Content generation defaults
    'defaults' => [
        'temperature' => 0.85, // Creativity level (0.0 - 1.0)
        'top_p' => 0.9,
        'top_k' => 50,
        'max_tokens' => 500,
    ],
    
    // Supported languages for lyrics generation
    'supported_languages' => [
        'english', 'spanish', 'french', 'german', 'italian', 'portuguese',
        'chinese', 'japanese', 'korean', 'russian', 'arabic', 'hindi',
        'dutch', 'swedish', 'turkish', 'polish', 'vietnamese', 'thai'
    ],
    
    // Predefined moods
    'moods' => [
        'happy', 'sad', 'energetic', 'calm', 'romantic', 'melancholic',
        'uplifting', 'dreamy', 'dark', 'mysterious', 'hopeful', 'nostalgic',
        'peaceful', 'intense', 'playful', 'dramatic', 'relaxing', 'aggressive'
    ],
    
    // Predefined genres
    'genres' => [
        'pop', 'rock', 'hip-hop', 'electronic', 'jazz', 'classical',
        'folk', 'country', 'r&b', 'soul', 'reggae', 'indie', 'metal',
        'blues', 'funk', 'disco', 'ambient', 'techno', 'house', 'trap',
        'edm', 'dance', 'latin', 'afrobeat', 'k-pop'
    ],
    
    // Feature flags
    'features' => [
        'random_prompts' => env('QWEN_FEATURE_RANDOM_PROMPTS', true),
        'random_lyrics' => env('QWEN_FEATURE_RANDOM_LYRICS', true),
        'custom_lyrics' => env('QWEN_FEATURE_CUSTOM_LYRICS', true),
        'instrumental_prompts' => env('QWEN_FEATURE_INSTRUMENTAL_PROMPTS', true),
    ],
];