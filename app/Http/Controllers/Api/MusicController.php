<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Services\MusicGenerationService;
use App\Services\LyricsGenerationService;
use App\Services\SingerService;
use App\Services\ConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MusicController extends Controller
{
    protected MusicGenerationService $musicService;
    protected LyricsGenerationService $lyricsService;
    protected SingerService $singerService;
    protected ConversionService $conversionService;

    public function __construct(
        MusicGenerationService $musicService,
        LyricsGenerationService $lyricsService,
        SingerService $singerService,
        ConversionService $conversionService
    ) {
        $this->musicService = $musicService;
        $this->lyricsService = $lyricsService;
        $this->singerService = $singerService;
        $this->conversionService = $conversionService;
    }

    /**
     * Generate music track
     */
    public function generateMusic(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            // Validate request
            $validator = Validator::make($request->all(), [
                'prompt' => 'required|string|max:500',
                'mood' => 'nullable|string|max:50',
                'genre' => 'nullable|string|max:50',
                'instruments' => 'nullable|array',
                'instruments.*' => 'string|max:50',
                'language' => 'nullable|string|max:20',
                'duration' => 'nullable|integer|min:30|max:300',
                'title' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            // Check if user can generate
            $canGenerate = $this->musicService->canUserGenerate($user->id);
            if (!$canGenerate['can_generate']) {
                return response()->json([
                    'success' => false,
                    'message' => $canGenerate['reason'],
                    'data' => $canGenerate
                ], 403);
            }

            // Prepare generation parameters
            $params = [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'prompt' => $request->input('prompt'),
                'mood' => $request->input('mood'),
                'genre' => $request->input('genre'),
                'instruments' => $request->input('instruments', []),
                'language' => $request->input('language', 'english'),
                'duration' => $request->input('duration'),
                'title' => $request->input('title'),
                'content_type' => 'song',
                'is_premium' => $user->isPremium(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            // Generate music
            $result = $this->musicService->generateMusic($params);

            // Increment user usage
            $this->musicService->incrementUsage($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Music generation started successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Music generation failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Music generation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate lyrics
     */
    public function generateLyrics(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            // Validate request
            $validator = Validator::make($request->all(), [
                'prompt' => 'required|string|max:500',
                'mood' => 'nullable|string|max:50',
                'genre' => 'nullable|string|max:50',
                'language' => 'nullable|string|max:20',
                'style' => 'nullable|string|max:50',
                'title' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            // Check if user can generate
            $canGenerate = $this->musicService->canUserGenerate($user->id);
            if (!$canGenerate['can_generate']) {
                return response()->json([
                    'success' => false,
                    'message' => $canGenerate['reason'],
                    'data' => $canGenerate
                ], 403);
            }

            // Prepare generation parameters
            $params = [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'prompt' => $request->input('prompt'),
                'mood' => $request->input('mood'),
                'genre' => $request->input('genre'),
                'language' => $request->input('language', 'english'),
                'style' => $request->input('style'),
                'title' => $request->input('title'),
                'is_premium' => $user->isPremium(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            // Generate lyrics
            $result = $this->lyricsService->generateLyrics($params);

            // Increment user usage
            $this->musicService->incrementUsage($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Lyrics generated successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Lyrics generation failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lyrics generation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Add vocals to existing music
     */
    public function addVocals(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            // Validate request
            $validator = Validator::make($request->all(), [
                'music_file_url' => 'required|url',
                'lyrics' => 'required|string|max:2000',
                'voice_id' => 'nullable|string|max:50',
                'voice_gender' => 'nullable|string|in:male,female',
                'voice_style' => 'nullable|string|max:50',
                'vocal_intensity' => 'nullable|string|in:soft,medium,strong',
                'title' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            // Check if user can generate (vocals count as generation)
            $canGenerate = $this->musicService->canUserGenerate($user->id);
            if (!$canGenerate['can_generate']) {
                return response()->json([
                    'success' => false,
                    'message' => $canGenerate['reason'],
                    'data' => $canGenerate
                ], 403);
            }

            // Prepare generation parameters
            $params = [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'music_file_url' => $request->input('music_file_url'),
                'lyrics' => $request->input('lyrics'),
                'voice_id' => $request->input('voice_id'),
                'voice_gender' => $request->input('voice_gender'),
                'voice_style' => $request->input('voice_style'),
                'vocal_intensity' => $request->input('vocal_intensity'),
                'title' => $request->input('title'),
                'is_premium' => $user->isPremium(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            // Generate vocals
            $result = $this->singerService->generateSinger($params);

            // Increment user usage
            $this->musicService->incrementUsage($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Vocal generation started successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Vocal generation failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Vocal generation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
