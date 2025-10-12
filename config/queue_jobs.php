<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Job Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file defines settings for our custom queue jobs
    | including retry attempts, timeouts, delays, and monitoring settings.
    |
    */

    'jobs' => [

        /*
        |--------------------------------------------------------------------------
        | CheckTaskStatusJob Configuration
        |--------------------------------------------------------------------------
        */
        'check_task_status' => [
            'max_retries' => 15,
            'timeout' => 120, // 2 minutes
            'initial_delay' => 120, // 2 minutes
            'progressive_delays' => [
                0 => 120,  // 2 minutes (initial)
                1 => 90,   // 1.5 minutes  
                3 => 120,  // 2 minutes
                6 => 180,  // 3 minutes
                10 => 300, // 5 minutes
                15 => 600  // 10 minutes (final attempts)
            ],
            'queue' => 'status_checks',
            'priority' => 'normal'
        ],

        /*
        |--------------------------------------------------------------------------
        | CheckAllPendingTasksJob Configuration
        |--------------------------------------------------------------------------
        */
        'check_all_pending_tasks' => [
            'batch_size' => 20,
            'max_age_minutes' => 180, // 3 hours
            'timeout' => 600, // 10 minutes
            'run_frequency' => 10, // minutes
            'min_interval' => 5, // minutes (collision prevention)
            'batch_delay' => 2, // seconds between batches
            'lock_timeout' => 15, // minutes
            'retry_delay' => 5, // minutes
            'emergency_retry_delay' => 15, // minutes
            'queue' => 'bulk_operations',
            'priority' => 'low'
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Priorities
    |--------------------------------------------------------------------------
    */
    'priorities' => [
        'high' => 10,
        'normal' => 5,
        'low' => 1
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'topmediai_api_calls_per_minute' => 20,
        'bulk_check_frequency_minutes' => 5,
        'individual_check_max_concurrent' => 10
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Cleanup
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'failed_job_retention_days' => 7,
        'completed_job_retention_days' => 3,
        'log_slow_jobs_seconds' => 30,
        'alert_high_failure_rate_percent' => 20,
        'cleanup_frequency_hours' => 6
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Settings
    |--------------------------------------------------------------------------
    */
    'production' => [
        'max_workers' => 5,
        'worker_timeout' => 300, // 5 minutes
        'worker_memory_limit' => '256M',
        'supervisor_enabled' => true,
        'restart_on_failure' => true,
        'health_check_interval' => 60 // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Settings
    |--------------------------------------------------------------------------
    */
    'development' => [
        'max_workers' => 2,
        'worker_timeout' => 120, // 2 minutes
        'worker_memory_limit' => '128M',
        'supervisor_enabled' => false,
        'restart_on_failure' => false,
        'health_check_interval' => 30 // seconds
    ]

];