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

            // Note: Monthly usage reset no longer needed with credit-based system

            return response()->json([
                'success' => true,
                'message' => $user->wasRecentlyCreated ? 'Device registered successfully' : 'Device retrieved successfully',
                'data' => [
                    'user_id' => $user->id,
                    'device_id' => $user->device_id,
                    'subscription_credits' => $user->subscription_credits,
                    'addon_credits' => $user->addon_credits,
                    'total_credits' => $user->totalCredits(),
                    'has_active_subscription' => $user->hasActiveSubscription(),
                    'subscription_expires_at' => $user->subscription_expires_at,
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
     * Get device information (with enhanced auto-registration)
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

            // Prepare enhanced device info for auto-registration
            $deviceInfo = [
                'platform' => $request->input('platform') ?? $request->header('X-Platform') ?? 'unknown',
                'app_version' => $request->input('version') ?? $request->header('X-App-Version') ?? 'unknown',
                'device_model' => $request->input('model') ?? $request->header('X-Device-Model') ?? 'unknown',
                'os_version' => $request->input('os_version') ?? $request->header('X-OS-Version') ?? null,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'auto_created_at' => now()->toISOString(),
            ];

            // Find or create user (enhanced auto-registration)
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, $deviceInfo);

            // Update last active
            $user->update(['last_active_at' => now()]);

            // Reset monthly usage if needed
            $user->resetMonthlyUsageIfNeeded();

            Log::info('Device info retrieved/auto-registered', [
                'device_id' => $deviceId,
                'user_id' => $user->id,
                'platform' => $deviceInfo['platform'],
                'app_version' => $deviceInfo['app_version'],
                'device_model' => $deviceInfo['device_model'],
                'was_created' => $user->wasRecentlyCreated
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'device_id' => $user->device_id,
                    'subscription_credits' => $user->subscription_credits,
                    'addon_credits' => $user->addon_credits,
                    'total_credits' => $user->totalCredits(),
                    'has_active_subscription' => $user->hasActiveSubscription(),
                    'subscription_expires_at' => $user->subscription_expires_at,
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