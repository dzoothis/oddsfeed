<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\LiveMatchSyncJob;
use App\Jobs\PrematchSyncJob;
use App\Jobs\OddsSyncJob;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler (Laravel 12 – CORRECT WAY)
|--------------------------------------------------------------------------
*/

return function (Schedule $schedule) {

    // Live matches - high priority, frequent updates
    $schedule->job(new LiveMatchSyncJob)
        ->everyMinute()
        ->withoutOverlapping()
        ->onQueue('live-sync')
        ->name('live-match-sync');

    // Odds sync - medium priority
    $schedule->job(new OddsSyncJob)
        ->everyMinute()
        ->withoutOverlapping()
        ->onQueue('odds-sync')
        ->name('odds-sync');

    // Prematch matches - lower priority
    $schedule->job(new PrematchSyncJob)
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onQueue('prematch-sync')
        ->name('prematch-sync');

    // Player data management
    $schedule->command('sports:manage-player-data')
        ->everyTenMinutes()
        ->withoutOverlapping()
        ->name('player-data-management');

    // Player data monitoring
    $schedule->command('sports:monitor-player-data --alert')
        ->everyThirtyMinutes()
        ->name('player-data-monitoring');

    // Failed job cleanup
    $schedule->command('queue:prune-failed --hours=24')
        ->daily();

    // System health monitoring
    $schedule->command('queue:health-check --restart --fix')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->name('system-health-check');
};
