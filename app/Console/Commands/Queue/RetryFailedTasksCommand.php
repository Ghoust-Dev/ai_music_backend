<?php

namespace App\Console\Commands\Queue;

use App\Models\GeneratedContent;
use App\Models\Generation;
use App\Jobs\CheckTaskStatusJob;
use App\Services\ErrorHandlingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedTasksCommand extends Command
{
    protected $signature = 'queue:retry-failed-tasks 
                            {--age=24 : Retry tasks failed within X hours}
                            {--limit=50 : Maximum number of tasks to retry}
                            {--task= : Retry specific task ID}
                            {--generation= : Retry all failed tasks for generation}
                            {--dry-run : Show what would be retried without executing}
                            {--force : Retry even if max retries exceeded}';

    protected $description = 'Retry failed music generation tasks with intelligent filtering';

    protected ErrorHandlingService $errorService;

    public function __construct()
    {
        parent::__construct();
        $this->errorService = new ErrorHandlingService();
    }

    public function handle()
    {
        if ($this->option('task')) {
            return $this->retrySpecificTask($this->option('task'));
        }

        if ($this->option('generation')) {
            return $this->retryGeneration($this->option('generation'));
        }

        // Default: Retry failed tasks with filters
        return $this->retryFailedTasks();
    }

    protected function retryFailedTasks(): int
    {
        $age = $this->option('age');
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('🔄 AI Music Generator - Failed Task Retry Tool');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Find failed tasks
        $failedTasks = GeneratedContent::where('status', 'failed')
            ->where('updated_at', '>=', now()->subHours($age))
            ->whereNotNull('topmediai_task_id')
            ->where('topmediai_task_id', '!=', '')
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();

        if ($failedTasks->isEmpty()) {
            $this->info("✅ No failed tasks found within the last {$age} hours");
            return 0;
        }

        $this->info("Found {$failedTasks->count()} failed tasks within the last {$age} hours");

        // Analyze failure reasons
        $this->analyzeFailures($failedTasks);

        if ($dryRun) {
            $this->info('🔍 DRY RUN - Tasks that would be retried:');
            $this->displayTasksTable($failedTasks);
            return 0;
        }

        // Confirm retry
        if (!$this->confirm("Do you want to retry {$failedTasks->count()} failed tasks?")) {
            $this->info('Operation cancelled');
            return 0;
        }

        return $this->executeRetry($failedTasks);
    }

    protected function retrySpecificTask(string $taskId): int
    {
        $this->info("🔄 Retrying specific task: {$taskId}");

        $task = GeneratedContent::where('topmediai_task_id', $taskId)->first();

        if (!$task) {
            $this->error("❌ Task not found: {$taskId}");
            return 1;
        }

        $this->info("Task found - Status: {$task->status}, Updated: {$task->updated_at->diffForHumans()}");

        if ($task->status !== 'failed' && !$this->option('force')) {
            $this->warn("⚠️  Task is not in failed status: {$task->status}");
            $this->line('Use --force to retry anyway');
            return 0;
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN - This task would be retried');
            $this->displayTaskInfo($task);
            return 0;
        }

        return $this->executeRetry(collect([$task]));
    }

    protected function retryGeneration(string $generationId): int
    {
        $this->info("🔄 Retrying failed tasks for generation: {$generationId}");

        $generation = Generation::where('generation_id', $generationId)->first();

        if (!$generation) {
            $this->error("❌ Generation not found: {$generationId}");
            return 1;
        }

        $failedTasks = $generation->tasks()->where('status', 'failed')->get();

        if ($failedTasks->isEmpty()) {
            $this->info("✅ No failed tasks found for generation: {$generationId}");
            return 0;
        }

        $this->info("Found {$failedTasks->count()} failed tasks for generation");

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN - Tasks that would be retried:');
            $this->displayTasksTable($failedTasks);
            return 0;
        }

        if (!$this->confirm("Do you want to retry {$failedTasks->count()} failed tasks for this generation?")) {
            $this->info('Operation cancelled');
            return 0;
        }

        return $this->executeRetry($failedTasks);
    }

    protected function executeRetry($tasks): int
    {
        $this->info('🚀 Starting retry process...');

        $bar = $this->output->createProgressBar($tasks->count());
        $bar->start();

        $retried = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($tasks as $task) {
            try {
                // Check if task should be retried
                if ($this->shouldRetryTask($task)) {
                    // Reset task status to pending
                    $task->update([
                        'status' => 'pending',
                        'error_message' => null,
                        'last_accessed_at' => now(),
                        'metadata' => array_merge($task->metadata ?? [], [
                            'retry_info' => [
                                'retried_at' => now()->toISOString(),
                                'retried_by' => 'manual_command',
                                'previous_error' => $task->error_message,
                                'retry_reason' => 'manual_retry_command'
                            ]
                        ])
                    ]);

                    // Dispatch new status check job
                    CheckTaskStatusJob::dispatch($task->topmediai_task_id, 15, 0);
                    $retried++;

                } else {
                    $skipped++;
                }

            } catch (\Exception $e) {
                $this->error("Error retrying task {$task->topmediai_task_id}: " . $e->getMessage());
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Update generation statuses
        $this->updateGenerationStatuses($tasks);

        // Results
        $this->info("✅ Retry completed:");
        $this->line("  - Tasks retried: {$retried}");
        
        if ($skipped > 0) {
            $this->line("  - Tasks skipped: {$skipped}");
        }
        
        if ($errors > 0) {
            $this->warn("  - Errors: {$errors}");
        }

        return 0;
    }

    protected function shouldRetryTask(GeneratedContent $task): bool
    {
        // Always retry if force option is used
        if ($this->option('force')) {
            return true;
        }

        // Check if error is retryable
        $errorMessage = strtolower($task->error_message ?? '');
        
        // Don't retry validation errors
        $nonRetryableErrors = [
            'validation', 'invalid', 'bad request', 'unauthorized',
            'forbidden', 'content policy', 'inappropriate'
        ];

        foreach ($nonRetryableErrors as $nonRetryable) {
            if (strpos($errorMessage, $nonRetryable) !== false) {
                return false;
            }
        }

        // Check retry count in metadata
        $retryCount = 0;
        if (isset($task->metadata['retry_info'])) {
            $retryCount = is_array($task->metadata['retry_info']) ? 1 : count($task->metadata['retry_info']);
        }

        // Maximum 3 retries
        return $retryCount < 3;
    }

    protected function analyzeFailures($failedTasks): void
    {
        $this->info('📊 Failure Analysis:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Group by error types
        $errorTypes = [];
        $timeoutCount = 0;
        $serverErrorCount = 0;
        $validationErrorCount = 0;
        $unknownErrorCount = 0;

        foreach ($failedTasks as $task) {
            $error = strtolower($task->error_message ?? 'unknown');
            
            if (strpos($error, 'timeout') !== false || strpos($error, 'exceeded') !== false) {
                $timeoutCount++;
            } elseif (strpos($error, 'server') !== false || strpos($error, '500') !== false) {
                $serverErrorCount++;
            } elseif (strpos($error, 'validation') !== false || strpos($error, 'invalid') !== false) {
                $validationErrorCount++;
            } else {
                $unknownErrorCount++;
            }
        }

        $data = [
            ['🕐 Timeout/Exceeded', $timeoutCount, $timeoutCount > 0 ? '✅ Retryable' : '-'],
            ['🔧 Server Errors', $serverErrorCount, $serverErrorCount > 0 ? '✅ Retryable' : '-'],
            ['❌ Validation Errors', $validationErrorCount, $validationErrorCount > 0 ? '⚠️  Not Retryable' : '-'],
            ['❓ Unknown Errors', $unknownErrorCount, $unknownErrorCount > 0 ? '✅ Retryable' : '-'],
        ];

        $this->table(['Error Type', 'Count', 'Retry Status'], $data);
    }

    protected function displayTasksTable($tasks): void
    {
        $data = [];
        
        foreach ($tasks as $task) {
            $data[] = [
                'ID' => $task->id,
                'Task ID' => substr($task->topmediai_task_id, 0, 12) . '...',
                'Title' => substr($task->title, 0, 25) . (strlen($task->title) > 25 ? '...' : ''),
                'Failed' => $task->updated_at->diffForHumans(),
                'Error' => substr($task->error_message ?? 'Unknown', 0, 30) . '...',
                'Retryable' => $this->shouldRetryTask($task) ? '✅' : '❌'
            ];
        }

        $this->table(['ID', 'Task ID', 'Title', 'Failed', 'Error', 'Retryable'], $data);
    }

    protected function displayTaskInfo(GeneratedContent $task): void
    {
        $this->info("Task Details:");
        $this->line("  ID: {$task->id}");
        $this->line("  Task ID: {$task->topmediai_task_id}");
        $this->line("  Title: {$task->title}");
        $this->line("  Status: {$task->status}");
        $this->line("  Failed: {$task->updated_at->diffForHumans()}");
        $this->line("  Error: " . ($task->error_message ?? 'Unknown'));
        $this->line("  Retryable: " . ($this->shouldRetryTask($task) ? 'Yes' : 'No'));
    }

    protected function updateGenerationStatuses($tasks): void
    {
        $generationIds = $tasks->whereNotNull('generation_id')
                              ->pluck('generation_id')
                              ->unique();

        if ($generationIds->isEmpty()) {
            return;
        }

        $updated = 0;
        
        foreach ($generationIds as $generationId) {
            $generation = Generation::find($generationId);
            if ($generation) {
                $oldStatus = $generation->status;
                $generation->updateStatus();
                
                if ($oldStatus !== $generation->status) {
                    $updated++;
                }
            }
        }

        if ($updated > 0) {
            $this->info("🔄 Updated {$updated} generation statuses");
        }
    }
}