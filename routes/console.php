<?php

use App\Jobs\HandleExpiredSubscriptionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Schedule expired subscriptions cleanup job to run daily at 2:00 AM
Schedule::job(new HandleExpiredSubscriptionsJob)
    ->dailyAt('02:00')
    ->name('cleanup-expired-subscriptions')
    ->description('Reset subscription credits for expired subscriptions')
    ->withoutOverlapping();

// Schedule stale task cleanup to run every five minutes
Schedule::command('tasks:fail-stale')->everyFiveMinutes()
    ->name('cleanup-stale-tasks')
    ->description('Find and fail tasks that have been processing for too long (15 min timeout)')
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
