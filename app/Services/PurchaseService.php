<?php

namespace App\Services;

use App\Models\AiMusicUser;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseService
{
    /**
     * Purchase a subscription plan
     */
    public function purchaseSubscription(AiMusicUser $user, string $plan, array $paymentData = []): array
    {
        $planConfig = $this->getSubscriptionPlanConfig($plan);
        
        if (!$planConfig) {
            return [
                'success' => false,
                'message' => 'Invalid subscription plan',
                'error' => 'INVALID_PLAN'
            ];
        }

        try {
            DB::beginTransaction();

            // Calculate expiration date
            $expiresAt = Carbon::now()->addDays($planConfig['duration_days']);

            // Update user subscription
            $user->update([
                'subscription_credits' => $user->subscription_credits + $planConfig['credits'],
                'subscription_expires_at' => $expiresAt,
            ]);

            // Record the purchase
            $purchase = Purchase::create([
                'user_id' => $user->id,
                'product_type' => 'subscription',
                'product_name' => $plan,
                'credits_granted' => $planConfig['credits'],
                'price' => $planConfig['price'],
                'currency' => 'USD',
                'metadata' => array_merge([
                    'duration_days' => $planConfig['duration_days'],
                    'expires_at' => $expiresAt->toISOString(),
                    'payment_method' => $paymentData['payment_method'] ?? 'unknown',
                ], $paymentData)
            ]);

            DB::commit();

            Log::info('Subscription purchased successfully', [
                'user_id' => $user->id,
                'device_id' => $user->device_id,
                'plan' => $plan,
                'credits_granted' => $planConfig['credits'],
                'expires_at' => $expiresAt->toISOString(),
                'purchase_id' => $purchase->id
            ]);

            return [
                'success' => true,
                'message' => 'Subscription purchased successfully',
                'data' => [
                    'purchase_id' => $purchase->id,
                    'plan' => $plan,
                    'credits_granted' => $planConfig['credits'],
                    'total_subscription_credits' => $user->subscription_credits,
                    'total_addon_credits' => $user->addon_credits,
                    'total_credits' => $user->totalCredits(),
                    'expires_at' => $expiresAt,
                    'price' => $planConfig['price'],
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Subscription purchase failed', [
                'user_id' => $user->id,
                'device_id' => $user->device_id,
                'plan' => $plan,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Subscription purchase failed: ' . $e->getMessage(),
                'error' => 'PURCHASE_FAILED'
            ];
        }
    }

    /**
     * Purchase a credit pack (only for active subscribers)
     */
    public function purchaseCreditPack(AiMusicUser $user, string $pack, array $paymentData = []): array
    {
        // Check if user has active subscription
        if (!$user->hasActiveSubscription()) {
            return [
                'success' => false,
                'message' => 'Credit packs are only available to users with active subscriptions',
                'error' => 'NO_ACTIVE_SUBSCRIPTION'
            ];
        }

        $packConfig = $this->getCreditPackConfig($pack);
        
        if (!$packConfig) {
            return [
                'success' => false,
                'message' => 'Invalid credit pack',
                'error' => 'INVALID_PACK'
            ];
        }

        try {
            DB::beginTransaction();

            // Add addon credits
            $user->addAddonCredits($packConfig['credits']);

            // Record the purchase
            $purchase = Purchase::create([
                'user_id' => $user->id,
                'product_type' => 'credit_pack',
                'product_name' => $pack,
                'credits_granted' => $packConfig['credits'],
                'price' => $packConfig['price'],
                'currency' => 'USD',
                'metadata' => array_merge([
                    'pack_type' => $pack,
                    'lifetime_credits' => true,
                    'payment_method' => $paymentData['payment_method'] ?? 'unknown',
                ], $paymentData)
            ]);

            DB::commit();

            Log::info('Credit pack purchased successfully', [
                'user_id' => $user->id,
                'device_id' => $user->device_id,
                'pack' => $pack,
                'credits_granted' => $packConfig['credits'],
                'purchase_id' => $purchase->id
            ]);

            return [
                'success' => true,
                'message' => 'Credit pack purchased successfully',
                'data' => [
                    'purchase_id' => $purchase->id,
                    'pack' => $pack,
                    'credits_granted' => $packConfig['credits'],
                    'total_subscription_credits' => $user->subscription_credits,
                    'total_addon_credits' => $user->addon_credits,
                    'total_credits' => $user->totalCredits(),
                    'price' => $packConfig['price'],
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Credit pack purchase failed', [
                'user_id' => $user->id,
                'device_id' => $user->device_id,
                'pack' => $pack,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Credit pack purchase failed: ' . $e->getMessage(),
                'error' => 'PURCHASE_FAILED'
            ];
        }
    }

    /**
     * Get available subscription plans
     */
    public function getAvailableSubscriptionPlans(): array
    {
        return [
            'weekly' => $this->getSubscriptionPlanConfig('weekly'),
            'monthly' => $this->getSubscriptionPlanConfig('monthly'),
            'yearly' => $this->getSubscriptionPlanConfig('yearly'),
        ];
    }

    /**
     * Get available credit packs
     */
    public function getAvailableCreditPacks(): array
    {
        return [
            'basic_pack' => $this->getCreditPackConfig('basic_pack'),
            'standard_pack' => $this->getCreditPackConfig('standard_pack'),
            'premium_pack' => $this->getCreditPackConfig('premium_pack'),
        ];
    }

    /**
     * Get user's purchase history
     */
    public function getUserPurchaseHistory(AiMusicUser $user, int $limit = 20): array
    {
        $purchases = $user->purchases()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $purchases->map(function ($purchase) {
            return [
                'id' => $purchase->id,
                'product_type' => $purchase->product_type,
                'product_name' => $purchase->product_name,
                'credits_granted' => $purchase->credits_granted,
                'price' => $purchase->price,
                'currency' => $purchase->currency,
                'purchased_at' => $purchase->created_at,
                'metadata' => $purchase->metadata,
            ];
        })->toArray();
    }

    /**
     * Get subscription plan configuration
     */
    private function getSubscriptionPlanConfig(string $plan): ?array
    {
        $plans = [
            'weekly' => [
                'credits' => (int) env('WEEKLY_SUBSCRIPTION_CREDITS', 50),
                'duration_days' => 7,
                'price' => (float) env('WEEKLY_SUBSCRIPTION_PRICE', 4.99),
            ],
            'monthly' => [
                'credits' => (int) env('MONTHLY_SUBSCRIPTION_CREDITS', 250),
                'duration_days' => 30,
                'price' => (float) env('MONTHLY_SUBSCRIPTION_PRICE', 14.99),
            ],
            'yearly' => [
                'credits' => (int) env('YEARLY_SUBSCRIPTION_CREDITS', 3000),
                'duration_days' => 365,
                'price' => (float) env('YEARLY_SUBSCRIPTION_PRICE', 129.99),
            ],
        ];

        return $plans[$plan] ?? null;
    }

    /**
     * Get credit pack configuration
     */
    private function getCreditPackConfig(string $pack): ?array
    {
        $packs = [
            'basic_pack' => [
                'credits' => 100,
                'price' => (float) env('BASIC_PACK_PRICE', 9.99),
            ],
            'standard_pack' => [
                'credits' => 250,
                'price' => (float) env('STANDARD_PACK_PRICE', 19.99),
            ],
            'premium_pack' => [
                'credits' => 500,
                'price' => (float) env('PREMIUM_PACK_PRICE', 34.99),
            ],
        ];

        return $packs[$pack] ?? null;
    }
}