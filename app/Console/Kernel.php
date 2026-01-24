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
        // Sync live matches for all sports
        // CRITICAL FIX: Explicitly set sportId for ALL sports to prevent defaulting to sportId=7 (NFL)
        // Previously, if sportId was not specified, it would default to NFL, causing Soccer matches to be missed
        
        $schedule->job(new LiveMatchSyncJob(1)) // Soccer
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-soccer');
        
        $schedule->job(new LiveMatchSyncJob(2)) // Tennis
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-tennis');
        
        $schedule->job(new LiveMatchSyncJob(3)) // Basketball
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-basketball');

        $schedule->job(new LiveMatchSyncJob(4)) // Hockey
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-hockey');

        $schedule->job(new LiveMatchSyncJob(5)) // Volleyball
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-volleyball');

        $schedule->job(new LiveMatchSyncJob(6)) // Handball
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-handball');

        $schedule->job(new LiveMatchSyncJob(7)) // American Football
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-american-football');

        $schedule->job(new LiveMatchSyncJob(8)) // Mixed Martial Arts
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-mma');

        $schedule->job(new LiveMatchSyncJob(9)) // Baseball
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-baseball');

        $schedule->job(new LiveMatchSyncJob(10)) // E Sports
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-esports');

        $schedule->job(new LiveMatchSyncJob(11)) // Cricket
            ->everyMinute()
            ->withoutOverlapping()
            ->name('live-match-sync-cricket');

        $schedule->job(new OddsSyncJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->name('odds-sync');

        $schedule->job(new PrematchSyncJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
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

        // Layer 1: API-Football Status Detection (Primary - 60-70% Coverage)
        // Runs every 5 minutes to catch finished matches from major leagues
        $schedule->job(new MatchStatusManager('api_football_filter'))
            ->everyFiveMinutes()
            ->name('finished-match-api-football-filter');

        // Layer 2: Pinnacle Market Verification (Secondary - 80-90% Coverage)
        // Runs every 10 minutes to check if Pinnacle still offers betting markets
        $schedule->job(new MatchStatusManager('pinnacle_verification'))
            ->everyTenMinutes()
            ->name('finished-match-pinnacle-verification');

        // Layer 3: Time-Based Cleanup (Fallback - 95% Coverage)
        // Runs every 15 minutes to catch finished matches faster
        $schedule->job(new MatchStatusManager('time_based_cleanup', null, true)) // Aggressive mode
            ->everyFifteenMinutes()
            ->name('finished-match-time-based-cleanup');

        // Layer 4: Staleness Detection (Safety Net - 100% Coverage)
        // Runs daily to remove completely stale matches
        $schedule->job(new MatchStatusManager('stale_data_purge'))
            ->daily()
            ->name('finished-match-stale-data-purge');

        // Comprehensive Check: All Layers Combined
        // Runs every 15 minutes in aggressive mode for faster cleanup
        $schedule->job(new MatchStatusManager('comprehensive_check', null, true)) // Aggressive mode
            ->everyFifteenMinutes()
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
