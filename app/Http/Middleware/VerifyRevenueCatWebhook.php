<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verify RevenueCat Webhook Signatures
 * 
 * CRITICAL FOR SECURITY: Always verify that webhooks are from RevenueCat
 * to prevent attackers from granting themselves premium access.
 */
class VerifyRevenueCatWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get webhook secret from environment
        $webhookSecret = config('services.revenuecat.webhook_secret');

        // Skip verification in local/development environment (optional)
        if (config('app.env') === 'local' && !$webhookSecret) {
            Log::warning('⚠️ [WEBHOOK SECURITY] Skipping signature verification in local environment');
            return $next($request);
        }

        // Webhook secret is required in production
        if (!$webhookSecret) {
            Log::error('🔒 [WEBHOOK SECURITY] REVENUECAT_WEBHOOK_SECRET not configured');
            return response()->json([
                'error' => 'Webhook configuration error'
            ], 500);
        }

        // Get signature from header
        $signature = $request->header('X-Revenuecat-Signature');

        if (!$signature) {
            Log::warning('🔒 [WEBHOOK SECURITY] No signature provided');
            return response()->json([
                'error' => 'No signature provided'
            ], 401);
        }

        // Calculate expected signature
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        // Compare signatures (timing-safe comparison)
        if (!hash_equals($expectedSignature, $signature)) {
            Log::error('🔒 [WEBHOOK SECURITY] Invalid signature', [
                'provided' => substr($signature, 0, 20) . '...',
                'expected' => substr($expectedSignature, 0, 20) . '...',
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Invalid signature'
            ], 401);
        }

        // Signature is valid
        Log::info('✅ [WEBHOOK SECURITY] Signature verified successfully');
        return $next($request);
    }
}
