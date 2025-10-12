<?php

namespace App\Jobs;

use App\Models\GeneratedContent;
use App\Models\Generation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class MaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $config;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'clean_failed_jobs' => true,
            'clean_completed_content' => true,
            'clean_old_generations' => false, // More conservative
            'clean_cache' => true,
            'failed_jobs_days' => 7,
            'completed_content_days' => 30,
            'old_generations_days' => 90,
        ], $config);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting automated maintenance job', [
            'config' => $this->config,
            'timestamp' => now()->toISOString()
        ]);

        $results = [
            'failed_jobs_cleaned' => 0,
            'content_archived' => 0,
            'generations_archived' => 0,
            'cache_cleared' => 0,
            'errors' => []
        ];

        try {
            // Clean failed jobs
            if ($this->config['clean_failed_jobs']) {
                $results['failed_jobs_cleaned'] = $this->cleanFailedJobs();
            }

            // Archive completed content
            if ($this->config['clean_completed_content']) {
                $results['content_archived'] = $this->archiveCompletedContent();
            }

            // Archive old generations
            if ($this->config['clean_old_generations']) {
                $results['generations_archived'] = $this->archiveOldGenerations();
            }

            // Clean cache
            if ($this->config['clean_cache']) {
                $results['cache_cleared'] = $this->cleanExpiredCache();
            }

            // Update system health metrics
            $this->updateHealthMetrics();

            Log::info('Automated maintenance completed successfully', [
                'results' => $results,
                'duration_seconds' => $this->getExecutionTime()
            ]);

            // Schedule next maintenance (daily)
            $this->scheduleNext();

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            
            Log::error('Automated maintenance failed', [
                'error' => $e->getMessage(),
                'results' => $results,
                'trace' => $e->getTraceAsString()
            ]);

            // Schedule retry in 4 hours
            self::dispatch($this->config)->delay(now()->addHours(4));
        }
    }

    /**
     * Clean old failed jobs
     */
    protected function cleanFailedJobs(): int
    {
        $cutoffDate = now()->subDays($this->config['failed_jobs_days']);
        
        $deleted = DB::table('failed_jobs')
            ->where('failed_at', '<', $cutoffDate)
            ->delete();

        if ($deleted > 0) {
            Log::info('Cleaned failed jobs', [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoffDate->toISOString()
            ]);
        }

        return $deleted;
    }

    /**
     * Archive old completed content
     */
    protected function archiveCompletedContent(): int
    {
        $cutoffDate = now()->subDays($this->config['completed_content_days']);
        $accessCutoff = now()->subDays(7); // Not accessed in 7 days
        
        $updated = GeneratedContent::where('status', 'completed')
            ->where('completed_at', '<', $cutoffDate)
            ->where(function($query) use ($accessCutoff) {
                $query->whereNull('last_accessed_at')
                      ->orWhere('last_accessed_at', '<', $accessCutoff);
            })
            ->whereRaw("JSON_EXTRACT(metadata, '$.archived_at') IS NULL")
            ->update([
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.archived_at', '" . now()->toISOString() . "')"),
                'updated_at' => now()
            ]);

        if ($updated > 0) {
            Log::info('Archived completed content', [
                'archived_count' => $updated,
                'cutoff_date' => $cutoffDate->toISOString()
            ]);
        }

        return $updated;
    }

    /**
     * Archive old generations
     */
    protected function archiveOldGenerations(): int
    {
        $cutoffDate = now()->subDays($this->config['old_generations_days']);
        
        $updated = Generation::where('created_at', '<', $cutoffDate)
            ->whereIn('status', ['completed', 'failed'])
            ->whereDoesntHave('tasks', function($q) {
                $q->whereIn('status', ['pending', 'processing']);
            })
            ->whereRaw("JSON_EXTRACT(metadata, '$.archived_at') IS NULL")
            ->update([
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, '{}'), '$.archived_at', '" . now()->toISOString() . "')"),
                'updated_at' => now()
            ]);

        if ($updated > 0) {
            Log::info('Archived old generations', [
                'archived_count' => $updated,
                'cutoff_date' => $cutoffDate->toISOString()
            ]);
        }

        return $updated;
    }

    /**
     * Clean expired cache entries
     */
    protected function cleanExpiredCache(): int
    {
        $cleared = 0;

        // Clean old error count entries (older than 2 hours)
        for ($i = 2; $i < 48; $i++) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $patterns = [
                "error_count_400015_generation_{$hour}",
                "error_count_429_generation_{$hour}",
                "error_count_500_generation_{$hour}",
                "error_count_502_generation_{$hour}",
                "error_count_503_generation_{$hour}",
                "error_count_0_status_check_{$hour}",
                "error_count_0_bulk_status_check_{$hour}",
            ];

            foreach ($patterns as $pattern) {
                if (Cache::forget($pattern)) {
                    $cleared++;
                }
            }
        }

        // Clean stale bulk check locks (older than 30 minutes)
        $bulkLockTime = Cache::get('bulk_status_check_running');
        if ($bulkLockTime && now()->diffInMinutes($bulkLockTime) > 30) {
            Cache::forget('bulk_status_check_running');
            $cleared++;
            
            Log::warning('Cleared stale bulk status check lock', [
                'lock_age_minutes' => now()->diffInMinutes($bulkLockTime)
            ]);
        }

        if ($cleared > 0) {
            Log::info('Cleaned expired cache entries', ['cleared_count' => $cleared]);
        }

        return $cleared;
    }

    /**
     * Update system health metrics
     */
    protected function updateHealthMetrics(): void
    {
        try {
            $metrics = [
                'timestamp' => now()->toISOString(),
                'failed_jobs_count' => DB::table('failed_jobs')->count(),
                'pending_tasks' => GeneratedContent::whereIn('status', ['pending', 'processing'])->count(),
                'completed_tasks' => GeneratedContent::where('status', 'completed')->count(),
                'failed_tasks' => GeneratedContent::where('status', 'failed')->count(),
                'active_generations' => Generation::whereIn('status', ['processing'])->count(),
                'bulk_check_running' => Cache::has('bulk_status_check_running'),
                'last_bulk_check' => Cache::get('bulk_status_check_last_run')?->toISOString(),
            ];

            // Store metrics in cache for monitoring
            Cache::put('system_health_metrics', $metrics, now()->addHours(24));

            // Check for alerts
            $this->checkHealthAlerts($metrics);

        } catch (\Exception $e) {
            Log::warning('Failed to update health metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check for health alerts
     */
    protected function checkHealthAlerts(array $metrics): void
    {
        $alerts = [];

        // Too many failed jobs
        if ($metrics['failed_jobs_count'] > 50) {
            $alerts[] = "High failed jobs count: {$metrics['failed_jobs_count']}";
        }

        // Too many failed tasks
        if ($metrics['failed_tasks'] > 100) {
            $alerts[] = "High failed tasks count: {$metrics['failed_tasks']}";
        }

        // Bulk check not running for too long
        $lastBulkCheck = $metrics['last_bulk_check'] ? 
            now()->diffInMinutes($metrics['last_bulk_check']) : null;
        
        if (!$metrics['bulk_check_running'] && (!$lastBulkCheck || $lastBulkCheck > 30)) {
            $alerts[] = "Bulk status check not running for " . ($lastBulkCheck ?? 'unknown') . " minutes";
        }

        // Log alerts
        if (!empty($alerts)) {
            Log::alert('System health alerts detected', [
                'alerts' => $alerts,
                'metrics' => $metrics
            ]);
        }
    }

    /**
     * Schedule next maintenance job
     */
    protected function scheduleNext(): void
    {
        // Schedule for tomorrow at 2 AM
        $nextRun = now()->addDay()->setTime(2, 0, 0);
        
        self::dispatch($this->config)->delay($nextRun);
        
        Log::info('Next maintenance scheduled', [
            'scheduled_for' => $nextRun->toISOString()
        ]);
    }

    /**
     * Get execution time (placeholder - would need start time tracking)
     */
    protected function getExecutionTime(): int
    {
        // This is a simplified version - in real implementation,
        // you'd track start time in constructor
        return 0;
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Maintenance job failed permanently', [
            'error' => $exception->getMessage(),
            'config' => $this->config,
            'trace' => $exception->getTraceAsString()
        ]);

        // Schedule retry in 6 hours
        self::dispatch($this->config)->delay(now()->addHours(6));
    }
}