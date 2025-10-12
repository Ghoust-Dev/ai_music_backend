<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GeneratedContent;
use Illuminate\Support\Facades\Log;

class FailStaleTasksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:fail-stale {--timeout=15 : The timeout in minutes.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and fail tasks that have been in pending or processing state for too long.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timeout = (int) $this->option('timeout');
        $this->info("Searching for stale tasks older than {$timeout} minutes...");
        Log::info("Running FailStaleTasksCommand for tasks older than {$timeout} minutes.");

        $staleTasks = GeneratedContent::whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<', now()->subMinutes($timeout))
            ->get();

        if ($staleTasks->isEmpty()) {
            $this->info('No stale tasks found. All tasks are fresh!');
            Log::info('No stale tasks found.');
            return 0;
        }

        $count = $staleTasks->count();
        $this->warn("Found {$count} stale tasks to fail.");
        Log::warning("Found {$count} stale tasks to fail.");

        foreach ($staleTasks as $task) {
            $task->update([
                'status' => 'failed',
                'error_message' => "Generation timed out after {$timeout} minutes and was automatically marked as failed.",
                'completed_at' => now(), // Mark a completion time for consistency
            ]);
            $this->line("Failed task #{$task->id} (TopMediai Task ID: {$task->topmediai_task_id})");
            Log::info("Failed stale task", [
                'content_id' => $task->id,
                'topmediai_task_id' => $task->topmediai_task_id,
                'created_at' => $task->created_at->toIso8601String(),
                'age_minutes' => now()->diffInMinutes($task->created_at),
            ]);
        }

        $this->info("Successfully failed {$count} stale tasks.");
        return 0;
    }
}
