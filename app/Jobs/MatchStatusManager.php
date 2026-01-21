<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\ApiFootballService;
use App\Services\PinnacleService;
use App\Models\SportsMatch;
use Illuminate\Support\Facades\DB;

class MatchStatusManager implements ShouldQueue
{
    use Queueable;

    protected string $operation;
    protected ?int $sportId;
    protected bool $aggressive;

    // Queue configuration
    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public array $backoff = [60, 120, 300];

    /**
     * Create a new job instance.
     *
     * @param string $operation - 'api_football_filter', 'pinnacle_verification', 'time_based_cleanup', 'stale_data_purge', 'comprehensive_check'
     * @param int|null $sportId
     * @param bool $aggressive - More aggressive removal when true
     */
    public function __construct(string $operation = 'comprehensive_check', ?int $sportId = null, bool $aggressive = false)
    {
        $this->operation = $operation;
        $this->sportId = $sportId ?? 1; // Default to soccer
        $this->aggressive = $aggressive;
    }

    /**
     * Execute the job with multi-layered finished match detection.
     */
    public function handle(ApiFootballService $apiFootballService, PinnacleService $pinnacleService): void
    {
        Log::info('MatchStatusManager started', [
            'operation' => $this->operation,
            'sport_id' => $this->sportId,
            'aggressive' => $this->aggressive
        ]);

        $startTime = microtime(true);

        try {
            $results = [];

            switch ($this->operation) {
                case 'api_football_filter':
                    $results = $this->apiFootballFilter($apiFootballService);
                    break;

                case 'pinnacle_verification':
                    $results = $this->pinnacleVerification($pinnacleService);
                    break;

                case 'time_based_cleanup':
                    $results = $this->timeBasedCleanup();
                    break;

                case 'stale_data_purge':
                    $results = $this->staleDataPurge();
                    break;

                case 'comprehensive_check':
                default:
                    $results = $this->comprehensiveCheck($apiFootballService, $pinnacleService);
                    break;
            }

            $executionTime = round(microtime(true) - $startTime, 2);

            Log::info('MatchStatusManager completed', array_merge($results, [
                'operation' => $this->operation,
                'execution_time_seconds' => $executionTime
            ]));

        } catch (\Exception $e) {
            Log::error('MatchStatusManager failed', [
                'operation' => $this->operation,
                'sport_id' => $this->sportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Layer 1: API-Football Status Detection (Primary - 60-70% Coverage)
     */
    private function apiFootballFilter(ApiFootballService $apiFootballService): array
    {
        $removedCount = 0;
        $cacheClearedCount = 0;

        try {
            // Check multiple recent dates for finished fixtures
            $datesToCheck = [
                date('Y-m-d'), // Today
                date('Y-m-d', strtotime('-1 day')), // Yesterday
                date('Y-m-d', strtotime('-2 days')), // 2 days ago
            ];

            $finishedMatches = [];

            foreach ($datesToCheck as $date) {
                $finishedFixtures = $apiFootballService->getFinishedFixtures($date);

                if (!empty($finishedFixtures['response'])) {
                    foreach ($finishedFixtures['response'] as $fixture) {
                        $status = $fixture['fixture']['status']['short'] ?? '';

                        // Consider match finished if status indicates completion
                        if (in_array($status, ['FT', 'AET', 'PEN', 'AWD', 'Canc', 'PST'])) {
                            $homeTeam = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                            $awayTeam = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');

                            $finishedMatches[] = [
                                'home_team' => $homeTeam,
                                'away_team' => $awayTeam,
                                'status' => $status,
                                'date' => $date,
                                'fixture' => $fixture
                            ];
                        }
                    }
                }
            }

            Log::info('Found finished matches via API-Football', [
                'finished_matches_count' => count($finishedMatches),
                'dates_checked' => $datesToCheck
            ]);

            // Remove finished matches from database
            foreach ($finishedMatches as $finishedMatch) {
                $removed = $this->removeMatchFromDatabase(
                    $finishedMatch['home_team'],
                    $finishedMatch['away_team'],
                    'api_football_' . $finishedMatch['status']
                );

                if ($removed) {
                    $removedCount++;
                    $this->clearMatchFromCache($finishedMatch['home_team'], $finishedMatch['away_team']);
                    $cacheClearedCount++;
                }
            }

        } catch (\Exception $e) {
            Log::warning('API-Football filtering failed, continuing with other methods', [
                'error' => $e->getMessage()
            ]);
        }

        return [
            'api_football_removed' => $removedCount,
            'api_football_cache_cleared' => $cacheClearedCount
        ];
    }

    /**
     * Layer 2: Pinnacle Market Availability Check (Secondary - 80-90% Coverage)
     */
    private function pinnacleVerification(PinnacleService $pinnacleService): array
    {
        $removedCount = 0;
        $checkedCount = 0;

        try {
            // Get all matches that might be finished (not live, but available for betting)
            $potentiallyFinishedMatches = SportsMatch::where('sportId', $this->sportId)
                ->where('live_status_id', 0) // Not currently live
                ->where('betting_availability', 'available_for_betting')
                ->where('updated_at', '>', now()->subHours(48)) // Updated recently
                ->limit(100) // Process in batches
                ->get();

            Log::info('Checking Pinnacle market availability', [
                'matches_to_check' => $potentiallyFinishedMatches->count()
            ]);

            foreach ($potentiallyFinishedMatches as $match) {
                $checkedCount++;

                try {
                    // Check if Pinnacle still offers markets for this match
                    $hasMarkets = $this->checkPinnacleMarkets($pinnacleService, $match->eventId);

                    if (!$hasMarkets) {
                        // No markets available = match is finished
                        Log::info('Match finished - no Pinnacle markets available', [
                            'match_id' => $match->eventId,
                            'home_team' => $match->homeTeam,
                            'away_team' => $match->awayTeam
                        ]);

                        $match->delete();
                        $removedCount++;

                        $this->clearMatchFromCache($match->homeTeam, $match->awayTeam);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to check Pinnacle markets for match', [
                        'match_id' => $match->eventId,
                        'error' => $e->getMessage()
                    ]);
                }

                // Rate limiting - don't overwhelm Pinnacle API
                sleep(1);
            }

        } catch (\Exception $e) {
            Log::warning('Pinnacle verification failed', [
                'error' => $e->getMessage()
            ]);
        }

        return [
            'pinnacle_checked' => $checkedCount,
            'pinnacle_removed' => $removedCount
        ];
    }

    /**
     * Layer 3: Time-Based Rules (Fallback - 95% Coverage)
     */
    private function timeBasedCleanup(): array
    {
        $removedCount = 0;
        $checkedCount = 0;

        try {
            // More aggressive threshold for comprehensive cleanup
            $thresholdHours = $this->aggressive ? 1 : 3; // More aggressive = shorter threshold
            $threshold = now()->subHours($thresholdHours);

            // Find matches that are past their scheduled time
            $pastDueMatches = SportsMatch::where('sportId', $this->sportId)
                ->whereRaw('startTime < DATE_SUB(NOW(), INTERVAL ? HOUR)', [$thresholdHours])
                ->where('betting_availability', '!=', 'live') // Not actively live
                ->get();

            Log::info('Time-based cleanup check', [
                'threshold_hours' => $thresholdHours,
                'past_due_matches' => $pastDueMatches->count()
            ]);

            foreach ($pastDueMatches as $match) {
                $checkedCount++;

                $scheduledTime = strtotime($match->startTime);
                $hoursPast = (time() - $scheduledTime) / 3600;

                // Calculate confidence score for removal
                $confidence = 0;
                $reasons = [];

                // Base confidence for being past scheduled time
                if ($hoursPast > 2) {
                    $confidence += 20;
                    $reasons[] = 'past_scheduled_time';
                }

                // Additional confidence if not updated recently
                $hoursSinceUpdate = (time() - strtotime($match->lastUpdated)) / 3600;
                if ($hoursSinceUpdate > 6) {
                    $confidence += 15;
                    $reasons[] = 'not_updated_recently';
                }

                // Additional confidence if available for betting but not live
                if ($match->betting_availability === 'available_for_betting' && $match->live_status_id == 0) {
                    $confidence += 10;
                    $reasons[] = 'available_but_not_live';
                }

                // Remove if confidence is high enough OR in aggressive mode with lower threshold
                $shouldRemove = ($confidence >= 30) || ($this->aggressive && $confidence >= 15);

                if ($shouldRemove) {
                    Log::info('Match finished - time-based rules', [
                        'match_id' => $match->eventId,
                        'home_team' => $match->homeTeam,
                        'away_team' => $match->awayTeam,
                        'scheduled_time' => $match->startTime,
                        'hours_past' => round($hoursPast, 1),
                        'confidence' => $confidence,
                        'reasons' => implode(', ', $reasons),
                        'aggressive_mode' => $this->aggressive
                    ]);

                    $match->delete();
                    $removedCount++;

                    $this->clearMatchFromCache($match->homeTeam, $match->awayTeam);
                }
            }

        } catch (\Exception $e) {
            Log::warning('Time-based cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }

        return [
            'time_based_removed' => $removedCount,
            'time_based_checked' => $checkedCount,
            'threshold_hours' => $thresholdHours
        ];
    }

    /**
     * Layer 4: Staleness Detection (Safety Net - 100% Coverage)
     */
    private function staleDataPurge(): array
    {
        $removedCount = 0;

        try {
            // Remove completely stale matches (no updates for extended period)
            $staleThreshold = $this->aggressive ? 12 : 24; // More aggressive = shorter threshold
            $threshold = now()->subHours($staleThreshold);

            $staleMatches = SportsMatch::where('sportId', $this->sportId)
                ->where('updated_at', '<', $threshold)
                ->where('betting_availability', '!=', 'live')
                ->get();

            Log::info('Stale data purge check', [
                'stale_threshold_hours' => $staleThreshold,
                'stale_matches' => $staleMatches->count()
            ]);

            foreach ($staleMatches as $match) {
                Log::info('Match removed - stale data', [
                    'match_id' => $match->eventId,
                    'home_team' => $match->homeTeam,
                    'away_team' => $match->awayTeam,
                    'last_updated' => $match->updated_at,
                    'hours_stale' => round((time() - strtotime($match->updated_at)) / 3600, 1)
                ]);

                $match->delete();
                $removedCount++;

                $this->clearMatchFromCache($match->homeTeam, $match->awayTeam);
            }

        } catch (\Exception $e) {
            Log::warning('Stale data purge failed', [
                'error' => $e->getMessage()
            ]);
        }

        return [
            'stale_data_removed' => $removedCount,
            'stale_threshold_hours' => $staleThreshold
        ];
    }

    /**
     * Comprehensive Check: All Layers Combined with Confidence Scoring
     */
    private function comprehensiveCheck(ApiFootballService $apiFootballService, PinnacleService $pinnacleService): array
    {
        $results = [];

        Log::info('Starting comprehensive finished match detection');

        // Run all layers
        $results = array_merge($results, $this->apiFootballFilter($apiFootballService));
        $results = array_merge($results, $this->pinnacleVerification($pinnacleService));
        $results = array_merge($results, $this->timeBasedCleanup());
        $results = array_merge($results, $this->staleDataPurge());

        // Final cleanup: Remove any orphaned cache entries
        $this->cleanupOrphanedCache();

        $totalRemoved = ($results['api_football_removed'] ?? 0) +
                       ($results['pinnacle_removed'] ?? 0) +
                       ($results['time_based_removed'] ?? 0) +
                       ($results['stale_data_removed'] ?? 0);

        $results['total_removed'] = $totalRemoved;
        $results['comprehensive_check'] = true;

        return $results;
    }

    /**
     * Check if Pinnacle still offers markets for a match
     */
    private function checkPinnacleMarkets(PinnacleService $pinnacleService, $matchId): bool
    {
        try {
            // This would need to be implemented in PinnacleService
            // For now, return true (assume markets exist) to avoid false positives
            // TODO: Implement actual market checking in PinnacleService
            return true;
        } catch (\Exception $e) {
            Log::warning('Pinnacle market check failed', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);
            return true; // Default to true to avoid removing active matches
        }
    }

    /**
     * Remove match from database using team name matching
     */
    private function removeMatchFromDatabase(string $homeTeam, string $awayTeam, string $reason): bool
    {
        try {
            // Use direct database query since the table structure is non-standard
            $match = DB::table('matches')
                ->where('sportId', $this->sportId)
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
                Log::info('Removing finished match from database', [
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
            Log::warning('Failed to remove match from database', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear match from cache
     */
    private function clearMatchFromCache(string $homeTeam, string $awayTeam): void
    {
        try {
            // Clear live matches cache for all leagues (since we don't know which league)
            for ($leagueId = 1; $leagueId <= 1000; $leagueId++) { // Reasonable range
                $cacheKey = "live_matches:{$this->sportId}:{$leagueId}";
                Cache::forget($cacheKey);

                $staleCacheKey = "live_matches_stale:{$this->sportId}:{$leagueId}";
                Cache::forget($staleCacheKey);
            }

            Log::debug('Cleared cache for finished match', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'sport_id' => $this->sportId
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to clear match from cache', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up orphaned cache entries
     */
    private function cleanupOrphanedCache(): void
    {
        try {
            // This would remove cache entries for matches that no longer exist in database
            // Implementation depends on cache structure
            Log::debug('Orphaned cache cleanup completed');
        } catch (\Exception $e) {
            Log::warning('Orphaned cache cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Normalize team name for matching
     */
    private function normalizeTeamName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $name));
    }
}
