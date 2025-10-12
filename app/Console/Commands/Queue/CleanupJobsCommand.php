<?php

namespace App\Console\Commands\Queue;

use App\Models\GeneratedContent;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CleanupJobsCommand extends Command
{
    protected $signature = 'queue:cleanup 
                            {--failed-jobs=7 : Delete failed jobs older than X days}
                            {--completed-content=30 : Archive completed content older than X days}
                            {--old-generations=90 : Archive old generations older than X days}
                            {--cache : Clear queue-related cache entries}
                            {--logs : Clean old queue logs}
                            {--dry-run : Show what would be cleaned without executing}
                            {--force : Skip confirmation prompts}';

    protected $description = 'Clean up old jobs, content, and maintain queue system health';

    public function handle()
    {
        $this->info('🧹 AI Music Generator - Queue Cleanup & Maintenance');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $cleanupResults = [
            'failed_jobs' => 0,
            'completed_content' => 0,
            'old_generations' => 0,
            'cache_entries' => 0,
            'log_files' => 0
        ];

        // Clean failed jobs
        $cleanupResults['failed_jobs'] = $this->cleanFailedJobs($dryRun, $force);

        // Clean completed content
        $cleanupResults['completed_content'] = $this->cleanCompletedContent($dryRun, $force);

        // Clean old generations
        $cleanupResults['old_generations'] = $this->cleanOldGenerations($dryRun, $force);

        // Clean cache
        if ($this->option('cache')) {
            $cleanupResults['cache_entries'] = $this->cleanCache($dryRun, $force);
        }

        // Clean logs
        if ($this->option('logs')) {
            $cleanupResults['log_files'] = $this->cleanLogs($dryRun, $force);
        }

        // Summary
        $this->displayCleanupSummary($cleanupResults, $dryRun);

        return 0;
    }

    protected function cleanFailedJobs(bool $dryRun, bool $force): int
    {
        $days = $this->option('failed-jobs');
        $this->info("🗑️  Cleaning failed jobs older than {$days} days...");

        $cutoffDate = now()->subDays($days);
        
        $query = DB::table('failed_jobs')->where('failed_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->line('   No old failed jobs found');
            return 0;
        }

        if ($dryRun) {
            $this->line("   Would delete {$count} failed jobs");
            return $count;
        }

        if (!$force && !$this->confirm("Delete {$count} failed jobs older than {$days} days?")) {
            $this->line('   Skipped failed jobs cleanup');
            return 0;
        }

        $deleted = $query->delete();
        $this->info("   ✅ Deleted {$deleted} failed jobs");

        return $deleted;
    }

    protected function cleanCompletedContent(bool $dryRun, bool $force): int
    {
        $days = $this->option('completed-content');
        $this->info("🗑️  Archiving completed content older than {$days} days...");

        $cutoffDate = now()->subDays($days);
        
        $query = GeneratedContent::where('status', 'completed')
            ->where('completed_at', '<', $cutoffDate)
            ->where('last_accessed_at', '<', now()->subDays(7)); // Not accessed in 7 days

        $count = $query->count();

        if ($count === 0) {
            $this->line('   No old completed content found');
            return 0;
        }

        if ($dryRun) {
            $this->line("   Would archive {$count} completed content records");
            return $count;
        }

        if (!$force && !$this->confirm("Archive {$count} completed content records older than {$days} days?")) {
            $this->line('   Skipped content cleanup');
            return 0;
        }

        // Instead of deleting, mark as archived
        $updated = $query->update([
            'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.archived_at', '" . now()->toISOString() . "')"),
            'updated_at' => now()
        ]);

        $this->info("   ✅ Archived {$updated} completed content records");

        return $updated;
    }

    protected function cleanOldGenerations(bool $dryRun, bool $force): int
    {
        $days = $this->option('old-generations');
        $this->info("🗑️  Archiving old generations older than {$days} days...");

        $cutoffDate = now()->subDays($days);
        
        // Only archive generations where ALL tasks are completed/failed and old
        $query = Generation::where('created_at', '<', $cutoffDate)
            ->whereIn('status', ['completed', 'failed'])
            ->whereDoesntHave('tasks', function($q) {
                $q->whereIn('status', ['pending', 'processing']);
            });

        $count = $query->count();

        if ($count === 0) {
            $this->line('   No old generations found');
            return 0;
        }

        if ($dryRun) {
            $this->line("   Would archive {$count} old generations");
            return $count;
        }

        if (!$force && !$this->confirm("Archive {$count} old generations older than {$days} days?")) {
            $this->line('   Skipped generations cleanup');
            return 0;
        }

        // Mark as archived
        $updated = $query->update([
            'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.archived_at', '" . now()->toISOString() . "')"),
            'updated_at' => now()
        ]);

        $this->info("   ✅ Archived {$updated} old generations");

        return $updated;
    }

    protected function cleanCache(bool $dryRun, bool $force): int
    {
        $this->info('🗑️  Cleaning queue-related cache entries...');

        $cacheKeys = [
            'bulk_status_check_running',
            'bulk_status_check_last_run',
            'topmediai_api_calls_per_minute',
            'error_count_*'
        ];

        $cleared = 0;

        if ($dryRun) {
            $this->line("   Would clear " . count($cacheKeys) . " cache key patterns");
            return count($cacheKeys);
        }

        if (!$force && !$this->confirm('Clear queue-related cache entries?')) {
            $this->line('   Skipped cache cleanup');
            return 0;
        }

        foreach ($cacheKeys as $key) {
            if (str_contains($key, '*')) {
                // For wildcard keys, we'd need to implement pattern matching
                // For now, just clear the specific keys we know about
                continue;
            }

            if (Cache::forget($key)) {
                $cleared++;
            }
        }

        // Clear error count cache entries (last 24 hours)
        for ($i = 0; $i < 24; $i++) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $patterns = [
                "error_count_400015_generation_{$hour}",
                "error_count_429_generation_{$hour}",
                "error_count_500_generation_{$hour}",
                "error_count_502_generation_{$hour}",
                "error_count_503_generation_{$hour}",
            ];

            foreach ($patterns as $pattern) {
                if (Cache::forget($pattern)) {
                    $cleared++;
                }
            }
        }

        $this->info("   ✅ Cleared {$cleared} cache entries");

        return $cleared;
    }

    protected function cleanLogs(bool $dryRun, bool $force): int
    {
        $this->info('🗑️  Cleaning old queue logs...');

        $logPath = storage_path('logs');
        $cleaned = 0;

        if (!is_dir($logPath)) {
            $this->line('   No logs directory found');
            return 0;
        }

        // Find old log files
        $cutoffDate = now()->subDays(7);
        $logFiles = [];

        $files = scandir($logPath);
        foreach ($files as $file) {
            if (str_ends_with($file, '.log') && str_contains($file, 'queue')) {
                $filePath = $logPath . DIRECTORY_SEPARATOR . $file;
                $fileTime = filemtime($filePath);
                
                if ($fileTime && $fileTime < $cutoffDate->timestamp) {
                    $logFiles[] = $filePath;
                }
            }
        }

        if (empty($logFiles)) {
            $this->line('   No old log files found');
            return 0;
        }

        if ($dryRun) {
            $this->line("   Would delete " . count($logFiles) . " old log files");
            return count($logFiles);
        }

        if (!$force && !$this->confirm('Delete ' . count($logFiles) . ' old queue log files?')) {
            $this->line('   Skipped log cleanup');
            return 0;
        }

        foreach ($logFiles as $logFile) {
            if (unlink($logFile)) {
                $cleaned++;
            }
        }

        $this->info("   ✅ Deleted {$cleaned} old log files");

        return $cleaned;
    }

    protected function displayCleanupSummary(array $results, bool $dryRun): void
    {
        $this->newLine();
        $this->info('📊 Cleanup Summary:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $action = $dryRun ? 'Would clean' : 'Cleaned';

        $data = [
            ['Failed Jobs', $results['failed_jobs']],
            ['Completed Content', $results['completed_content']],
            ['Old Generations', $results['old_generations']],
            ['Cache Entries', $results['cache_entries']],
            ['Log Files', $results['log_files']],
        ];

        $this->table(['Category', $action], $data);

        $total = array_sum($results);

        if ($dryRun) {
            $this->warn("🔍 DRY RUN: {$total} items would be cleaned");
            $this->line('Run without --dry-run to execute cleanup');
        } else {
            $this->info("✅ Cleanup completed: {$total} items processed");
        }

        // Recommendations
        $this->newLine();
        $this->info('💡 Recommendations:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        $this->line('• Run this cleanup weekly: php artisan queue:cleanup');
        $this->line('• Monitor failed jobs: php artisan queue:failed');
        $this->line('• Check system health: php artisan queue:monitor --once');
        $this->line('• Set up automated cleanup in cron:');
        $this->line('  0 2 * * 0 cd /path/to/app && php artisan queue:cleanup --force');
    }

    /**
     * Get system statistics for cleanup recommendations
     */
    protected function getSystemStats(): array
    {
        try {
            return [
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'pending_tasks' => GeneratedContent::whereIn('status', ['pending', 'processing'])->count(),
                'completed_tasks' => GeneratedContent::where('status', 'completed')->count(),
                'failed_tasks' => GeneratedContent::where('status', 'failed')->count(),
                'total_generations' => Generation::count(),
                'active_generations' => Generation::whereIn('status', ['processing'])->count(),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
}