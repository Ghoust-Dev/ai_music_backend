<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    protected $purchaseService;

    public function __construct(PurchaseService $purchaseService)
    {
        $this->purchaseService = $purchaseService;
    }

    /**
     * Get comprehensive user profile
     */
    public function getProfile(Request $request)
    {
        try {
            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Get purchase history (last 5 purchases)
            $recentPurchases = $this->purchaseService->getUserPurchaseHistory($user, 5);
            
            // Calculate days until expiration
            $daysUntilExpiration = null;
            if ($user->subscription_expires_at) {
                $daysUntilExpiration = max(0, now()->diffInDays($user->subscription_expires_at, false));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user_info' => [
                        'user_id' => $user->id,
                        'device_id' => $user->device_id,
                        'created_at' => $user->created_at,
                        'last_active_at' => $user->last_active_at,
                    ],
                    'subscription_info' => [
                        'has_active_subscription' => $user->hasActiveSubscription(),
                        'subscription_expires_at' => $user->subscription_expires_at,
                        'days_until_expiration' => $daysUntilExpiration,
                        'is_expired' => $user->isSubscriptionExpired(),
                    ],
                    'credits_info' => [
                        'subscription_credits' => $user->subscription_credits,
                        'addon_credits' => $user->addon_credits,
                        'total_credits' => $user->totalCredits(),
                        'can_generate' => $user->canGenerate(),
                    ],
                    'purchase_info' => [
                        'can_purchase_credit_packs' => $user->hasActiveSubscription(),
                        'total_purchases' => $user->purchases()->count(),
                        'recent_purchases' => $recentPurchases,
                    ],
                    'generation_info' => [
                        'total_generated' => $user->generatedContent()->count(),
                        'completed_generations' => $user->generatedContent()->where('status', 'completed')->count(),
                        'failed_generations' => $user->generatedContent()->where('status', 'failed')->count(),
                    ],
                    'available_products' => [
                        'subscription_plans' => $this->purchaseService->getAvailableSubscriptionPlans(),
                        'credit_packs' => $this->purchaseService->getAvailableCreditPacks(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get user profile API failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user profile'
            ], 500);
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find user
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Update allowed fields
            $updateData = [];
            
            if ($request->has('device_info')) {
                $updateData['device_info'] = array_merge(
                    $user->device_info ?? [], 
                    $request->input('device_info', [])
                );
            }

            if (!empty($updateData)) {
                $updateData['last_active_at'] = now();
                $user->update($updateData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user_id' => $user->id,
                    'device_id' => $user->device_id,
                    'device_info' => $user->device_info,
                    'last_active_at' => $user->last_active_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Update user profile API failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user profile'
            ], 500);
        }
    }

    /**
     * Get user quota information
     * 
     * Returns quota details including total, used, and remaining generations
     */
    public function getQuota(Request $request)
    {
        try {
            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Device ID required',
                    'message' => 'X-Device-ID header is required'
                ], 422);
            }

            // Find user by device ID
            $user = AiMusicUser::findByDeviceId($deviceId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not found',
                    'message' => 'Device ID not registered'
                ], 404);
            }

            // Calculate used generations (count of completed content)
            $usedGenerations = $user->generatedContent()
                ->where('status', 'completed')
                ->count();

            // Get remaining generations (current available credits)
            $remainingGenerations = $user->totalCredits();

            // Calculate total generations (used + remaining)
            $totalGenerations = $usedGenerations + $remainingGenerations;

            // Determine reset date
            // For premium users: subscription expiration date
            // For free users: first day of next month
            $resetDate = null;
            if ($user->hasActiveSubscription() && $user->subscription_expires_at) {
                $resetDate = $user->subscription_expires_at->toIso8601String();
            } else {
                // First day of next month for free users
                $resetDate = now()->startOfMonth()->addMonth()->toIso8601String();
            }

            // Check if user is premium
            $isPremium = $user->hasActiveSubscription();
            
            // Get premium expiration date
            $premiumExpiresAt = $user->subscription_expires_at 
                ? $user->subscription_expires_at->toIso8601String() 
                : null;

            // Update last active timestamp
            $user->update(['last_active_at' => now()]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_generations' => $totalGenerations,
                    'used_generations' => $usedGenerations,
                    'remaining_generations' => $remainingGenerations,
                    'is_premium' => $isPremium,
                    'premium_expires_at' => $premiumExpiresAt,
                    'reset_date' => $resetDate,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get quota API failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => 'Failed to retrieve quota information'
            ], 500);
        }
    }
}
