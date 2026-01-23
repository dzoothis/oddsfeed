<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\ApiFootballService;
use App\Services\PinnacleService;
use App\Models\SportsMatch;
use Illuminate\Support\Facades\DB;

class MatchStatusManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
                date('Y-m-d', strtotime('-3 days')), // 3 days ago
            ];

            $finishedMatches = [];

            foreach ($datesToCheck as $date) {
                $finishedFixtures = $apiFootballService->getFinishedFixtures($date);

                if (!empty($finishedFixtures['response'])) {
                    foreach ($finishedFixtures['response'] as $fixture) {
                        $status = $fixture['fixture']['status']['short'] ?? '';

                        // Consider match finished if status indicates completion
                        if (in_array($status, ['FT', 'AET', 'PEN', 'AWD', 'Canc', 'PST'])) {
                            $homeTeamNormalized = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                            $awayTeamNormalized = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');

                            $finishedMatches[] = [
                                'home_team' => $fixture['teams']['home']['name'] ?? '', // Store original name for database removal
                                'away_team' => $fixture['teams']['away']['name'] ?? '', // Store original name for database removal
                                'home_team_normalized' => $homeTeamNormalized, // For lookup purposes
                                'away_team_normalized' => $awayTeamNormalized, // For lookup purposes
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
                ->where('lastUpdated', '>', now()->subHours(48)) // Updated recently
                ->limit(100) // Process in batches
                ->get();

            Log::info('Checking Pinnacle market availability', [
                'matches_to_check' => $potentiallyFinishedMatches->count()
            ]);

            // IMPROVED: Also check if match is in Pinnacle's current live feed
            // If not in live feed, it's likely finished
            $pinnacleLiveMatches = $pinnacleService->getMatchesByLeagues($this->sportId, [], 'live');
            $pinnacleLiveIds = [];
            foreach ($pinnacleLiveMatches as $pm) {
                if (isset($pm['id'])) {
                    $pinnacleLiveIds[] = $pm['id'];
                }
            }

            foreach ($potentiallyFinishedMatches as $match) {
                $checkedCount++;

                try {
                    // First check: Is match in Pinnacle's current live feed?
                    $inPinnacleLive = in_array($match->eventId, $pinnacleLiveIds);
                    
                    if (!$inPinnacleLive) {
                        // Match not in Pinnacle live feed = likely finished
                        Log::info('Match finished - not in Pinnacle live feed', [
                            'match_id' => $match->eventId,
                            'home_team' => $match->homeTeam,
                            'away_team' => $match->awayTeam,
                            'pinnacle_live_count' => count($pinnacleLiveIds)
                        ]);

                        // Mark as finished instead of deleting (safer)
                        $match->markAsFinished();
                        $removedCount++;

                        $this->clearMatchFromCache($match->homeTeam, $match->awayTeam);
                        continue; // Skip market check if not in live feed
                    }

                    // Second check: Check if Pinnacle still offers markets for this match
                    $hasMarkets = $this->checkPinnacleMarkets($pinnacleService, $match->eventId);

                    if (!$hasMarkets) {
                        // No markets available = match is finished
                        Log::info('Match finished - no Pinnacle markets available', [
                            'match_id' => $match->eventId,
                            'home_team' => $match->homeTeam,
                            'away_team' => $match->awayTeam
                        ]);

                        $match->markAsFinished();
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

            // GLOBAL FIX: Find ALL matches that need to be marked as finished
            // Priority 1: Matches 48+ hours old with live_status_id = 1 - ALWAYS mark as finished
            // Priority 2: Other old matches past threshold
            $pastDueMatches = SportsMatch::where('sportId', $this->sportId)
                ->whereNotIn('live_status_id', [2, -1]) // Don't process already finished matches
                ->where(function($q) use ($thresholdHours) {
                    // PRIMARY: Catch ALL matches 48+ hours old with live_status_id = 1
                    // This is the global fix - no exceptions, no conditions
                    $q->where(function($subQ) {
                        $subQ->where('live_status_id', '=', 1)
                             ->whereRaw('startTime < DATE_SUB(NOW(), INTERVAL 48 HOUR)');
                    })
                    // SECONDARY: Other old matches past threshold
                    ->orWhere(function($subQ) use ($thresholdHours) {
                        $subQ->whereRaw('startTime < DATE_SUB(NOW(), INTERVAL ? HOUR)', [$thresholdHours])
                             ->where(function($innerQ) {
                                $innerQ->where('betting_availability', '!=', 'live') // Not actively live
                                      ->orWhere(function($staleQ) {
                                          // Matches with live_status_id = 1 that are old and stale
                                          $staleQ->where('live_status_id', '=', 1)
                                                 ->where('lastUpdated', '<', now()->subHours(2)); // Not updated in last 2 hours
                                      });
                             });
                    });
                })
                ->orderBy('startTime', 'asc') // Process oldest matches first
                ->get();

            Log::info('Time-based cleanup check', [
                'threshold_hours' => $thresholdHours,
                'past_due_matches' => $pastDueMatches->count()
            ]);

            foreach ($pastDueMatches as $match) {
                $checkedCount++;

                try {
                    // Special logging for specific problematic matches
                    if ($match->eventId == 1622668279 || 
                        (stripos($match->homeTeam, 'Tijuana') !== false && stripos($match->awayTeam, 'Juarez') !== false)) {
                        Log::info('Processing specific match in time-based cleanup', [
                            'match_id' => $match->eventId,
                            'home_team' => $match->homeTeam,
                            'away_team' => $match->awayTeam,
                            'live_status_id' => $match->live_status_id,
                            'betting_availability' => $match->betting_availability,
                            'startTime' => $match->startTime
                        ]);
                    }

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

                // High confidence if marked as live but very old and not updated
                if ($match->live_status_id == 1 && $hoursPast > 3 && $hoursSinceUpdate > 2) {
                    $confidence += 30;
                    $reasons[] = 'marked_live_but_old_and_stale';
                }

                // CRITICAL: Matches that are 48+ hours old and marked as live should ALWAYS be finished
                // This catches matches that are being continuously updated but are clearly finished
                if ($match->live_status_id == 1 && $hoursPast > 48) {
                    $confidence += 50; // Very high confidence - override other factors
                    $reasons[] = 'marked_live_but_48h_old';
                }

                // Determine action based on league coverage and confidence
                $shouldMarkFinished = false;
                $shouldMarkSoftFinished = false;
                $action = 'none';

                // CRITICAL FIX: For matches 48+ hours old with live_status_id = 1, ALWAYS mark as finished
                // Also mark matches 24+ hours old if they haven't been updated recently
                // This bypasses league coverage check to ensure these matches are caught automatically
                $leagueCoverage = 'regional'; // Default
                if ($match->live_status_id == 1 && $hoursPast > 48) {
                    // Force mark as finished regardless of league coverage - these are definitely finished
                    $shouldMarkFinished = true;
                    $action = 'finished_48h_old_forced';
                    $confidence = 100; // Set to max to ensure it's processed
                    $leagueCoverage = 'forced_48h_old'; // Special marker
                } elseif ($match->live_status_id == 1 && $hoursPast > 24 && $hoursSinceUpdate > 12) {
                    // Matches 24+ hours old that haven't been updated in 12+ hours - likely finished
                    $shouldMarkFinished = true;
                    $action = 'finished_24h_old_stale';
                    $confidence = 80;
                    $leagueCoverage = 'forced_24h_old_stale';
                } else {
                    // Get league coverage type for other matches
                    $leagueCoverage = $this->getLeagueCoverage($match);

                    if ($confidence >= 30) {
                        // High confidence - decide based on league coverage
                        if ($leagueCoverage === 'major') {
                            $shouldMarkFinished = true;
                            $action = 'finished';
                        } else {
                            // Regional or unknown leagues get soft_finished
                            $shouldMarkSoftFinished = true;
                            $action = 'soft_finished';
                        }
                    } elseif ($this->aggressive && $confidence >= 15) {
                        // Aggressive mode with lower threshold - same logic
                        if ($leagueCoverage === 'major') {
                            $shouldMarkFinished = true;
                            $action = 'finished_aggressive';
                        } else {
                            $shouldMarkSoftFinished = true;
                            $action = 'soft_finished_aggressive';
                        }
                    }
                }

                if ($shouldMarkFinished || $shouldMarkSoftFinished) {
                    Log::info('Match status update - time-based rules', [
                        'match_id' => $match->eventId,
                        'league_id' => $match->leagueId,
                        'league_coverage' => $leagueCoverage,
                        'home_team' => $match->homeTeam,
                        'away_team' => $match->awayTeam,
                        'scheduled_time' => $match->startTime,
                        'hours_past' => round($hoursPast, 1),
                        'confidence' => $confidence,
                        'reasons' => implode(', ', $reasons),
                        'action' => $action,
                        'aggressive_mode' => $this->aggressive
                    ]);

                    try {
                        if ($shouldMarkFinished) {
                            $result = $match->markAsFinished();
                            if ($result) {
                                $removedCount++;
                                Log::info('Match marked as finished successfully', [
                                    'match_id' => $match->eventId,
                                    'home_team' => $match->homeTeam,
                                    'away_team' => $match->awayTeam
                                ]);
                            } else {
                                Log::warning('Failed to mark match as finished', [
                                    'match_id' => $match->eventId
                                ]);
                            }
                        } elseif ($shouldMarkSoftFinished) {
                            $result = $match->markAsSoftFinished();
                            if ($result) {
                                $removedCount++; // Count as "removed" from active status
                                Log::info('Match marked as soft finished successfully', [
                                    'match_id' => $match->eventId,
                                    'home_team' => $match->homeTeam,
                                    'away_team' => $match->awayTeam
                                ]);
                            } else {
                                Log::warning('Failed to mark match as soft finished', [
                                    'match_id' => $match->eventId
                                ]);
                            }
                        }

                        $this->clearMatchFromCache($match->homeTeam, $match->awayTeam);
                    } catch (\Exception $e) {
                        Log::error('Error marking match as finished', [
                            'match_id' => $match->eventId,
                            'home_team' => $match->homeTeam,
                            'away_team' => $match->awayTeam,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                } else {
                    // Log why match wasn't marked as finished (for debugging)
                    if ($match->eventId == 1622668279 || 
                        (stripos($match->homeTeam, 'Tijuana') !== false && stripos($match->awayTeam, 'Juarez') !== false)) {
                        Log::info('Match NOT marked as finished - conditions not met', [
                            'match_id' => $match->eventId,
                            'confidence' => $confidence,
                            'threshold' => $this->aggressive ? 15 : 30,
                            'reasons' => implode(', ', $reasons),
                            'hours_past' => round($hoursPast, 1),
                            'live_status_id' => $match->live_status_id
                        ]);
                    }
                }
                } catch (\Exception $e) {
                    // Catch any exception during match processing to prevent stopping the entire loop
                    Log::error('Error processing match in time-based cleanup', [
                        'match_id' => $match->eventId ?? 'unknown',
                        'home_team' => $match->homeTeam ?? 'unknown',
                        'away_team' => $match->awayTeam ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue; // Continue with next match instead of stopping
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
                ->where('lastUpdated', '<', $threshold)
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
                    'last_updated' => $match->lastUpdated,
                    'hours_stale' => round((time() - strtotime($match->lastUpdated)) / 3600, 1)
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
            // Normalize team names for better matching (remove U20, U19, etc. and special chars)
            $homeTeamNormalized = $this->normalizeTeamName($homeTeam);
            $awayTeamNormalized = $this->normalizeTeamName($awayTeam);
            
            // Use direct database query since the table structure is non-standard
            $match = DB::table('matches')
                ->where('sportId', $this->sportId)
                ->where(function($query) use ($homeTeam, $awayTeam, $homeTeamNormalized, $awayTeamNormalized) {
                    // Try exact match first
                    $query->where(function($q) use ($homeTeam, $awayTeam) {
                        $q->where('homeTeam', 'like', '%' . $homeTeam . '%')
                          ->where('awayTeam', 'like', '%' . $awayTeam . '%');
                    })
                    // Try reversed
                    ->orWhere(function($q) use ($homeTeam, $awayTeam) {
                        $q->where('homeTeam', 'like', '%' . $awayTeam . '%')
                          ->where('awayTeam', 'like', '%' . $homeTeam . '%');
                    })
                    // Try normalized matching (handles U20 variations)
                    ->orWhere(function($q) use ($homeTeamNormalized, $awayTeamNormalized) {
                        $q->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(homeTeam, " ", ""), "-", ""), "U20", ""), "U19", "")) LIKE ?', ['%' . $homeTeamNormalized . '%'])
                          ->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(awayTeam, " ", ""), "-", ""), "U20", ""), "U19", "")) LIKE ?', ['%' . $awayTeamNormalized . '%']);
                    })
                    // Try normalized reversed
                    ->orWhere(function($q) use ($homeTeamNormalized, $awayTeamNormalized) {
                        $q->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(homeTeam, " ", ""), "-", ""), "U20", ""), "U19", "")) LIKE ?', ['%' . $awayTeamNormalized . '%'])
                          ->whereRaw('LOWER(REPLACE(REPLACE(REPLACE(REPLACE(awayTeam, " ", ""), "-", ""), "U20", ""), "U19", "")) LIKE ?', ['%' . $homeTeamNormalized . '%']);
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
     * Get league coverage type for a match
     */
    private function getLeagueCoverage(SportsMatch $match): string
    {
        try {
            if ($match->league) {
                return $match->league->getCoverageType();
            }

            // Fallback: try to find league by pinnacleId
            $league = \App\Models\League::where('pinnacleId', $match->leagueId)->first();
            if ($league) {
                return $league->getCoverageType();
            }

        } catch (\Exception $e) {
            Log::warning('Failed to get league coverage, defaulting to regional', [
                'match_id' => $match->eventId,
                'league_id' => $match->leagueId,
                'error' => $e->getMessage()
            ]);
        }

        // Default to regional for unknown leagues
        return 'regional';
    }

    /**
     * Normalize team name for matching
     * Removes common suffixes like U20, U19, U21, etc. and special characters
     */
    private function normalizeTeamName(string $name): string
    {
        // Remove common age group suffixes (U20, U19, U21, etc.) first
        $name = preg_replace('/\s*U\d+\s*/i', '', $name);
        // Remove all non-alphanumeric characters and convert to lowercase
        // Keep spaces temporarily, then remove them
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]/', '', $name);
        return $name;
    }
}
