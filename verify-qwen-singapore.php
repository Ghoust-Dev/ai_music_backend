<?php

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🇸🇬 Qwen Singapore Region Verification\n";
echo "=====================================\n\n";

// Check current configuration
$apiKey = config('services.qwen.api_key');
$model = config('services.qwen.model');
$baseUrl = config('services.qwen.base_url');

echo "Current Configuration:\n";
echo "  API Key: " . substr($apiKey, 0, 10) . "... (length: " . strlen($apiKey) . ")\n";
echo "  Model: $model\n";
echo "  Base URL: $baseUrl\n\n";

if (empty($apiKey) || $apiKey === 'your-api-key-here') {
    echo "❌ ERROR: No API key configured!\n";
    echo "Please update your .env file with the Singapore API key.\n";
    exit(1);
}

echo "Testing API connection...\n\n";

// Test API call
$requestData = [
    'model' => $model,
    'input' => [
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Say "Singapore region test successful!" and nothing else.'
            ]
        ]
    ],
    'parameters' => [
        'max_tokens' => 50,
        'temperature' => 0.7,
        'result_format' => 'message'
    ]
];

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
        'X-DashScope-SSE' => 'disable'
    ])
    ->timeout(30)
    ->post($baseUrl, $requestData);

    echo "Response Status: " . $response->status() . "\n";
    
    if ($response->successful()) {
        $data = $response->json();
        echo "✅ SUCCESS! Singapore region is working!\n\n";
        
        if (isset($data['output']['choices'][0]['message']['content'])) {
            echo "AI Response: " . $data['output']['choices'][0]['message']['content'] . "\n\n";
        }
        
        echo "🎉 Your Qwen AI integration is ready!\n";
        echo "You can now use all the endpoints:\n";
        echo "  - POST /api/qwen/random-prompt\n";
        echo "  - POST /api/qwen/random-lyrics\n";
        echo "  - POST /api/qwen/custom-lyrics\n";
        echo "  - POST /api/qwen/random-instrumental\n\n";
        
        echo "Test with curl:\n";
        echo "curl -X POST http://localhost:8000/api/qwen/random-lyrics \\\n";
        echo "  -H \"X-Device-ID: test-123\" \\\n";
        echo "  -H \"Content-Type: application/json\" \\\n";
        echo "  -d '{\"mood\": \"happy\", \"genre\": \"pop\"}'\n";
        
    } else {
        $error = $response->json();
        echo "❌ FAILED! API returned error:\n";
        echo "Status: " . $response->status() . "\n";
        
        if (isset($error['code'])) {
            echo "Error Code: " . $error['code'] . "\n";
        }
        if (isset($error['message'])) {
            echo "Error Message: " . $error['message'] . "\n";
        }
        
        echo "\n🔧 Troubleshooting:\n";
        echo "1. Verify you're in Singapore region in Alibaba Cloud console\n";
        echo "2. Check if your API key is for Singapore region\n";
        echo "3. Ensure DashScope service is activated\n";
        echo "4. Try generating a new API key\n";
        echo "5. Check your .env file has the correct key\n";
    }

} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "\n🔧 Troubleshooting:\n";
    echo "1. Check your internet connection\n";
    echo "2. Verify the API endpoint is accessible\n";
    echo "3. Check firewall/proxy settings\n";
}

echo "\n=====================================\n";
echo "Verification Complete\n";
echo "=====================================\n";