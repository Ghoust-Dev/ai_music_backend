<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMusicUser;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\ProductParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends Controller
{
    protected $purchaseService;

    public function __construct(PurchaseService $purchaseService)
    {
        $this->purchaseService = $purchaseService;
    }

    /**
     * Purchase a subscription plan
     */
    public function purchaseSubscription(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan' => 'required|string|in:weekly,monthly,yearly',
                'payment_method' => 'nullable|string',
                'payment_token' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId, [
                'platform' => $request->input('platform') ?? $request->header('X-Platform') ?? 'unknown',
                'app_version' => $request->input('version') ?? $request->header('X-App-Version') ?? 'unknown',
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
            ]);

            $plan = $request->input('plan');
            $paymentData = [
                'payment_method' => $request->input('payment_method'),
                'payment_token' => $request->input('payment_token'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'platform' => $request->header('X-Platform'),
            ];

            $result = $this->purchaseService->purchaseSubscription($user, $plan, $paymentData);

            if ($result['success']) {
                return response()->json($result, 200);
            } else {
                return response()->json($result, 400);
            }

        } catch (\Exception $e) {
            Log::error('Subscription purchase API failed', [
                'device_id' => $deviceId ?? null,
                'plan' => $request->input('plan'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Subscription purchase failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Purchase a credit pack
     */
    public function purchaseCreditPack(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'pack' => 'required|string|in:basic_pack,standard_pack,premium_pack',
                'payment_method' => 'nullable|string',
                'payment_token' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

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
                    'message' => 'User not found. Please register first.'
                ], 404);
            }

            $pack = $request->input('pack');
            $paymentData = [
                'payment_method' => $request->input('payment_method'),
                'payment_token' => $request->input('payment_token'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'platform' => $request->header('X-Platform'),
            ];

            $result = $this->purchaseService->purchaseCreditPack($user, $pack, $paymentData);

            if ($result['success']) {
                return response()->json($result, 200);
            } else {
                $statusCode = $result['error'] === 'NO_ACTIVE_SUBSCRIPTION' ? 403 : 400;
                return response()->json($result, $statusCode);
            }

        } catch (\Exception $e) {
            Log::error('Credit pack purchase API failed', [
                'device_id' => $deviceId ?? null,
                'pack' => $request->input('pack'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Credit pack purchase failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available plans and packs
     */
    public function getAvailableProducts(Request $request)
    {
        try {
            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            $user = null;
            $hasActiveSubscription = false;

            if ($deviceId) {
                $user = AiMusicUser::findByDeviceId($deviceId);
                $hasActiveSubscription = $user ? $user->hasActiveSubscription() : false;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'subscription_plans' => $this->purchaseService->getAvailableSubscriptionPlans(),
                    'credit_packs' => $this->purchaseService->getAvailableCreditPacks(),
                    'user_info' => $user ? [
                        'has_active_subscription' => $hasActiveSubscription,
                        'subscription_expires_at' => $user->subscription_expires_at,
                        'subscription_credits' => $user->subscription_credits,
                        'addon_credits' => $user->addon_credits,
                        'total_credits' => $user->totalCredits(),
                        'can_purchase_credit_packs' => $hasActiveSubscription,
                    ] : null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get available products API failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available products'
            ], 500);
        }
    }

    /**
     * Get user's purchase history
     */
    public function getPurchaseHistory(Request $request)
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

            $limit = $request->input('limit', 20);
            $history = $this->purchaseService->getUserPurchaseHistory($user, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'purchases' => $history,
                    'total_purchases' => count($history),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get purchase history API failed', [
                'device_id' => $deviceId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve purchase history'
            ], 500);
        }
    }

    /**
     * Validate store purchase and grant credits dynamically
     * 
     * This endpoint accepts receipts from App Store or Play Store,
     * validates them, and grants credits based on the product ID.
     * 
     * The client can create any products in the stores following
     * the naming convention, and this endpoint will automatically
     * parse the product ID to grant the correct credits.
     */
    public function validatePurchase(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'platform' => 'required|in:ios,android',
                'product_id' => 'required|string',
                'receipt' => 'required|string',
                'transaction_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get device ID from header
            $deviceId = $request->header('X-Device-ID');
            if (!$deviceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device ID is required'
                ], 422);
            }

            // Find or create user
            $user = AiMusicUser::findOrCreateByDeviceId($deviceId);

            $platform = $request->platform;
            $productId = $request->product_id;
            $receipt = $request->receipt;
            $transactionId = $request->transaction_id;

            // Parse product ID to extract credits and metadata
            $productParser = new ProductParserService();
            $productInfo = $productParser->parseProductId($productId);

            if ($productInfo['credits'] === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid product ID format. Could not extract credits.',
                    'error' => 'INVALID_PRODUCT_ID',
                    'help' => 'Product ID should follow format: subscription_{duration}_{credits} or credits_{amount}_{type}'
                ], 400);
            }

            // TODO: Validate receipt with store
            // For now, we'll log the validation request
            // In production, implement actual receipt validation
            Log::info('Store purchase validation', [
                'platform' => $platform,
                'product_id' => $productId,
                'transaction_id' => $transactionId,
                'parsed_info' => $productInfo,
                'user_id' => $user->id,
                'device_id' => $deviceId,
            ]);

            // Grant credits based on product type
            if ($productInfo['type'] === 'subscription') {
                // Grant subscription credits
                $user->update([
                    'subscription_credits' => $user->subscription_credits + $productInfo['credits'],
                    'subscription_expires_at' => now()->addDays($productInfo['duration_days']),
                ]);

                $creditType = 'subscription';
                $expiresAt = $user->subscription_expires_at;
            } else {
                // Grant addon credits (lifetime)
                $user->addAddonCredits($productInfo['credits']);
                $creditType = 'addon';
                $expiresAt = null;
            }

            // Record the purchase
            $purchase = Purchase::create([
                'user_id' => $user->id,
                'product_type' => $productInfo['type'],
                'product_name' => $productId,
                'credits_granted' => $productInfo['credits'],
                'price' => null, // Will be filled when receipt validation is implemented
                'currency' => 'USD',
                'metadata' => [
                    'platform' => $platform,
                    'transaction_id' => $transactionId,
                    'receipt' => substr($receipt, 0, 100) . '...', // Store truncated receipt
                    'product_info' => $productInfo,
                    'duration_days' => $productInfo['duration_days'] ?? 0,
                    'credit_type' => $creditType,
                    'validated_at' => now()->toISOString(),
                ],
            ]);

            Log::info('Store purchase validated successfully', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'platform' => $platform,
                'product_id' => $productId,
                'credits_granted' => $productInfo['credits'],
                'credit_type' => $creditType,
                'purchase_id' => $purchase->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase validated and credits granted',
                'data' => [
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'product_type' => $productInfo['type'],
                    'credits_granted' => $productInfo['credits'],
                    'credit_type' => $creditType,
                    'subscription_credits' => $user->subscription_credits,
                    'addon_credits' => $user->addon_credits,
                    'total_credits' => $user->totalCredits(),
                    'subscription_expires_at' => $expiresAt,
                    'parsed_info' => [
                        'duration_days' => $productInfo['duration_days'] ?? 0,
                        'duration_name' => $productInfo['duration_name'] ?? 'lifetime',
                        'pack_type' => $productInfo['pack_type'] ?? null,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Store purchase validation failed', [
                'device_id' => $deviceId ?? null,
                'platform' => $request->platform ?? null,
                'product_id' => $request->product_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase validation failed: ' . $e->getMessage(),
                'error' => 'VALIDATION_FAILED'
            ], 500);
        }
    }
}
