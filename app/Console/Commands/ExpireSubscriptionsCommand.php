<?php

namespace App\Console\Commands;

use App\Jobs\HandleExpiredSubscriptionsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class ExpireSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire {--force : Force expiration without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually expire subscriptions and reset subscription credits';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Manual Subscription Expiration Tool');
        $this->line('This will expire all subscriptions that have passed their expiration date.');
        $this->line('⚠️  Subscription credits will be reset to 0, but add-on credits will remain untouched.');
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to proceed with expiring subscriptions?')) {
                $this->info('❌ Operation cancelled.');
                return 0;
            }
        }

        $this->info('⏳ Processing expired subscriptions...');
        
        // Dispatch the job synchronously for immediate execution
        $job = new HandleExpiredSubscriptionsJob();
        $job->handle();

        $this->info('✅ Subscription expiration completed successfully!');
        $this->line('📝 Check the application logs for detailed information about processed subscriptions.');
        
        return 0;
    }
}
