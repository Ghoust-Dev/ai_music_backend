<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TopMediai API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for TopMediai API integration used by the AI Music Generator
    |
    */

    'api_key' => env('TOPMEDIAI_API_KEY'),
    'base_url' => env('TOPMEDIAI_BASE_URL', 'https://api.topmediai.com'),
    'timeout' => (int) env('TOPMEDIAI_TIMEOUT', 30),
    'retry_attempts' => (int) env('TOPMEDIAI_RETRY_ATTEMPTS', 3),
    'retry_delay' => (int) env('TOPMEDIAI_RETRY_DELAY', 1000),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints (CORRECTED)
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'music' => '/v3/music/generate',           // ✅ FIXED: Added /generate
        'music_status' => '/v3/music/tasks',       // ✅ FIXED: Changed to /tasks
        'lyrics' => '/v1/lyrics',
        'singer' => '/v3/music/generate-singer',   // ✅ FIXED: Added /generate-singer
        'convert_mp4' => '/v3/convert-mp4',
        'convert_wav' => '/v3/convert-wav',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage Configuration
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'generated_content_path' => env('GENERATED_CONTENT_PATH', 'storage/app/generated'),
        'thumbnail_path' => env('THUMBNAIL_PATH', 'storage/app/thumbnails'),
        'max_file_size' => (int) env('MAX_FILE_SIZE', 52428800), // 50MB
        'allowed_formats' => explode(',', env('ALLOWED_AUDIO_FORMATS', 'mp3,wav,mp4')),
        'cleanup_days' => (int) env('CLEANUP_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'free_tier' => (int) env('RATE_LIMIT_FREE_TIER', 5),
        'premium_tier' => (int) env('RATE_LIMIT_PREMIUM_TIER', 50),
        'window' => (int) env('RATE_LIMIT_WINDOW', 3600), // 1 hour in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Configuration
    |--------------------------------------------------------------------------
    */

    'subscription' => [
        'free_tier_limit' => (int) env('FREE_TIER_LIMIT', 100),
        'premium_features_enabled' => (bool) env('PREMIUM_FEATURES_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Device Tracking Configuration
    |--------------------------------------------------------------------------
    */

    'device_tracking' => [
        'salt' => env('DEVICE_ID_SALT', 'default_salt'),
        'linking_enabled' => (bool) env('DEVICE_LINKING_ENABLED', true),
        'max_devices_per_user' => (int) env('MAX_DEVICES_PER_USER', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Security Configuration
    |--------------------------------------------------------------------------
    */

    'api_security' => [
        'rate_limit' => (int) env('API_RATE_LIMIT', 100),
        'rate_limit_window' => (int) env('API_RATE_LIMIT_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Job Configuration
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'timeout' => (int) env('QUEUE_TIMEOUT', 300),
        'memory_limit' => (int) env('QUEUE_MEMORY_LIMIT', 512),
        'sleep' => (int) env('QUEUE_SLEEP', 3),
        'tries' => (int) env('QUEUE_TRIES', 3),
    ],
];