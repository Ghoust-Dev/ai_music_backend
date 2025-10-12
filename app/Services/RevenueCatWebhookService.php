<?php

namespace App\Services;

use App\Models\AiMusicUser;
use App\Models\Subscription;
use App\Models\Purchase;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * RevenueCat Webhook Service
 * 
 * Processes webhook events from RevenueCat and updates the database accordingly.
 */
class RevenueCatWebhookService
{
    /**
     * Process a RevenueCat webhook event
     * 
     * @param array $event
     * @return array
     */
    public function processEvent(array $event): array
    {
        $eventType = $event['type'] ?? null;
        $appUserId = $event['app_user_id'] ?? null;

        if (!$eventType || !$appUserId) {
            return [
                'success' => false,
                'message' => 'Missing required event data (type or app_user_id)',
            ];
        }

        Log::info("📊 [WEBHOOK SERVICE] Processing event", [
            'event_type' => $eventType,
            'app_user_id' => $appUserId,
        ]);

        // Log webhook event to database (for audit trail)
        $webhookEvent = WebhookEvent::create([
            'event_type' => $eventType,
            'app_user_id' => $appUserId,
            'product_id' => $event['product_id'] ?? null,
            'platform' => $this->detectPlatform($event),
            'transaction_id' => $event['transaction_id'] ?? null,
            'event_data' => $event,
            'processed' => false,
            'received_at' => now(),
        ]);

        try {
            // Route to appropriate handler based on event type
            switch ($eventType) {
                case 'INITIAL_PURCHASE':
                    $result = $this->handleInitialPurchase($event);
                    break;
                
                case 'RENEWAL':
                    $result = $this->handleRenewal($event);
                    break;
                
                case 'CANCELLATION':
                    $result = $this->handleCancellation($event);
                    break;
                
                case 'EXPIRATION':
                    $result = $this->handleExpiration($event);
                    break;
                
                case 'BILLING_ISSUE':
                    $result = $this->handleBillingIssue($event);
                    break;
                
                case 'PRODUCT_CHANGE':
                    $result = $this->handleProductChange($event);
                    break;
                
                case 'NON_RENEWING_PURCHASE':
                    $result = $this->handleNonRenewingPurchase($event);
                    break;
                
                default:
                    Log::warning("⚠️ [WEBHOOK SERVICE] Unknown event type: {$eventType}");
                    $result = [
                        'success' => true,
                        'message' => "Unknown event type: {$eventType} (ignored)",
                        'event_type' => $eventType,
                    ];
            }

            // Mark webhook event as processed
            $webhookEvent->markAsProcessed(
                $result['success'] ? 'Success: ' . ($result['message'] ?? 'Processed') : 'Failed: ' . ($result['message'] ?? 'Unknown error')
            );

            return $result;

        } catch (\Exception $e) {
            // Mark webhook event as failed
            $webhookEvent->markAsProcessed('Exception: ' . $e->getMessage());

            Log::error("💥 [WEBHOOK SERVICE] Error processing event", [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
                'event_type' => $eventType,
            ];
        }
    }

    /**
     * Handle INITIAL_PURCHASE event
     * User subscribes for the first time
     */
    protected function handleInitialPurchase(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;
        $expirationAtMs = $event['expiration_at_ms'] ?? null;
        $environment = $event['environment'] ?? 'PRODUCTION';

        Log::info("🎉 [WEBHOOK] Initial Purchase", [
            'device_id' => $deviceId,
            'product_id' => $productId,
            'environment' => $environment,
        ]);

        DB::beginTransaction();
        try {
            // Find or create user
            $user = AiMusicUser::firstOrCreate(
                ['device_id' => $deviceId],
                [
                    'subscription_credits' => 0,
                    'addon_credits' => 0,
                    'last_active_at' => now(),
                ]
            );

            // Parse product ID to get credits and duration
            $productParser = new ProductParserService();
            $productInfo = $productParser->parseProductId($productId);

            // Calculate expiration date
            $expiresAt = $expirationAtMs 
                ? Carbon::createFromTimestampMs($expirationAtMs)
                : $this->calculateExpirationDate($productInfo['duration_name'] ?? 'monthly');

            // Grant subscription credits
            $user->update([
                'subscription_credits' => $productInfo['credits'],
                'subscription_expires_at' => $expiresAt,
            ]);

            // Create subscription record
            Subscription::create([
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'subscription_type' => $this->mapDurationToSubscriptionType($productInfo['duration_name'] ?? 'monthly'),
                'status' => 'active',
                'platform' => $this->detectPlatform($event),
                'platform_product_id' => $productId,
                'platform_transaction_id' => $event['transaction_id'] ?? null,
                'platform_original_transaction_id' => $event['original_transaction_id'] ?? null,
                'starts_at' => $event['purchased_at_ms'] 
                    ? Carbon::createFromTimestampMs($event['purchased_at_ms'])
                    : now(),
                'expires_at' => $expiresAt,
                'auto_renewal' => true,
                'price' => $event['price'] ?? null,
                'currency' => $event['currency'] ?? 'USD',
                'generations_included' => $productInfo['credits'],
                'generations_used' => 0,
            ]);

            // Create purchase record
            Purchase::create([
                'user_id' => $user->id,
                'product_type' => 'subscription',
                'product_name' => $this->mapDurationToProductName($productInfo['duration_name'] ?? 'monthly'),
                'credits_granted' => $productInfo['credits'],
                'price' => $event['price'] ?? 0,
                'currency' => $event['currency'] ?? 'USD',
                'metadata' => [
                    'device_id' => $deviceId,
                    'product_id' => $productId,
                    'platform' => $this->detectPlatform($event),
                    'transaction_id' => $event['transaction_id'] ?? null,
                    'environment' => $environment,
                    'event_type' => 'INITIAL_PURCHASE',
                ],
            ]);

            DB::commit();

            Log::info("✅ [WEBHOOK] Granted premium access", [
                'device_id' => $deviceId,
                'credits' => $productInfo['credits'],
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return [
                'success' => true,
                'message' => 'Premium access granted',
                'event_type' => 'INITIAL_PURCHASE',
                'user_id' => $user->id,
                'action' => 'granted_premium',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle RENEWAL event
     * Subscription auto-renews
     */
    protected function handleRenewal(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;
        $expirationAtMs = $event['expiration_at_ms'] ?? null;

        Log::info("🔄 [WEBHOOK] Renewal", [
            'device_id' => $deviceId,
            'product_id' => $productId,
        ]);

        DB::beginTransaction();
        try {
            $user = AiMusicUser::where('device_id', $deviceId)->first();

            if (!$user) {
                Log::warning("⚠️ [WEBHOOK] User not found for renewal: {$deviceId}");
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'event_type' => 'RENEWAL',
                ];
            }

            // Parse product ID
            $productParser = new ProductParserService();
            $productInfo = $productParser->parseProductId($productId);

            // Calculate new expiration
            $expiresAt = $expirationAtMs 
                ? Carbon::createFromTimestampMs($expirationAtMs)
                : $this->calculateExpirationDate($productInfo['duration_name'] ?? 'monthly');

            // Renew subscription credits
            $user->update([
                'subscription_credits' => $productInfo['credits'],
                'subscription_expires_at' => $expiresAt,
            ]);

            // Update subscription record
            $subscription = Subscription::where('device_id', $deviceId)
                ->where('platform_product_id', $productId)
                ->where('status', 'active')
                ->first();

            if ($subscription) {
                $subscription->update([
                    'expires_at' => $expiresAt,
                    'status' => 'active',
                    'generations_used' => 0, // Reset monthly usage
                    'usage_reset_at' => now(),
                ]);
            }

            // Log renewal in purchases
            Purchase::create([
                'user_id' => $user->id,
                'product_type' => 'subscription',
                'product_name' => $this->mapDurationToProductName($productInfo['duration_name'] ?? 'monthly') . '_renewal',
                'credits_granted' => $productInfo['credits'],
                'price' => $event['price'] ?? 0,
                'currency' => $event['currency'] ?? 'USD',
                'metadata' => [
                    'device_id' => $deviceId,
                    'product_id' => $productId,
                    'platform' => $this->detectPlatform($event),
                    'transaction_id' => $event['transaction_id'] ?? null,
                    'event_type' => 'RENEWAL',
                ],
            ]);

            DB::commit();

            Log::info("✅ [WEBHOOK] Subscription renewed", [
                'device_id' => $deviceId,
                'credits' => $productInfo['credits'],
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return [
                'success' => true,
                'message' => 'Subscription renewed',
                'event_type' => 'RENEWAL',
                'user_id' => $user->id,
                'action' => 'renewed_subscription',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle CANCELLATION event
     * User turns off auto-renewal (keeps access until expiration)
     */
    protected function handleCancellation(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;
        $expirationAtMs = $event['expiration_at_ms'] ?? null;
        $cancellationReason = $event['cancellation_reason'] ?? 'user_cancelled';

        Log::info("⚠️ [WEBHOOK] Cancellation", [
            'device_id' => $deviceId,
            'product_id' => $productId,
            'reason' => $cancellationReason,
        ]);

        try {
            $user = AiMusicUser::where('device_id', $deviceId)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'event_type' => 'CANCELLATION',
                ];
            }

            // Update subscription status (keep access until expiration)
            $subscription = Subscription::where('device_id', $deviceId)
                ->where('platform_product_id', $productId)
                ->where('status', 'active')
                ->first();

            if ($subscription) {
                $subscription->update([
                    'auto_renewal' => false,
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $cancellationReason,
                ]);
            }

            Log::info("✅ [WEBHOOK] Subscription marked as cancelled (access until {$user->subscription_expires_at})", [
                'device_id' => $deviceId,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription marked as cancelled (access until expiration)',
                'event_type' => 'CANCELLATION',
                'user_id' => $user->id,
                'action' => 'marked_cancelled',
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle EXPIRATION event
     * Subscription expired (not renewed)
     */
    protected function handleExpiration(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;
        $expirationReason = $event['expiration_reason'] ?? 'unknown';

        Log::info("🚫 [WEBHOOK] Expiration", [
            'device_id' => $deviceId,
            'product_id' => $productId,
            'reason' => $expirationReason,
        ]);

        DB::beginTransaction();
        try {
            $user = AiMusicUser::where('device_id', $deviceId)->first();

            if (!$user) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'event_type' => 'EXPIRATION',
                ];
            }

            // Revoke premium access
            $user->update([
                'subscription_credits' => 0,
                'subscription_expires_at' => null,
            ]);

            // Update subscription record
            $subscription = Subscription::where('device_id', $deviceId)
                ->where('platform_product_id', $productId)
                ->whereIn('status', ['active', 'cancelled'])
                ->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'expired',
                    'auto_renewal' => false,
                ]);
            }

            DB::commit();

            Log::info("✅ [WEBHOOK] Premium access revoked", [
                'device_id' => $deviceId,
                'reason' => $expirationReason,
            ]);

            return [
                'success' => true,
                'message' => 'Premium access revoked',
                'event_type' => 'EXPIRATION',
                'user_id' => $user->id,
                'action' => 'revoked_premium',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle BILLING_ISSUE event
     * Payment failed (grace period active)
     */
    protected function handleBillingIssue(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;
        $gracePeriodExpirationMs = $event['grace_period_expiration_at_ms'] ?? null;

        Log::warning("❌ [WEBHOOK] Billing Issue", [
            'device_id' => $deviceId,
            'product_id' => $productId,
            'grace_period_ends' => $gracePeriodExpirationMs 
                ? Carbon::createFromTimestampMs($gracePeriodExpirationMs)->toDateTimeString()
                : 'unknown',
        ]);

        try {
            $user = AiMusicUser::where('device_id', $deviceId)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'event_type' => 'BILLING_ISSUE',
                ];
            }

            // Update subscription status (keep access during grace period)
            $subscription = Subscription::where('device_id', $deviceId)
                ->where('platform_product_id', $productId)
                ->where('status', 'active')
                ->first();

            if ($subscription) {
                $subscription->update([
                    'status' => 'billing_issue',
                    'cancellation_reason' => 'billing_issue',
                    'cancellation_notes' => 'Payment failed - grace period active',
                ]);
            }

            // Keep premium access during grace period
            if ($gracePeriodExpirationMs) {
                $gracePeriodEnd = Carbon::createFromTimestampMs($gracePeriodExpirationMs);
                if ($gracePeriodEnd->isFuture()) {
                    Log::info("⏳ [WEBHOOK] Keeping access during grace period until {$gracePeriodEnd->toDateTimeString()}");
                    // Don't revoke access yet
                }
            }

            return [
                'success' => true,
                'message' => 'Billing issue recorded (grace period active)',
                'event_type' => 'BILLING_ISSUE',
                'user_id' => $user->id,
                'action' => 'billing_issue_recorded',
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle PRODUCT_CHANGE event
     * User upgrades/downgrades plan
     */
    protected function handleProductChange(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $newProductId = $event['new_product_id'] ?? null;
        $oldProductId = $event['old_product_id'] ?? null;
        $expirationAtMs = $event['expiration_at_ms'] ?? null;

        Log::info("🔀 [WEBHOOK] Product Change", [
            'device_id' => $deviceId,
            'old_product' => $oldProductId,
            'new_product' => $newProductId,
        ]);

        DB::beginTransaction();
        try {
            $user = AiMusicUser::where('device_id', $deviceId)->first();

            if (!$user) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'User not found',
                    'event_type' => 'PRODUCT_CHANGE',
                ];
            }

            // Parse new product ID
            $productParser = new ProductParserService();
            $productInfo = $productParser->parseProductId($newProductId);

            // Calculate expiration
            $expiresAt = $expirationAtMs 
                ? Carbon::createFromTimestampMs($expirationAtMs)
                : $this->calculateExpirationDate($productInfo['duration_name'] ?? 'monthly');

            // Update user credits
            $user->update([
                'subscription_credits' => $productInfo['credits'],
                'subscription_expires_at' => $expiresAt,
            ]);

            // Update subscription record
            $subscription = Subscription::where('device_id', $deviceId)
                ->where('platform_product_id', $oldProductId)
                ->where('status', 'active')
                ->first();

            if ($subscription) {
                $subscription->update([
                    'platform_product_id' => $newProductId,
                    'subscription_type' => $this->mapDurationToSubscriptionType($productInfo['duration_name'] ?? 'monthly'),
                    'expires_at' => $expiresAt,
                    'generations_included' => $productInfo['credits'],
                ]);
            }

            DB::commit();

            Log::info("✅ [WEBHOOK] Subscription tier updated", [
                'device_id' => $deviceId,
                'new_credits' => $productInfo['credits'],
                'expires_at' => $expiresAt->toDateTimeString(),
            ]);

            return [
                'success' => true,
                'message' => 'Subscription tier updated',
                'event_type' => 'PRODUCT_CHANGE',
                'user_id' => $user->id,
                'action' => 'product_changed',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle NON_RENEWING_PURCHASE event
     * One-time purchase (lifetime credits)
     */
    protected function handleNonRenewingPurchase(array $event): array
    {
        $deviceId = $event['app_user_id'];
        $productId = $event['product_id'] ?? null;

        Log::info("💎 [WEBHOOK] Non-Renewing Purchase", [
            'device_id' => $deviceId,
            'product_id' => $productId,
        ]);

        DB::beginTransaction();
        try {
            // Find or create user
            $user = AiMusicUser::firstOrCreate(
                ['device_id' => $deviceId],
                [
                    'subscription_credits' => 0,
                    'addon_credits' => 0,
                    'last_active_at' => now(),
                ]
            );

            // Parse product ID
            $productParser = new ProductParserService();
            $productInfo = $productParser->parseProductId($productId);

            // Grant lifetime credits
            $user->increment('addon_credits', $productInfo['credits']);

            // Create purchase record
            Purchase::create([
                'user_id' => $user->id,
                'product_type' => 'credit_pack',
                'product_name' => $this->extractPackName($productId),
                'credits_granted' => $productInfo['credits'],
                'price' => $event['price'] ?? 0,
                'currency' => $event['currency'] ?? 'USD',
                'metadata' => [
                    'device_id' => $deviceId,
                    'product_id' => $productId,
                    'platform' => $this->detectPlatform($event),
                    'transaction_id' => $event['transaction_id'] ?? null,
                    'event_type' => 'NON_RENEWING_PURCHASE',
                ],
            ]);

            DB::commit();

            Log::info("✅ [WEBHOOK] Lifetime credits granted", [
                'device_id' => $deviceId,
                'credits' => $productInfo['credits'],
                'total_addon_credits' => $user->addon_credits,
            ]);

            return [
                'success' => true,
                'message' => 'Lifetime credits granted',
                'event_type' => 'NON_RENEWING_PURCHASE',
                'user_id' => $user->id,
                'action' => 'granted_lifetime_credits',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Detect platform from event data
     */
    protected function detectPlatform(array $event): string
    {
        $store = $event['store'] ?? null;
        
        if ($store === 'APP_STORE') {
            return 'ios';
        } elseif ($store === 'PLAY_STORE') {
            return 'android';
        }
        
        return 'unknown';
    }

    /**
     * Calculate expiration date based on duration
     */
    protected function calculateExpirationDate(?string $duration): Carbon
    {
        switch ($duration) {
            case 'weekly':
                return now()->addWeek();
            case 'monthly':
                return now()->addMonth();
            case 'yearly':
                return now()->addYear();
            default:
                return now()->addMonth(); // Default to monthly
        }
    }

    /**
     * Map duration string to subscription_type enum value
     * 
     * @param string|null $duration
     * @return string
     */
    protected function mapDurationToSubscriptionType(?string $duration): string
    {
        switch ($duration) {
            case 'weekly':
                return 'premium_monthly'; // Map weekly to monthly (closest match)
            case 'monthly':
                return 'premium_monthly';
            case 'yearly':
                return 'premium_yearly';
            case 'lifetime':
                return 'premium_lifetime';
            default:
                return 'premium_monthly'; // Default to monthly
        }
    }

    /**
     * Map duration string to product name for purchases table
     * 
     * @param string|null $duration
     * @return string
     */
    protected function mapDurationToProductName(?string $duration): string
    {
        switch ($duration) {
            case 'weekly':
                return 'weekly';
            case 'monthly':
                return 'monthly';
            case 'yearly':
                return 'yearly';
            case 'lifetime':
                return 'lifetime';
            default:
                return 'monthly';
        }
    }

    /**
     * Extract pack name from product ID
     * 
     * @param string $productId
     * @return string
     */
    protected function extractPackName(string $productId): string
    {
        // Extract pack type from product ID (e.g., "credits_500_premium" -> "premium_pack")
        $parts = explode('_', strtolower($productId));
        
        // Look for pack type keywords
        $packTypes = ['basic', 'standard', 'premium', 'mega', 'ultimate'];
        foreach ($parts as $part) {
            if (in_array($part, $packTypes)) {
                return $part . '_pack';
            }
        }
        
        // Look for credit amount
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                return 'pack_' . $part;
            }
        }
        
        return 'standard_pack'; // Default
    }
}
