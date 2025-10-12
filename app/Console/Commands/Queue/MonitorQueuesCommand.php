<?php

namespace App\Console\Commands\Queue;

use App\Models\GeneratedContent;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MonitorQueuesCommand extends Command
{
    protected $signature = 'queue:monitor 
                            {--refresh=5 : Refresh interval in seconds}
                            {--once : Run once and exit}
                            {--json : Output as JSON}';

    protected $description = 'Monitor queue status, jobs, and system health';

    public function handle()
    {
        $refresh = $this->option('refresh');
        $once = $this->option('once');
        $json = $this->option('json');

        if ($json) {
            $this->outputJson();
            return;
        }

        if ($once) {
            $this->displayStatus();
            return;
        }

        $this->info('🚀 AI Music Generator - Queue Monitor');
        $this->info('Press Ctrl+C to stop monitoring');
        $this->newLine();

        while (true) {
            $this->displayStatus();
            
            if ($once) {
                break;
            }
            
            sleep($refresh);
            
            if (PHP_OS_FAMILY !== 'Windows') {
                system('clear');
            } else {
                system('cls');
            }
            
            $this->info('🚀 AI Music Generator - Queue Monitor (Updated: ' . now()->format('H:i:s') . ')');
            $this->newLine();
        }
    }

    protected function displayStatus(): void
    {
        try {
            $this->displayQueueStats();
            $this->newLine();
            $this->displayRedisStatus();
            $this->newLine();
            $this->displayJobStats();
            $this->newLine();
            $this->displaySystemHealth();
        } catch (\Exception $e) {
            $this->error('❌ Error monitoring queues: ' . $e->getMessage());
        }
    }

    protected function displayQueueStats(): void
    {
        $this->info('📊 Queue Statistics');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $queues = ['default', 'status_checks', 'bulk_operations'];
        $data = [];

        foreach ($queues as $queue) {
            try {
                $size = Queue::size($queue);
                $data[] = [
                    'Queue' => $queue,
                    'Jobs' => $size,
                    'Status' => $size > 0 ? '🟡 Active' : '🟢 Empty'
                ];
            } catch (\Exception $e) {
                $data[] = [
                    'Queue' => $queue,
                    'Jobs' => 'Error',
                    'Status' => '🔴 Error'
                ];
            }
        }

        $this->table(['Queue', 'Pending Jobs', 'Status'], $data);
    }

    protected function displayRedisStatus(): void
    {
        $this->info('🔴 Redis Status');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $redis = Redis::connection();
            $info = $redis->info();
            
            $data = [
                ['Connection', '🟢 Connected'],
                ['Redis Version', $info['redis_version'] ?? 'Unknown'],
                ['Used Memory', $this->formatBytes($info['used_memory'] ?? 0)],
                ['Connected Clients', $info['connected_clients'] ?? 'Unknown'],
                ['Total Commands', number_format($info['total_commands_processed'] ?? 0)],
            ];

            $this->table(['Metric', 'Value'], $data);

        } catch (\Exception $e) {
            $this->error('🔴 Redis connection failed: ' . $e->getMessage());
        }
    }

    protected function displayJobStats(): void
    {
        $this->info('🎵 Music Generation Statistics');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $taskStats = GeneratedContent::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $generationStats = Generation::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $data = [
                ['📝 Tasks - Pending', $taskStats['pending'] ?? 0],
                ['⚡ Tasks - Processing', $taskStats['processing'] ?? 0],
                ['✅ Tasks - Completed', $taskStats['completed'] ?? 0],
                ['❌ Tasks - Failed', $taskStats['failed'] ?? 0],
                ['🎼 Generations - Processing', $generationStats['processing'] ?? 0],
                ['🎵 Generations - Completed', $generationStats['completed'] ?? 0],
                ['⚠️  Generations - Failed', $generationStats['failed'] ?? 0],
            ];

            $this->table(['Metric', 'Count'], $data);

        } catch (\Exception $e) {
            $this->error('❌ Error fetching job statistics: ' . $e->getMessage());
        }
    }

    protected function displaySystemHealth(): void
    {
        $this->info('🏥 System Health');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $bulkCheckRunning = Cache::has('bulk_status_check_running');
            $recentErrors = GeneratedContent::where('status', 'failed')
                ->where('updated_at', '>=', now()->subHour())
                ->count();
            $totalRecent = GeneratedContent::where('updated_at', '>=', now()->subHour())->count();
            $successRate = $totalRecent > 0 ? round((($totalRecent - $recentErrors) / $totalRecent) * 100, 1) : 100;

            $data = [
                ['Failed Jobs', $failedJobs, $failedJobs > 10 ? '⚠️' : '✅'],
                ['Bulk Check Running', $bulkCheckRunning ? 'Yes' : 'No', $bulkCheckRunning ? '✅' : '⚠️'],
                ['Recent Errors (1h)', $recentErrors, $recentErrors > 5 ? '⚠️' : '✅'],
                ['Success Rate (1h)', $successRate . '%', $successRate >= 90 ? '✅' : ($successRate >= 70 ? '⚠️' : '❌')],
            ];

            $this->table(['Health Check', 'Status', 'Indicator'], $data);

        } catch (\Exception $e) {
            $this->error('❌ Error checking system health: ' . $e->getMessage());
        }
    }

    protected function outputJson(): void
    {
        try {
            $status = [
                'timestamp' => now()->toISOString(),
                'queues' => $this->getQueueStats(),
                'redis' => $this->getRedisStats(),
                'jobs' => $this->getJobStats(),
                'health' => $this->getHealthStats(),
            ];

            $this->line(json_encode($status, JSON_PRETTY_PRINT));

        } catch (\Exception $e) {
            $this->error('Error generating JSON output: ' . $e->getMessage());
        }
    }

    protected function getQueueStats(): array
    {
        $queues = ['default', 'status_checks', 'bulk_operations'];
        $stats = [];

        foreach ($queues as $queue) {
            try {
                $stats[$queue] = Queue::size($queue);
            } catch (\Exception $e) {
                $stats[$queue] = 'error';
            }
        }

        return $stats;
    }

    protected function getRedisStats(): array
    {
        try {
            $redis = Redis::connection();
            $info = $redis->info();
            
            return [
                'connected' => true,
                'version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory'] ?? 0,
                'connected_clients' => $info['connected_clients'] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    protected function getJobStats(): array
    {
        try {
            $taskStats = GeneratedContent::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $generationStats = Generation::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            return [
                'tasks' => $taskStats,
                'generations' => $generationStats,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function getHealthStats(): array
    {
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $bulkCheckRunning = Cache::has('bulk_status_check_running');
            $recentErrors = GeneratedContent::where('status', 'failed')
                ->where('updated_at', '>=', now()->subHour())
                ->count();
            $totalRecent = GeneratedContent::where('updated_at', '>=', now()->subHour())->count();
            $successRate = $totalRecent > 0 ? round((($totalRecent - $recentErrors) / $totalRecent) * 100, 1) : 100;

            return [
                'failed_jobs' => $failedJobs,
                'bulk_check_running' => $bulkCheckRunning,
                'recent_errors' => $recentErrors,
                'success_rate' => $successRate,
                'healthy' => $failedJobs < 10 && $successRate >= 90,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'healthy' => false];
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.1f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}