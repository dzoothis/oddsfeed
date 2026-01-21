<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\LiveMatchSyncJob;
use App\Jobs\PrematchSyncJob;
use App\Jobs\OddsSyncJob;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
| This file is where you may define all of your Closure based console
| commands as well as your scheduled tasks.
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler (Laravel 12)
|--------------------------------------------------------------------------
| Phase 2: Background sync jobs for optimized match fetching
|--------------------------------------------------------------------------
*/

// Live matches - high priority, frequent updates
app('schedule')->job(new LiveMatchSyncJob())
    ->everyMinute()
    ->withoutOverlapping()
    ->onQueue('live-sync')
    ->name('live-match-sync');

// Odds sync - medium priority, regular updates for active matches
app('schedule')->job(new OddsSyncJob())
    ->everyMinute()
    ->withoutOverlapping()
    ->onQueue('odds-sync')
    ->name('odds-sync');

// Prematch matches - lower priority, longer cache TTL
app('schedule')->job(new PrematchSyncJob())
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onQueue('prematch-sync')
    ->name('prematch-sync');

// Player data management - ensure all teams have fresh lineups
app('schedule')->command('sports:manage-player-data')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('player-data-management');

// Player data monitoring - alert about issues
app('schedule')->command('sports:monitor-player-data --alert')
    ->everyThirtyMinutes()
    ->name('player-data-monitoring');

// Optional: Run the failed job cleanup
app('schedule')->command('queue:prune-failed', ['--hours' => 24])
    ->daily();

// Comprehensive system health monitoring - check queues, data freshness, critical data
app('schedule')->command('queue:health-check --restart --fix')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('system-health-check');
