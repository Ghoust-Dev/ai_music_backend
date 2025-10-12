<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'runware' => [
        'api_key' => env('RUNWARE_API_KEY'),
        'base_url' => env('RUNWARE_BASE_URL', 'https://api.runware.ai/v1'),
        'model' => env('RUNWARE_MODEL', 'runware:100@1'), // FLUX.1 [schnell]
        'timeout' => env('RUNWARE_TIMEOUT', 30),
    ],

    'qwen' => [
        'api_key' => env('QWEN_API_KEY'),
        // Model Studio Singapore region endpoint (OpenAI-compatible)
        'base_url' => env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions'),
        'model' => env('QWEN_MODEL', 'qwen-turbo'), // qwen-turbo (Flash), qwen-plus, qwen-max, qwen-flash
        'timeout' => env('QWEN_TIMEOUT', 30),
        'max_retries' => env('QWEN_MAX_RETRIES', 3),
    ],

    'revenuecat' => [
        'api_key' => env('REVENUECAT_API_KEY'),
        'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
        'public_api_key' => env('REVENUECAT_PUBLIC_API_KEY'), // Optional: For REST API calls
    ],

];
