<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MusicController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\SubscriptionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your AI Music Generator
| application. These routes are loaded by the RouteServiceProvider and all
| of them will be assigned to the "api" middleware group.
|
*/

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'AI Music Generator Backend',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
    ]);
});

// Test endpoint for development
Route::get('/test', function (Request $request) {
    return response()->json([
        'message' => 'AI Music Generator API is working!',
        'request_headers' => $request->headers->all(),
        'device_id' => $request->header('X-Device-ID'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Device Registration Routes
|--------------------------------------------------------------------------
*/

Route::prefix('device')->group(function () {
    Route::post('/register', [DeviceController::class, 'register']);
    Route::get('/info', [DeviceController::class, 'info']);
});

/*
|--------------------------------------------------------------------------
| Music Generation Routes (Phase 2)
|--------------------------------------------------------------------------
*/

Route::prefix('generate')->group(function () {
    Route::post('/lyrics', [MusicController::class, 'generateLyrics']);
    Route::post('/music', [MusicController::class, 'generateMusic']);
    Route::post('/vocals', [MusicController::class, 'addVocals']);
});

/*
|--------------------------------------------------------------------------
| Task Management Routes (Phase 2)
|--------------------------------------------------------------------------
*/

Route::prefix('task')->group(function () {
    Route::get('/{taskId}/status', [TaskController::class, 'getStatus']);
    Route::post('/multiple-status', [TaskController::class, 'getMultipleStatus']);
    Route::post('/{taskId}/cancel', [TaskController::class, 'cancelTask']);
    Route::post('/{taskId}/retry', [TaskController::class, 'retryTask']);
});

/*
|--------------------------------------------------------------------------
| File Management Routes (Phase 2)
|--------------------------------------------------------------------------
*/

Route::prefix('files')->group(function () {
    // Download generated files
    Route::get('/download/{fileId}', function ($fileId) {
        return response()->json([
            'file_id' => $fileId,
            'message' => 'File download endpoint - Coming in Phase 2'
        ]);
    });
    
    // Convert file formats
    Route::post('/convert', function () {
        return response()->json(['message' => 'File conversion endpoint - Coming in Phase 2']);
    });
});

/*
|--------------------------------------------------------------------------
| User Content Routes (Phase 2)
|--------------------------------------------------------------------------
*/

Route::prefix('content')->group(function () {
    Route::get('/list', [ContentController::class, 'list']);
    Route::get('/usage', [ContentController::class, 'usage']);
    Route::get('/{contentId}', [ContentController::class, 'show']);
    Route::put('/{contentId}', [ContentController::class, 'update']);
    Route::delete('/{contentId}', [ContentController::class, 'delete']);
    
    // Update song title - Headers: id, device_id | Body: { "title": "New Title" }
    Route::post('/', [ContentController::class, 'updateTitle']);
    
    // Delete song permanently - Headers: id, device_id
    Route::post('/delete', [ContentController::class, 'deleteSong']);
    
    // Move song to trash (soft delete) - Headers: id, device_id
    Route::post('/trash', [ContentController::class, 'moveToTrash']);
    
    // Restore song from trash - Headers: id, device_id
    Route::post('/restore', [ContentController::class, 'restoreFromTrash']);
    
    // Smart retry endpoint - checks status before creating new generation
    Route::post('/{contentId}/smart-retry', [MusicController::class, 'smartRetryGeneration']);
});

/*
|--------------------------------------------------------------------------
| Purchase & Subscription Routes (Phase 4)
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\UserController;

// User profile routes
Route::prefix('user')->group(function () {
    Route::get('/profile', [UserController::class, 'getProfile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
});

// Quota endpoint - returns user's generation quota information
Route::get('/quota', [UserController::class, 'getQuota']);

// Purchase routes - new credit-based system
Route::prefix('purchase')->group(function () {
    // New dynamic validation endpoint (supports any product from stores)
    Route::post('/validate', [PurchaseController::class, 'validatePurchase']);
    
    // Legacy endpoints (for backward compatibility)
    Route::post('/subscription', [PurchaseController::class, 'purchaseSubscription']);
    Route::post('/credits', [PurchaseController::class, 'purchaseCreditPack']);
    Route::get('/products', [PurchaseController::class, 'getAvailableProducts']);
    Route::get('/history', [PurchaseController::class, 'getPurchaseHistory']);
});

// Legacy subscription routes (for backward compatibility)
Route::prefix('subscription')->group(function () {
    Route::post('/validate', [SubscriptionController::class, 'validatePurchase']);
    Route::get('/status', [SubscriptionController::class, 'status']);
});

/*
|--------------------------------------------------------------------------
| Qwen AI Creative Content Generation Routes
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\QwenAiController;

Route::prefix('qwen')->group(function () {
    // Test connection
    Route::get('/test', [QwenAiController::class, 'testConnection']);
    
    // Get available options (moods, genres, languages)
    Route::get('/options', [QwenAiController::class, 'getOptions']);
    
    // Random generators
    Route::post('/random-prompt', [QwenAiController::class, 'generateRandomPrompt']);
    Route::post('/random-lyrics', [QwenAiController::class, 'generateRandomLyrics']);
    Route::post('/random-instrumental', [QwenAiController::class, 'generateRandomInstrumental']);
    
    // Custom lyrics from description
    Route::post('/custom-lyrics', [QwenAiController::class, 'generateCustomLyrics']);
});

/*
|--------------------------------------------------------------------------
| RevenueCat Webhook Routes (Phase 8)
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\WebhookController;

Route::prefix('webhooks')->group(function () {
    // RevenueCat webhook endpoint (with signature verification middleware)
    Route::post('/revenuecat', [WebhookController::class, 'revenueCat'])
        ->middleware('verify.revenuecat.webhook');
    
    // Test endpoint (no authentication required)
    Route::get('/revenuecat/test', [WebhookController::class, 'test']);
});