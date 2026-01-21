<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\ApiFootballService;
use App\Models\SportsMatch;
use App\Jobs\MatchStatusManager;

class FinishedMatchManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:manage-finished
                            {action : Action to perform (check, remove, cleanup, status)}
                            {--home-team= : Home team name}
                            {--away-team= : Away team name}
                            {--sport-id=1 : Sport ID (default: 1 for soccer)}
                            {--aggressive : Use aggressive mode (shorter thresholds)}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comprehensive finished match detection and management';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $homeTeam = $this->option('home-team');
        $awayTeam = $this->option('away-team');
        $sportId = (int) $this->option('sport-id');
        $aggressive = $this->option('aggressive');
        $force = $this->option('force');

        $this->info("Finished Match Manager - Action: {$action}");

        switch ($action) {
            case 'check':
                $this->checkFinishedMatches($sportId);
                break;

            case 'remove':
                $this->removeSpecificMatch($homeTeam, $awayTeam, $sportId, $force);
                break;

            case 'cleanup':
                $this->runCleanup($sportId, $aggressive, $force);
                break;

            case 'status':
                $this->showSystemStatus($sportId);
                break;

            default:
                $this->error("Invalid action. Use: check, remove, cleanup, or status");
                return 1;
        }

        return 0;
    }

    /**
     * Check for potentially finished matches
     */
    private function checkFinishedMatches(int $sportId): void
    {
        $this->info("Checking for finished matches (Sport ID: {$sportId})");

        // Get matches that might be finished (prioritize by risk level)
        $sixHoursAgo = now()->subHours(6);
        $twentyFourHoursAgo = now()->subHours(24);

        $potentiallyFinished = SportsMatch::where('sportId', $sportId)
            ->where(function($query) use ($sixHoursAgo, $twentyFourHoursAgo) {
                // High risk: Not updated in 24+ hours
                $query->where('lastUpdated', '<', $twentyFourHoursAgo)
                      // Medium risk: Available for betting but not updated in 6+ hours
                      ->orWhere(function($q) use ($sixHoursAgo) {
                          $q->where('betting_availability', 'available_for_betting')
                            ->where('lastUpdated', '<', $sixHoursAgo);
                      })
                      // Medium risk: Marked as live but past scheduled time
                      ->orWhere(function($q) {
                          $q->where('live_status_id', 1)
                            ->whereRaw('startTime < DATE_SUB(NOW(), INTERVAL 4 HOUR)');
                      });
            })
            ->orderByRaw("
                CASE
                    WHEN lastUpdated < DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1
                    WHEN lastUpdated < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 2
                    WHEN (betting_availability = 'available_for_betting' AND lastUpdated < DATE_SUB(NOW(), INTERVAL 6 HOUR)) THEN 3
                    WHEN (live_status_id = 1 AND startTime < DATE_SUB(NOW(), INTERVAL 4 HOUR)) THEN 4
                    ELSE 5
                END ASC,
                lastUpdated ASC
            ")
            ->limit(20)
            ->get();

        if ($potentiallyFinished->isEmpty()) {
            $this->info('No potentially finished matches found.');
            return;
        }

        $this->table(
            ['ID', 'Home Team', 'Away Team', 'Status', 'Availability', 'Last Updated', 'Risk Level'],
            $potentiallyFinished->map(function($match) {
                $riskLevel = $this->assessRiskLevel($match);
                return [
                    $match->id,
                    $match->homeTeam,
                    $match->awayTeam,
                    $match->live_status_id,
                    $match->betting_availability,
                    $match->lastUpdated,
                    $riskLevel
                ];
            })
        );

        $this->info("Found {$potentiallyFinished->count()} potentially finished matches.");
    }

    /**
     * Remove a specific finished match
     */
    private function removeSpecificMatch(?string $homeTeam, ?string $awayTeam, int $sportId, bool $force): void
    {
        if (!$homeTeam || !$awayTeam) {
            $this->error('Both --home-team and --away-team are required for remove action');
            return;
        }

        $this->info("Removing match: {$homeTeam} vs {$awayTeam} (Sport ID: {$sportId})");

        if (!$force) {
            if (!$this->confirm('Are you sure you want to remove this match? This action cannot be undone.')) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        $removed = $this->removeMatchFromDatabase($homeTeam, $awayTeam, $sportId, 'manual_removal');

        if ($removed) {
            $this->info('✅ Match removed successfully');
            $this->clearMatchFromCache($homeTeam, $awayTeam, $sportId);
        } else {
            $this->error('❌ Match not found or could not be removed');
        }
    }

    /**
     * Run comprehensive cleanup
     */
    private function runCleanup(int $sportId, bool $aggressive, bool $force): void
    {
        $this->info("Running comprehensive cleanup (Sport ID: {$sportId}, Aggressive: " . ($aggressive ? 'Yes' : 'No') . ")");

        if (!$force) {
            if (!$this->confirm('This will run all finished match detection layers. Continue?')) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        // Dispatch comprehensive cleanup job
        dispatch(new MatchStatusManager('comprehensive_check', $sportId, $aggressive));

        $this->info('✅ Comprehensive cleanup job dispatched');
        $this->info('Check the logs for cleanup results');
    }

    /**
     * Show system status and statistics
     */
    private function showSystemStatus(int $sportId): void
    {
        $this->info("System Status - Finished Match Detection (Sport ID: {$sportId})");
        $this->newLine();

        // Database statistics
        $totalMatches = SportsMatch::where('sportId', $sportId)->count();
        $liveMatches = SportsMatch::where('sportId', $sportId)->where('live_status_id', 1)->count();
        $potentiallyFinished = SportsMatch::where('sportId', $sportId)->where('live_status_id', 0)->count();
        $staleMatches = SportsMatch::where('sportId', $sportId)
            ->where('lastUpdated', '<', now()->subHours(24))
            ->count();

        $this->info("📊 Database Statistics:");
        $this->line("  Total matches: {$totalMatches}");
        $this->line("  Live matches: {$liveMatches}");
        $this->line("  Potentially finished: {$potentiallyFinished}");
        $this->line("  Stale matches (24h+): {$staleMatches}");

        $this->newLine();

        // API-Football status
        try {
            $apiFootballService = app(ApiFootballService::class);
            $finishedFixtures = $apiFootballService->getFinishedFixtures();
            $apiFootballCount = count($finishedFixtures['response'] ?? []);
            $this->info("🔍 API-Football Status:");
            $this->line("  Finished fixtures available: {$apiFootballCount}");
            $this->line("  Service status: ✅ Working");
        } catch (\Exception $e) {
            $this->error("❌ API-Football Status: Service unavailable - " . $e->getMessage());
        }

        $this->newLine();

        // Scheduled jobs status
        $this->info("⏰ Scheduled Jobs:");
        $this->line("  API-Football Filter: Every 5 minutes");
        $this->line("  Pinnacle Verification: Every 10 minutes");
        $this->line("  Time-based Cleanup: Every hour");
        $this->line("  Stale Data Purge: Daily");
        $this->line("  Comprehensive Check: Every 30 minutes");

        $this->newLine();

        // Risk assessment
        $highRiskMatches = SportsMatch::where('sportId', $sportId)
            ->where('lastUpdated', '<', now()->subHours(6))
            ->where('betting_availability', '!=', 'live')
            ->count();

        if ($highRiskMatches > 0) {
            $this->warn("⚠️  Risk Alert: {$highRiskMatches} matches haven't been updated in 6+ hours");
            $this->warn("   These matches may be finished but not detected yet.");
        } else {
            $this->info("✅ Risk Assessment: All matches updated within acceptable timeframe");
        }
    }

    /**
     * Assess risk level of a match being finished
     */
    private function assessRiskLevel($match): string
    {
        $hoursSinceUpdate = (time() - strtotime($match->lastUpdated)) / 3600;
        $scheduledTime = strtotime($match->startTime);
        $hoursPastScheduled = (time() - $scheduledTime) / 3600;

        // Critical: Not updated in 48+ hours
        if ($hoursSinceUpdate > 48) return '🔴 Critical';

        // High: Not updated in 24+ hours
        if ($hoursSinceUpdate > 24) return '🟠 High';

        // Medium: Past scheduled time OR old available_for_betting matches
        if ($hoursPastScheduled > 4) return '🟡 Medium';
        if ($match->betting_availability === 'available_for_betting' && $hoursSinceUpdate > 6) return '🟡 Medium';
        if ($match->live_status_id == 1 && $hoursSinceUpdate > 12) return '🟡 Medium';

        return '🟢 Low';
    }

    /**
     * Remove match from database using team name matching
     */
    private function removeMatchFromDatabase(string $homeTeam, string $awayTeam, int $sportId, string $reason): bool
    {
        try {
            // Use direct database query since the table structure is non-standard
            $match = DB::table('matches')
                ->where('sportId', $sportId)
                ->where(function($query) use ($homeTeam, $awayTeam) {
                    $query->where(function($q) use ($homeTeam, $awayTeam) {
                        $q->where('homeTeam', 'like', '%' . $homeTeam . '%')
                          ->where('awayTeam', 'like', '%' . $awayTeam . '%');
                    })->orWhere(function($q) use ($homeTeam, $awayTeam) {
                        $q->where('homeTeam', 'like', '%' . $awayTeam . '%')
                          ->where('awayTeam', 'like', '%' . $homeTeam . '%');
                    });
                })
                ->first();

            if ($match) {
                Log::info('Manually removing finished match from database', [
                    'match_id' => $match->eventId,
                    'home_team' => $match->homeTeam,
                    'away_team' => $match->awayTeam,
                    'reason' => $reason
                ]);

                // Use direct database delete since eventId is the primary key
                DB::table('matches')->where('eventId', $match->eventId)->delete();
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Failed to manually remove match from database', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'sport_id' => $sportId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear match from cache
     */
    private function clearMatchFromCache(string $homeTeam, string $awayTeam, int $sportId): void
    {
        try {
            // Clear live matches cache for all leagues (since we don't know which league)
            for ($leagueId = 1; $leagueId <= 1000; $leagueId++) {
                $cacheKey = "live_matches:{$sportId}:{$leagueId}";
                Cache::forget($cacheKey);

                $staleCacheKey = "live_matches_stale:{$sportId}:{$leagueId}";
                Cache::forget($staleCacheKey);
            }

            Log::debug('Cleared cache for manually removed match', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'sport_id' => $sportId
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to clear match from cache', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'error' => $e->getMessage()
            ]);
        }
    }
}
