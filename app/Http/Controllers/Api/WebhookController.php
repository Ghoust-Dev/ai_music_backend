<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RevenueCat Webhook Controller
 * 
 * Handles incoming webhook events from RevenueCat for:
 * - Subscription purchases
 * - Renewals
 * - Cancellations
 * - Expirations
 * - Billing issues
 * - Product changes
 */
class WebhookController extends Controller
{
    protected $webhookService;

    public function __construct(RevenueCatWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle RevenueCat webhook events
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueCat(Request $request)
    {
        try {
            // Log incoming webhook
            Log::info('🔔 [REVENUECAT WEBHOOK] Received webhook', [
                'headers' => $request->headers->all(),
                'body_preview' => substr(json_encode($request->all()), 0, 500),
            ]);

            // Get event data
            $eventData = $request->input('event');
            
            if (!$eventData) {
                Log::warning('⚠️ [REVENUECAT WEBHOOK] No event data in request');
                // Still return 200 to prevent retries
                return response()->json([
                    'success' => false,
                    'message' => 'No event data provided'
                ], 200);
            }

            // Verify webhook signature (done in middleware)
            // Process event
            $result = $this->webhookService->processEvent($eventData);

            // Log result
            if ($result['success']) {
                Log::info('✅ [REVENUECAT WEBHOOK] Event processed successfully', [
                    'event_type' => $result['event_type'] ?? 'unknown',
                    'user_id' => $result['user_id'] ?? 'unknown',
                    'action_taken' => $result['action'] ?? 'none',
                ]);
            } else {
                Log::error('❌ [REVENUECAT WEBHOOK] Failed to process event', [
                    'error' => $result['message'] ?? 'Unknown error',
                    'event_type' => $result['event_type'] ?? 'unknown',
                ]);
            }

            // ALWAYS return 200 OK to acknowledge receipt
            // (prevents RevenueCat from retrying)
            return response()->json([
                'received' => true,
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Event received'
            ], 200);

        } catch (\Exception $e) {
            // Log error but still return 200 OK
            Log::error('💥 [REVENUECAT WEBHOOK] Exception caught', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 200 to prevent retries
            return response()->json([
                'received' => true,
                'success' => false,
                'message' => 'Internal error',
                'error' => config('app.debug') ? $e->getMessage() : 'Error processing webhook'
            ], 200);
        }
    }

    /**
     * Test endpoint to verify webhook setup
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function test()
    {
        return response()->json([
            'success' => true,
            'message' => 'RevenueCat webhook endpoint is configured correctly',
            'timestamp' => now()->toISOString(),
            'webhook_url' => url('/api/webhooks/revenuecat'),
        ]);
    }
}
