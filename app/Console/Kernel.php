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
        // Live matches - high priority, frequent updates
        $schedule->job(new LiveMatchSyncJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->onQueue('live-sync')
            ->name('live-match-sync');

        // Odds sync - medium priority, regular updates for active matches
        $schedule->job(new OddsSyncJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->onQueue('odds-sync')
            ->name('odds-sync');

        // Prematch matches - lower priority, longer cache TTL
        $schedule->job(new PrematchSyncJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onQueue('prematch-sync')
            ->name('prematch-sync');

        // Player data management - ensure all teams have fresh lineups
        $schedule->command('sports:manage-player-data')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->name('player-data-management');

        // Player data monitoring - alert about issues
        $schedule->command('sports:monitor-player-data --alert')
            ->everyThirtyMinutes()
            ->name('player-data-monitoring');

        // Optional: Run the failed job cleanup
        $schedule->command('queue:prune-failed', ['--hours' => 24])
            ->daily();

        // Comprehensive system health monitoring
        $schedule->command('queue:health-check --restart --fix')
            ->everyFiveMinutes()
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
