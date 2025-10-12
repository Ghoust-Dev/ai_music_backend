<?php

namespace App\Console\Commands;

use App\Models\AiMusicUser;
use App\Services\PurchaseService;
use Illuminate\Console\Command;

class GrantSubscriptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:grant-subscription {device_id} {plan=monthly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant a subscription plan to a user for testing purposes.';

    protected $purchaseService;

    public function __construct(PurchaseService $purchaseService)
    {
        parent::__construct();
        $this->purchaseService = $purchaseService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deviceId = $this->argument('device_id');
        $plan = $this->argument('plan');
        $validPlans = ['weekly', 'monthly', 'yearly'];

        if (!in_array($plan, $validPlans)) {
            $this->error("Invalid plan specified. Available plans are: weekly, monthly, yearly.");
            return 1;
        }

        $this->info("Attempting to grant '{$plan}' subscription to user with Device ID: {$deviceId}");

        $user = AiMusicUser::findByDeviceId($deviceId);

        if (!$user) {
            $this->error("User with Device ID '{$deviceId}' not found.");
            return 1;
        }

        $this->line("User found: ID #{$user->id}");

        // Simulate a purchase without actual payment data
        $result = $this->purchaseService->purchaseSubscription($user, $plan, [
            'payment_method' => 'manual_grant',
            'granted_by' => 'artisan_command',
        ]);

        if ($result['success']) {
            $this->info("✅ Subscription granted successfully!");
            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Plan Granted', $result['data']['plan']],
                    ['Credits Granted', $result['data']['credits_granted']],
                    ['New Subscription Credits', $result['data']['total_subscription_credits']],
                    ['Total Credits', $result['data']['total_credits']],
                    ['Subscription Expires At', $result['data']['expires_at']->toDateTimeString()],
                ]
            );
        } else {
            $this->error("Failed to grant subscription: {$result['message']}");
            return 1;
        }

        return 0;
    }
}
