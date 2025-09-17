<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Get current subscription status
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $deviceId = $request->header('X-Device-ID');
            
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 400);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not registered'
                ], 404);
            }

            $activeSubscription = $user->activeSubscription();

            return response()->json([
                'success' => true,
                'data' => [
                    'user_info' => [
                        'subscription_status' => $user->subscription_status,
                        'is_premium' => $user->isPremium(),
                        'usage_count' => $user->usage_count,
                        'monthly_usage' => $user->monthly_usage,
                        'can_generate' => $user->canGenerate(),
                        'remaining_generations' => $user->remainingGenerations(),
                    ],
                    'active_subscription' => $activeSubscription ? [
                        'id' => $activeSubscription->id,
                        'type' => $activeSubscription->subscription_type,
                        'status' => $activeSubscription->status,
                        'platform' => $activeSubscription->platform,
                        'starts_at' => $activeSubscription->starts_at,
                        'expires_at' => $activeSubscription->expires_at,
                        'auto_renewal' => $activeSubscription->auto_renewal,
                        'generations_included' => $activeSubscription->generations_included,
                        'generations_used' => $activeSubscription->generations_used,
                        'features_enabled' => $activeSubscription->features_enabled,
                        'days_remaining' => $activeSubscription->expires_at ? 
                            max(0, now()->diffInDays($activeSubscription->expires_at, false)) : null,
                    ] : null,
                    'subscription_plans' => $this->getAvailablePlans(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Subscription status check failed', [
                'error' => $e->getMessage(),
                'device_id' => $deviceId ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get subscription status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get available subscription plans
     */
    private function getAvailablePlans(): array
    {
        return [
            'free' => [
                'name' => 'Free',
                'price' => 0,
                'currency' => 'USD',
                'duration' => 'unlimited',
                'generations_per_month' => 5,
                'features' => ['Basic music generation', 'Standard quality'],
            ],
            'premium_monthly' => [
                'name' => 'Premium Monthly',
                'price' => 9.99,
                'currency' => 'USD',
                'duration' => '1 month',
                'generations_per_month' => 100,
                'features' => ['Unlimited music generation', 'High quality', 'Priority processing', 'Commercial use'],
            ],
            'premium_yearly' => [
                'name' => 'Premium Yearly',
                'price' => 99.99,
                'currency' => 'USD',
                'duration' => '1 year',
                'generations_per_month' => 'unlimited',
                'features' => ['Unlimited music generation', 'High quality', 'Priority processing', 'Commercial use', '2 months free'],
            ],
        ];
    }
}
