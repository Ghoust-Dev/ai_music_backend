<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Current Qwen Configuration:\n";
echo "==========================\n\n";

echo "QWEN_API_KEY: " . substr(env('QWEN_API_KEY'), 0, 10) . "... (length: " . strlen(env('QWEN_API_KEY')) . ")\n";
echo "QWEN_BASE_URL: " . env('QWEN_BASE_URL') . "\n";
echo "QWEN_MODEL: " . env('QWEN_MODEL') . "\n\n";

echo "Config values:\n";
echo "services.qwen.api_key: " . substr(config('services.qwen.api_key'), 0, 10) . "...\n";
echo "services.qwen.base_url: " . config('services.qwen.base_url') . "\n";
echo "services.qwen.model: " . config('services.qwen.model') . "\n\n";

echo "Issue: Your .env file has the wrong QWEN_BASE_URL\n";
echo "Current: " . env('QWEN_BASE_URL') . "\n";
echo "Should be: https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation\n\n";

echo "Please update your .env file:\n";
echo "QWEN_BASE_URL=https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation\n";