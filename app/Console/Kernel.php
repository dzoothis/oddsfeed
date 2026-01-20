<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\LiveMatchSyncJob;
use App\Jobs\PrematchSyncJob;
use App\Jobs\OddsSyncJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Phase 2: Background sync jobs for optimized match fetching

        // Live matches - high priority, frequent updates
        $schedule->job(new LiveMatchSyncJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->onQueue('live-sync')
            ->name('live-match-sync');

        // Odds sync - medium priority, regular updates for active matches
        $schedule->job(new OddsSyncJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->onQueue('odds-sync')
            ->name('odds-sync');

        // Prematch matches - lower priority, longer cache TTL
        $schedule->job(new PrematchSyncJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->onQueue('prematch-sync')
            ->name('prematch-sync');

        // Player data management - ensure all teams have fresh lineups
        $schedule->command('sports:manage-player-data')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->name('player-data-management');

        // Player data monitoring - alert about issues
        $schedule->command('sports:monitor-player-data --alert')
            ->everyThirtyMinutes()
            ->name('player-data-monitoring');

        // Optional: Run the failed job cleanup
        $schedule->command('queue:prune-failed', ['--hours' => 24])
            ->daily();

        // Comprehensive system health monitoring - check queues, data freshness, critical data
        $schedule->command('queue:health-check --restart --fix')
            ->everyFiveMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->name('system-health-check');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
