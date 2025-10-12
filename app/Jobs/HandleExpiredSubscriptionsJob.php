<?php

namespace App\Jobs;

use App\Models\AiMusicUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HandleExpiredSubscriptionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = Carbon::now();
        
        Log::info('Starting expired subscriptions cleanup job', [
            'timestamp' => $now->toISOString()
        ]);

        // Find all users with expired subscriptions
        $expiredUsers = AiMusicUser::where('subscription_expires_at', '<=', $now)
            ->whereNotNull('subscription_expires_at')
            ->get();

        $processedCount = 0;
        $totalCreditsReset = 0;

        foreach ($expiredUsers as $user) {
            $originalSubscriptionCredits = $user->subscription_credits;
            
            // Reset subscription credits to 0 (keep addon_credits untouched)
            $user->update([
                'subscription_credits' => 0,
                'subscription_expires_at' => null,
            ]);

            $totalCreditsReset += $originalSubscriptionCredits;
            $processedCount++;

            Log::info('Expired subscription processed', [
                'user_id' => $user->id,
                'device_id' => $user->device_id,
                'expired_at' => $user->subscription_expires_at,
                'subscription_credits_reset' => $originalSubscriptionCredits,
                'addon_credits_kept' => $user->addon_credits,
                'total_credits_remaining' => $user->addon_credits
            ]);
        }

        Log::info('Expired subscriptions cleanup completed', [
            'users_processed' => $processedCount,
            'total_subscription_credits_reset' => $totalCreditsReset,
            'duration' => $now->diffInSeconds(Carbon::now()) . ' seconds'
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('HandleExpiredSubscriptionsJob failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
