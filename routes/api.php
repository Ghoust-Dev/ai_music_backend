<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MusicController;

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
    Route::put('/update', [DeviceController::class, 'update']);
    Route::get('/stats', [DeviceController::class, 'stats']);
    Route::post('/can-perform', [DeviceController::class, 'canPerformAction']);
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
    // Check task status
    Route::get('/{taskId}/status', function ($taskId) {
        return response()->json([
            'task_id' => $taskId,
            'message' => 'Task status endpoint - Coming in Phase 2'
        ]);
    });
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
    // List user's generated content
    Route::get('/list', function () {
        return response()->json(['message' => 'Content listing endpoint - Coming in Phase 2']);
    });
    
    // Get user's usage stats
    Route::get('/usage', function () {
        return response()->json(['message' => 'Usage stats endpoint - Coming in Phase 2']);
    });
});

/*
|--------------------------------------------------------------------------
| Subscription Routes (Phase 4)
|--------------------------------------------------------------------------
*/

Route::prefix('subscription')->group(function () {
    // Validate purchase receipt
    Route::post('/validate', function () {
        return response()->json(['message' => 'Purchase validation endpoint - Coming in Phase 4']);
    });
    
    // Get subscription status
    Route::get('/status', function () {
        return response()->json(['message' => 'Subscription status endpoint - Coming in Phase 4']);
    });
});