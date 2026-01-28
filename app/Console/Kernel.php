<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\LiveMatchSyncJob;
use App\Jobs\PrematchSyncJob;
use App\Jobs\OddsSyncJob;
use App\Jobs\MatchStatusManager;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync live matches for ALL sports
        // OPTIMIZED: ONE job syncs ALL 11 sports every 1 minute
        // This is more efficient than 11 separate jobs and ensures all live matches update every minute
        // API-Football is called once (for Soccer) and shared across all sports
        
        $schedule->job(new LiveMatchSyncJob(null)) // null = sync ALL sports (1-11)
            ->everyMinute()
            ->withoutOverlapping(60)
            ->name('live-match-sync-all-sports');

        // OPTIMIZED: OddsSyncJob - every 10 minutes (odds don't change that frequently)
        $schedule->job(new OddsSyncJob())
            ->cron('*/10 * * * *')
            ->withoutOverlapping(600)
            ->name('odds-sync');

        // PrematchSyncJob - already optimized at 5 minutes
        $schedule->job(new PrematchSyncJob())
            ->everyFiveMinutes()
            ->withoutOverlapping(300)
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

        /*
        |--------------------------------------------------------------------------
        | Multi-Layered Finished Match Detection
        |--------------------------------------------------------------------------
        | Layered approach ensures finished matches are removed before users see them
        |--------------------------------------------------------------------------
        */

        // OPTIMIZED: MatchStatusManager jobs - reduced frequency and added overlapping protection
        // Layer 1: API-Football Status Detection (Primary - 60-70% Coverage)
        // Runs every 10 minutes (reduced from 5) to catch finished matches from major leagues
        $schedule->job(new MatchStatusManager('api_football_filter'))
            ->everyTenMinutes()
            ->withoutOverlapping(600)
            ->name('finished-match-api-football-filter');

        // Layer 2: Pinnacle Market Verification (Secondary - 80-90% Coverage)
        // Runs every 15 minutes (reduced from 10) to check if Pinnacle still offers betting markets
        $schedule->job(new MatchStatusManager('pinnacle_verification'))
            ->everyFifteenMinutes()
            ->withoutOverlapping(900)
            ->name('finished-match-pinnacle-verification');

        // Layer 3: Time-Based Cleanup (Fallback - 95% Coverage)
        // Runs every 20 minutes (reduced from 15) to catch finished matches
        $schedule->job(new MatchStatusManager('time_based_cleanup', null, true)) // Aggressive mode
            ->cron('*/20 * * * *')
            ->withoutOverlapping(1200)
            ->name('finished-match-time-based-cleanup');

        // Layer 4: Staleness Detection (Safety Net - 100% Coverage)
        // Runs daily to remove completely stale matches
        $schedule->job(new MatchStatusManager('stale_data_purge'))
            ->daily()
            ->withoutOverlapping(86400)
            ->name('finished-match-stale-data-purge');

        // Comprehensive Check: All Layers Combined
        // Runs every 20 minutes (reduced from 15) in aggressive mode
        $schedule->job(new MatchStatusManager('comprehensive_check', null, true)) // Aggressive mode
            ->cron('*/20 * * * *')
            ->withoutOverlapping(1200)
            ->name('finished-match-comprehensive-check');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
