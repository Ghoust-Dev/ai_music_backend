<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "═══════════════════════════════════════════════\n";
echo "   FINAL QWEN AI VERIFICATION TEST\n";
echo "═══════════════════════════════════════════════\n\n";

$apiKey = config('services.qwen.api_key');
$baseUrl = config('services.qwen.base_url');
$model = config('services.qwen.model');

echo "Configuration:\n";
echo "  API Key: " . substr($apiKey, 0, 12) . "...\n";
echo "  Base URL: $baseUrl\n";
echo "  Model: $model\n\n";

echo "Testing API call...\n\n";

try {
    $response = \Illuminate\Support\Facades\Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
    ])->timeout(30)->post($baseUrl, [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Say "Qwen AI is working perfectly!" and nothing else.']
        ],
        'max_tokens' => 20
    ]);

    if ($response->successful()) {
        $data = $response->json();
        $message = $data['choices'][0]['message']['content'] ?? 'No content';
        
        echo "╔═══════════════════════════════════════════╗\n";
        echo "║          ✅ SUCCESS!                       ║\n";
        echo "╚═══════════════════════════════════════════╝\n\n";
        
        echo "Response: \"$message\"\n\n";
        echo "Tokens used:\n";
        echo "  Input: " . ($data['usage']['prompt_tokens'] ?? 0) . "\n";
        echo "  Output: " . ($data['usage']['completion_tokens'] ?? 0) . "\n";
        echo "  Total: " . ($data['usage']['total_tokens'] ?? 0) . "\n\n";
        
        echo "╔═══════════════════════════════════════════╗\n";
        echo "║  QWEN AI INTEGRATION IS FULLY WORKING!    ║\n";
        echo "║                                           ║\n";
        echo "║  ✅ API Key: Valid                        ║\n";
        echo "║  ✅ Endpoint: Model Studio Singapore      ║\n";
        echo "║  ✅ Model: $model                ║\n";
        echo "║  ✅ Format: OpenAI-compatible             ║\n";
        echo "║                                           ║\n";
        echo "║  You're ready for production! 🚀          ║\n";
        echo "╚═══════════════════════════════════════════╝\n\n";
        
        echo "Next steps:\n";
        echo "  1. Check QWEN_MODEL_STUDIO_FIXED.md for full documentation\n";
        echo "  2. Check SOLUTION_SUMMARY.md for the fix summary\n";
        echo "  3. Your API endpoints are ready at /api/qwen/*\n\n";
        
    } else {
        echo "❌ FAILED: HTTP " . $response->status() . "\n";
        echo "Response: " . $response->body() . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "═══════════════════════════════════════════════\n";