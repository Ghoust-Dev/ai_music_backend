<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    /**
     * Register or retrieve device information
     */
    public function register(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'device_id' => 'required|string|max:255',
                'platform' => 'required|string|in:ios,android,web',
                'app_version' => 'nullable|string|max:50',
                'device_model' => 'nullable|string|max:100',
                'os_version' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $deviceId = $request->input('device_id');
            
            // Prepare device info
            $deviceInfo = [
                'platform' => $request->input('platform'),
                'app_version' => $request->input('app_version'),
                'device_model' => $request->input('device_model'),
                'os_version' => $request->input('os_version'),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'registered_at' => now()->toISOString(),
            ];

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, $deviceInfo);

            Log::info('Device registered/retrieved', [
                'device_id' => $deviceId,
                'user_id' => $user->id,
                'platform' => $request->input('platform'),
                'is_new_user' => $user->wasRecentlyCreated
            ]);

            // Reset monthly usage if needed
            $user->resetMonthlyUsageIfNeeded();

            return response()->json([
                'success' => true,
                'message' => $user->wasRecentlyCreated ? 'Device registered successfully' : 'Device retrieved successfully',
                'data' => [
                    'user_id' => $user->id,
                    'device_id' => $user->device_id,
                    'subscription_status' => $user->subscription_status,
                    'usage_count' => $user->usage_count,
                    'monthly_usage' => $user->monthly_usage,
                    'can_generate' => $user->canGenerate(),
                    'remaining_generations' => $user->remainingGenerations(),
                    'is_premium' => $user->isPremium(),
                    'last_active_at' => $user->last_active_at,
                    'created_at' => $user->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Device registration failed', [
                'error' => $e->getMessage(),
                'device_id' => $request->input('device_id')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Device registration failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get device information
     */
    public function info(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID') ?? $request->input('device_id');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            $user = AiMusicUser::findByDeviceId($deviceId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not found'
                ], 404);
            }

            // Update last active
            $user->update(['last_active_at' => now()]);

            // Reset monthly usage if needed
            $user->resetMonthlyUsageIfNeeded();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'device_id' => $user->device_id,
                    'subscription_status' => $user->subscription_status,
                    'usage_count' => $user->usage_count,
                    'monthly_usage' => $user->monthly_usage,
                    'can_generate' => $user->canGenerate(),
                    'remaining_generations' => $user->remainingGenerations(),
                    'is_premium' => $user->isPremium(),
                    'device_info' => $user->device_info,
                    'last_active_at' => $user->last_active_at,
                    'created_at' => $user->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Device info retrieval failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve device information',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
