<?php

namespace App\Console\Commands\Queue;

use App\Models\GeneratedContent;
use App\Models\Generation;
use App\Jobs\CheckTaskStatusJob;
use App\Jobs\CheckAllPendingTasksJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class StatusCheckCommand extends Command
{
    protected $signature = 'queue:status-check 
                            {--pending : Check all pending tasks manually}
                            {--task= : Check specific task ID}
                            {--generation= : Check all tasks for specific generation}
                            {--start-bulk : Start bulk checking job}
                            {--stop-bulk : Stop bulk checking job}
                            {--force : Force execution even if already running}';

    protected $description = 'Manual status checking and bulk job management';

    public function handle()
    {
        if ($this->option('pending')) {
            return $this->checkAllPending();
        }

        if ($this->option('task')) {
            return $this->checkSpecificTask($this->option('task'));
        }

        if ($this->option('generation')) {
            return $this->checkGeneration($this->option('generation'));
        }

        if ($this->option('start-bulk')) {
            return $this->startBulkChecking();
        }

        if ($this->option('stop-bulk')) {
            return $this->stopBulkChecking();
        }

        // Default: Show status and options
        $this->showStatus();
    }

    protected function showStatus(): void
    {
        $this->info('🔍 AI Music Generator - Status Check Tool');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Current bulk check status
        $bulkRunning = Cache::has('bulk_status_check_running');
        $lastBulkCheck = Cache::get('bulk_status_check_last_run');

        $this->info('📊 Current Status:');
        $this->line('Bulk Check Running: ' . ($bulkRunning ? '🟢 Yes' : '🔴 No'));
        $this->line('Last Bulk Check: ' . ($lastBulkCheck ? $lastBulkCheck->diffForHumans() : 'Never'));

        // Pending tasks count
        $pendingCount = GeneratedContent::whereIn('status', ['pending', 'processing'])->count();
        $this->line('Pending Tasks: ' . $pendingCount);

        $this->newLine();
        $this->info('🛠️ Available Commands:');
        $this->line('--pending          Check all pending tasks manually');
        $this->line('--task=ID          Check specific task ID');
        $this->line('--generation=ID    Check all tasks for generation');
        $this->line('--start-bulk       Start automatic bulk checking');
        $this->line('--stop-bulk        Stop automatic bulk checking');
        $this->line('--force            Force execution');
    }

    protected function checkAllPending(): int
    {
        $this->info('🔍 Checking all pending tasks...');

        $pendingTasks = GeneratedContent::whereIn('status', ['pending', 'processing'])
            ->where('created_at', '>=', now()->subHours(6))
            ->get();

        if ($pendingTasks->isEmpty()) {
            $this->info('✅ No pending tasks found');
            return 0;
        }

        $this->info("Found {$pendingTasks->count()} pending tasks");

        $bar = $this->output->createProgressBar($pendingTasks->count());
        $bar->start();

        $updated = 0;
        $errors = 0;

        foreach ($pendingTasks as $task) {
            try {
                // Dispatch individual status check
                CheckTaskStatusJob::dispatch($task->topmediai_task_id, 15, 0);
                $updated++;
            } catch (\Exception $e) {
                $this->error("Error checking task {$task->topmediai_task_id}: " . $e->getMessage());
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("✅ Status checks dispatched: {$updated}");
        if ($errors > 0) {
            $this->warn("⚠️  Errors: {$errors}");
        }

        return 0;
    }

    protected function checkSpecificTask(string $taskId): int
    {
        $this->info("🔍 Checking task: {$taskId}");

        $task = GeneratedContent::where('topmediai_task_id', $taskId)->first();

        if (!$task) {
            $this->error("❌ Task not found: {$taskId}");
            return 1;
        }

        $this->info("Task found - Status: {$task->status}");

        if (in_array($task->status, ['completed', 'failed'])) {
            $this->warn("⚠️  Task is already in final status: {$task->status}");
            
            if (!$this->option('force')) {
                $this->line('Use --force to check anyway');
                return 0;
            }
        }

        try {
            CheckTaskStatusJob::dispatch($taskId, 15, 0);
            $this->info("✅ Status check job dispatched for task: {$taskId}");
        } catch (\Exception $e) {
            $this->error("❌ Error dispatching status check: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function checkGeneration(string $generationId): int
    {
        $this->info("🔍 Checking generation: {$generationId}");

        $generation = Generation::where('generation_id', $generationId)->first();

        if (!$generation) {
            $this->error("❌ Generation not found: {$generationId}");
            return 1;
        }

        $tasks = $generation->tasks;

        if ($tasks->isEmpty()) {
            $this->warn("⚠️  No tasks found for generation: {$generationId}");
            return 0;
        }

        $this->info("Found {$tasks->count()} tasks for generation");

        $dispatched = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            if (in_array($task->status, ['completed', 'failed']) && !$this->option('force')) {
                $skipped++;
                continue;
            }

            try {
                CheckTaskStatusJob::dispatch($task->topmediai_task_id, 15, 0);
                $dispatched++;
            } catch (\Exception $e) {
                $this->error("Error checking task {$task->topmediai_task_id}: " . $e->getMessage());
            }
        }

        $this->info("✅ Status checks dispatched: {$dispatched}");
        if ($skipped > 0) {
            $this->line("Skipped final status tasks: {$skipped} (use --force to check anyway)");
        }

        return 0;
    }

    protected function startBulkChecking(): int
    {
        $this->info('🚀 Starting bulk status checking...');

        $isRunning = Cache::has('bulk_status_check_running');

        if ($isRunning && !$this->option('force')) {
            $this->warn('⚠️  Bulk checking is already running');
            $this->line('Use --force to start anyway');
            return 0;
        }

        if ($this->option('force')) {
            Cache::forget('bulk_status_check_running');
            $this->info('🔄 Forced clearing of running lock');
        }

        try {
            CheckAllPendingTasksJob::dispatch(20, 180);
            $this->info('✅ Bulk status checking job started');
            $this->line('The job will run every 10 minutes automatically');
        } catch (\Exception $e) {
            $this->error('❌ Error starting bulk checking: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function stopBulkChecking(): int
    {
        $this->info('🛑 Stopping bulk status checking...');

        $isRunning = Cache::has('bulk_status_check_running');

        if (!$isRunning) {
            $this->info('ℹ️  Bulk checking is not currently running');
            return 0;
        }

        // Clear the running lock
        Cache::forget('bulk_status_check_running');
        Cache::forget('bulk_status_check_last_run');

        $this->info('✅ Bulk status checking stopped');
        $this->warn('⚠️  Note: Already queued jobs will still execute');
        $this->line('To completely stop, restart the queue workers');

        return 0;
    }
}