<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QwenAiService;
use App\Models\AiMusicUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Qwen AI Controller
 * 
 * Handles creative content generation using Qwen AI:
 * - Random song prompts
 * - Random lyrics
 * - Custom lyrics from description
 * - Random instrumental prompts
 */
class QwenAiController extends Controller
{
    protected QwenAiService $qwenService;

    public function __construct(QwenAiService $qwenService)
    {
        $this->qwenService = $qwenService;
    }

    /**
     * Generate a random song prompt
     * 
     * POST /api/qwen/random-prompt
     */
    public function generateRandomPrompt(Request $request)
    {
        try {
            // Get device ID
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId);

            // Get optional parameters
            $options = [];
            if ($request->has('mood')) {
                $options['mood'] = $request->input('mood');
            }
            if ($request->has('genre')) {
                $options['genre'] = $request->input('genre');
            }

            Log::info('Qwen AI: Generating random song prompt', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'options' => $options
            ]);

            // Generate prompt
            $result = $this->qwenService->generateRandomSongPrompt($options);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate prompt',
                    'error' => $result['error'] ?? 'Unknown error',
                    'fallback_prompt' => $result['fallback_prompt'] ?? null
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Random prompt generated successfully',
                'data' => [
                    'prompt' => $result['prompt'],
                    'metadata' => $result['metadata']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Qwen AI: Random prompt generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate random prompt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate random lyrics
     * 
     * POST /api/qwen/random-lyrics
     */
    public function generateRandomLyrics(Request $request)
    {
        try {
            // Get device ID
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId);

            // Get optional parameters
            $options = [];
            if ($request->has('mood')) {
                $options['mood'] = $request->input('mood');
            }
            if ($request->has('genre')) {
                $options['genre'] = $request->input('genre');
            }
            if ($request->has('theme')) {
                $options['theme'] = $request->input('theme');
            }
            if ($request->has('length')) {
                $options['length'] = $request->input('length'); // short, medium, long
            }

            Log::info('Qwen AI: Generating random lyrics', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'options' => $options
            ]);

            // Generate lyrics
            $result = $this->qwenService->generateRandomLyrics($options);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate lyrics',
                    'error' => $result['error'] ?? 'Unknown error'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Random lyrics generated successfully',
                'data' => [
                    'lyrics' => $result['lyrics'],
                    'metadata' => $result['metadata']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Qwen AI: Random lyrics generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate random lyrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate custom lyrics from description
     * 
     * POST /api/qwen/custom-lyrics
     */
    public function generateCustomLyrics(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'description' => 'required|string|min:5|max:500',
                'language' => 'nullable|string|max:50',
                'mood' => 'nullable|string|max:50',
                'genre' => 'nullable|string|max:50',
                'style' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get device ID
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId);

            $description = $request->input('description');
            $language = $request->input('language', 'english');
            
            $options = [];
            if ($request->has('mood')) {
                $options['mood'] = $request->input('mood');
            }
            if ($request->has('genre')) {
                $options['genre'] = $request->input('genre');
            }
            if ($request->has('style')) {
                $options['style'] = $request->input('style');
            }

            Log::info('Qwen AI: Generating custom lyrics from description', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'description' => $description,
                'language' => $language,
                'options' => $options
            ]);

            // Generate lyrics
            $result = $this->qwenService->generateLyricsFromDescription($description, $language, $options);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate custom lyrics',
                    'error' => $result['error'] ?? 'Unknown error'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Custom lyrics generated successfully',
                'data' => [
                    'lyrics' => $result['lyrics'],
                    'metadata' => $result['metadata']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Qwen AI: Custom lyrics generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate custom lyrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate random instrumental prompt
     * 
     * POST /api/qwen/random-instrumental
     */
    public function generateRandomInstrumental(Request $request)
    {
        try {
            // Get device ID
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId);

            // Get optional parameters
            $options = [];
            if ($request->has('mood')) {
                $options['mood'] = $request->input('mood');
            }
            if ($request->has('genre')) {
                $options['genre'] = $request->input('genre');
            }
            if ($request->has('instruments')) {
                $options['instruments'] = $request->input('instruments'); // array
            }

            Log::info('Qwen AI: Generating random instrumental prompt', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'options' => $options
            ]);

            // Generate prompt
            $result = $this->qwenService->generateRandomInstrumentalPrompt($options);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate instrumental prompt',
                    'error' => $result['error'] ?? 'Unknown error',
                    'fallback_prompt' => $result['fallback_prompt'] ?? null
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Random instrumental prompt generated successfully',
                'data' => [
                    'prompt' => $result['prompt'],
                    'metadata' => $result['metadata']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Qwen AI: Random instrumental prompt generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate random instrumental prompt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Qwen AI connection
     * 
     * GET /api/qwen/test
     */
    public function testConnection(Request $request)
    {
        try {
            $result = $this->qwenService->testConnection();

            return response()->json($result, $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Qwen AI connection test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available options (moods, genres, languages)
     * 
     * GET /api/qwen/options
     */
    public function getOptions(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'moods' => config('qwen.moods'),
                'genres' => config('qwen.genres'),
                'languages' => config('qwen.supported_languages'),
                'lengths' => ['short', 'medium', 'long'],
                'features' => config('qwen.features')
            ]
        ]);
    }
}