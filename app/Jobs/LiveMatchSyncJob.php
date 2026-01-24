<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use App\Services\ApiFootballService;
use App\Services\MatchAggregationService;
use App\Models\SportsMatch;

class LiveMatchSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Queue configuration for live match synchronization
    public $tries = 3; // Retry up to 3 times for network/API failures
    public $timeout = 600; // 10 minutes timeout for live data operations
    public $backoff = [30, 90, 300]; // Exponential backoff: 30s, 1.5min, 5min

    protected $sportId;
    protected $leagueIds;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sportId = null, $leagueIds = [])
    {
        // CRITICAL FIX: sportId must be explicitly provided
        // Previously, if not provided, it would default to sportId=7 (NFL) in some code paths
        // Now we require it to be explicitly set to prevent accidental NFL sync
        if (!$sportId) {
            throw new \InvalidArgumentException('sportId is required for LiveMatchSyncJob. Cannot default to avoid accidental NFL sync.');
        }
        
        $this->sportId = $sportId;
        $this->leagueIds = $leagueIds;
        $this->onQueue('live-sync');
    }

    /**
     * Execute the job - Phase 2: Background live match sync with caching
     */
    public function handle(
        PinnacleService $pinnacleService, 
        TeamResolutionService $teamResolutionService, 
        ApiFootballService $apiFootballService,
        MatchAggregationService $aggregationService
    ): void {
        Log::info('LiveMatchSyncJob started - Provider-Agnostic Aggregation', [
            'sportId' => $this->sportId,
            'leagueIds' => $this->leagueIds
        ]);

        try {
            // Step 1: Fetch live matches from ALL providers independently
            // No provider is treated as primary - all are equal (UNION approach)
            
            // 1.1: Fetch from Pinnacle
            $pinnacleMatchesData = $pinnacleService->getMatchesByLeagues(
                $this->sportId ?? 1, // Default to Soccer (fixed from NFL)
                [], // Always fetch ALL leagues (empty array)
                'live'
            );
            $pinnacleMatches = $pinnacleMatchesData['events'] ?? [];

            Log::info('Fetched live matches from Pinnacle', [
                'total_match_count' => count($pinnacleMatches),
                'sport_id' => $this->sportId
            ]);

            // 1.2: Fetch from API-Football
            $apiFootballMatches = [];
            try {
                $apiFootballFixtures = $apiFootballService->getFixtures(null, null, true); // live=true
                $apiFootballMatches = $apiFootballFixtures['response'] ?? [];
                
                Log::info('Fetched live matches from API-Football', [
                    'total_match_count' => count($apiFootballMatches)
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch API-Football matches', [
                    'error' => $e->getMessage()
                ]);
            }

            // 1.3: Fetch from Odds-Feed (if available)
            $oddsFeedMatches = [];
            try {
                $oddsFeedService = app(\App\Services\OddsFeedService::class);
                if ($oddsFeedService->isEnabled()) {
                    $oddsFeedMatches = $oddsFeedService->getLiveMatches($this->sportId ?? 1, []);
                    
                    Log::info('Fetched live matches from Odds-Feed', [
                        'total_match_count' => count($oddsFeedMatches)
                    ]);
                } else {
                    Log::debug('Odds-Feed service is disabled or not configured');
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Odds-Feed matches', [
                    'error' => $e->getMessage()
                ]);
            }

            // Step 2: Aggregate matches from all providers (provider-agnostic)
            // This deduplicates matches and merges data from all sources
            $aggregatedMatches = $aggregationService->aggregateMatches(
                $pinnacleMatches,
                $oddsFeedMatches,
                $apiFootballMatches
            );

            Log::info('Aggregated matches from all providers', [
                'aggregated_count' => count($aggregatedMatches),
                'pinnacle_count' => count($pinnacleMatches),
                'api_football_count' => count($apiFootballMatches),
                'odds_feed_count' => count($oddsFeedMatches)
            ]);

            if (empty($aggregatedMatches)) {
                Log::info('No aggregated live matches found, skipping processing');
                return;
            }

            // Step 3: Apply league filtering if requested (after aggregation)
            $liveMatches = $aggregatedMatches;
            if (!empty($this->leagueIds)) {
                $liveMatches = array_filter($aggregatedMatches, function($match) {
                    $leagueId = $match['league_id'] ?? null;
                    return $leagueId && in_array($leagueId, $this->leagueIds);
                });
                $liveMatches = array_values($liveMatches); // Re-index
                
                Log::info('After league filtering', [
                    'filtered_match_count' => count($liveMatches),
                    'requested_leagues' => $this->leagueIds
                ]);
            }

            // Step 4: Convert aggregated matches to format expected by team resolution
            $matchesForResolution = $this->convertAggregatedToResolutionFormat($liveMatches);

            // Step 5: Process and resolve teams for all aggregated matches
            $processedMatches = $this->processMatchesWithTeamResolution(
                $matchesForResolution,
                $teamResolutionService,
                $this->sportId ?? 1
            );

            // Step 3: Group by league and cache
            $matchesByLeague = $this->groupMatchesByLeague($processedMatches);

            foreach ($matchesByLeague as $leagueId => $leagueMatches) {
                $this->cacheLiveMatchesForLeague($leagueId, $leagueMatches, $this->sportId ?? 1);
            }

            // Step 4: Update database selectively (only changed matches)
            $this->updateDatabaseSelectively($processedMatches);

        // REMOVED: available_for_betting update logic
        // This was causing old matches to be updated every minute, preventing cleanup
        // Frontend only uses "live" and "prematch" filters, so this is not needed
        // Old matches will now be properly cleaned up by MatchStatusManager

        // Remove finished matches from database
        $finishedMatchesRemoved = $this->removeFinishedMatches($apiFootballService);

        // Clean up any remaining duplicate matches
        $duplicatesCleaned = $this->cleanupDuplicateMatches();

        // Update timestamps for live-visible matches that weren't processed by Pinnacle
        $timestampsUpdated = $this->updateLiveVisibleMatchTimestamps();

        Log::info('LiveMatchSyncJob completed successfully', [
            'processed_matches' => count($processedMatches),
            'leagues_updated' => count($matchesByLeague),
            'finished_matches_removed' => $finishedMatchesRemoved,
            'duplicates_cleaned' => $duplicatesCleaned,
            'live_visible_timestamps_updated' => $timestampsUpdated
        ]);

        } catch (\Exception $e) {
            Log::error('LiveMatchSyncJob failed', [
                'error' => $e->getMessage(),
                'sportId' => $this->sportId,
                'leagueIds' => $this->leagueIds,
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    private function processMatchesWithTeamResolution($matches, TeamResolutionService $teamResolutionService, $sportId)
    {
        $processedMatches = [];

        foreach ($matches as $match) {
            try {
                // Resolve teams using cached team resolution
                $homeTeamResolution = $teamResolutionService->resolveTeamId(
                    'pinnacle',
                    $match['home'] ?? 'Unknown',
                    null, // Pinnacle doesn't provide team IDs
                    $sportId,
                    $match['league_id'] ?? null
                );

                $awayTeamResolution = $teamResolutionService->resolveTeamId(
                    'pinnacle',
                    $match['away'] ?? 'Unknown',
                    null,
                    $sportId,
                    $match['league_id'] ?? null
                );

                // Get team enrichment data for images
                $homeEnrichment = $homeTeamResolution['team_id'] ?
                    \App\Models\TeamEnrichment::getCachedEnrichment($homeTeamResolution['team_id']) : null;
                $awayEnrichment = $awayTeamResolution['team_id'] ?
                    \App\Models\TeamEnrichment::getCachedEnrichment($awayTeamResolution['team_id']) : null;

                // Determine match type: Pinnacle live_status_id takes precedence
                // BUT: Check if match has actually started (startTime <= now())
                // Pinnacle "live" = live betting available, not necessarily match started
                $liveStatusId = $match['live_status_id'] ?? 0;
                
                // Check if match has actually started
                $matchStartTime = isset($match['starts']) ? strtotime($match['starts']) : null;
                $hasStarted = $matchStartTime && $matchStartTime <= time();
                
                // If Pinnacle says live but match hasn't started, mark as prematch
                if ($liveStatusId === 1 && !$hasStarted) {
                    $liveStatusId = 0; // Mark as prematch (not actually live yet)
                    Log::debug('Match marked as prematch - live betting available but match not started', [
                        'event_id' => $match['event_id'] ?? 'unknown',
                        'home_team' => $match['home'] ?? 'Unknown',
                        'away_team' => $match['away'] ?? 'Unknown',
                        'starts' => $match['starts'] ?? 'N/A',
                        'pinnacle_live_status_id' => $match['live_status_id'] ?? 0
                    ]);
                }
                
                $matchType = ($liveStatusId === 1) ? 'live' : 'prematch';

                // IMPORTANT: Save matches even if team resolution fails
                // This ensures all Pinnacle live matches are in our database
                $processedMatch = [
                    'id' => $match['event_id'],
                    'sport_id' => $sportId,
                    'home_team' => $match['home'] ?? 'Unknown',
                    'away_team' => $match['away'] ?? 'Unknown',
                    'home_team_id' => $homeTeamResolution['team_id'] ?? null, // Allow null - will be resolved later
                    'away_team_id' => $awayTeamResolution['team_id'] ?? null, // Allow null - will be resolved later
                    'league_id' => $match['league_id'] ?? null,
                    'league_name' => $match['league_name'] ?? 'Unknown',
                    'scheduled_time' => isset($match['starts']) ?
                        date('m/d/Y, H:i:s', strtotime($match['starts'])) : 'TBD',
                    'match_type' => $matchType, // Determined by Pinnacle live_status_id
                    'live_status_id' => $liveStatusId,
                    'betting_availability' => $match['betting_availability'] ?? 'prematch', // Preserve betting availability status
                    'has_open_markets' => $match['is_have_open_markets'] ?? false,
                    'home_score' => $match['home_score'] ?? 0, // Live score from Pinnacle API
                    'away_score' => $match['away_score'] ?? 0, // Live score from Pinnacle API
                    'match_duration' => $match['clock'] ?? $match['period'] ?? null, // Match time/duration
                    'odds_count' => 0, // Will be updated by OddsSyncJob
                    'images' => [
                        'home_team_logo' => $homeEnrichment['logo_url'] ?? null,
                        'away_team_logo' => $awayEnrichment['logo_url'] ?? null,
                        'league_logo' => null,
                        'country_flag' => null
                    ],
                    'markets' => [
                        'money_line' => ['count' => rand(4, 8), 'available' => true],
                        'spreads' => ['count' => rand(30, 45), 'available' => true],
                        'totals' => ['count' => rand(18, 28), 'available' => true],
                        'player_props' => ['count' => rand(25, 35), 'available' => rand(0, 1) == 1]
                    ],
                    'last_updated' => $match['last'] ? date('c', $match['last']) : date('c'),
                    'pinnacle_last_update' => $match['last'] ?? null
                ];

                // Always add to processed matches - don't skip even if team_id is null
                // Team resolution can happen later, but match should be saved
                $processedMatches[] = $processedMatch;

            } catch (\Exception $e) {
                // CRITICAL FIX: Don't skip matches on team resolution failure
                // Save match with null team_id - team resolution can happen later
                // Only skip if it's a critical error (e.g., missing required data)
                $hasRequiredData = isset($match['event_id']) && 
                                   isset($match['home']) && 
                                   isset($match['away']);
                
                if (!$hasRequiredData) {
                    // Missing critical data - skip this match
                    Log::warning('Skipping match - missing required data', [
                        'match_id' => $match['event_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
                
                // Team resolution failed, but we have required data - save match with null team_id
                Log::info('Saving match with null team_id (team resolution failed)', [
                    'match_id' => $match['event_id'] ?? 'unknown',
                    'home_team' => $match['home'] ?? 'Unknown',
                    'away_team' => $match['away'] ?? 'Unknown',
                    'error' => $e->getMessage()
                ]);
                
                // Create processed match with null team_id
                $liveStatusId = $match['live_status_id'] ?? 0;
                $matchStartTime = isset($match['starts']) ? strtotime($match['starts']) : null;
                $hasStarted = $matchStartTime && $matchStartTime <= time();
                
                if ($liveStatusId === 1 && !$hasStarted) {
                    $liveStatusId = 0;
                }
                
                $processedMatch = [
                    'id' => $match['event_id'],
                    'sport_id' => $sportId,
                    'home_team' => $match['home'] ?? 'Unknown',
                    'away_team' => $match['away'] ?? 'Unknown',
                    'home_team_id' => null, // Team resolution failed - will be resolved later
                    'away_team_id' => null, // Team resolution failed - will be resolved later
                    'league_id' => $match['league_id'] ?? null,
                    'league_name' => $match['league_name'] ?? 'Unknown',
                    'scheduled_time' => isset($match['starts']) ?
                        date('m/d/Y, H:i:s', strtotime($match['starts'])) : 'TBD',
                    'match_type' => ($liveStatusId === 1) ? 'live' : 'prematch',
                    'live_status_id' => $liveStatusId,
                    'betting_availability' => $match['betting_availability'] ?? 'prematch',
                    'has_open_markets' => $match['is_have_open_markets'] ?? false,
                    'home_score' => $match['home_score'] ?? 0,
                    'away_score' => $match['away_score'] ?? 0,
                    'match_duration' => $match['clock'] ?? $match['period'] ?? null,
                    'odds_count' => 0,
                    'images' => [
                        'home_team_logo' => null,
                        'away_team_logo' => null,
                        'league_logo' => null,
                        'country_flag' => null
                    ],
                    'markets' => [],
                    'pinnacle_last_update' => $match['last'] ?? null
                ];
                
                $processedMatches[] = $processedMatch;
            }
        }

        return $processedMatches;
    }

    private function groupMatchesByLeague($matches)
    {
        $grouped = [];

        foreach ($matches as $match) {
            $leagueId = $match['league_id'] ?? 'unknown';
            if (!isset($grouped[$leagueId])) {
                $grouped[$leagueId] = [];
            }
            $grouped[$leagueId][] = $match;
        }

        return $grouped;
    }

    private function cacheLiveMatchesForLeague($leagueId, $matches, $sportId)
    {
        $cacheKey = "live_matches:{$sportId}:{$leagueId}";
        $staleCacheKey = "live_matches_stale:{$sportId}:{$leagueId}";

        // Filter matches: only matches with live_status_id = 1 should be in live cache
        $filteredMatches = array_filter($matches, function($match) {
            $liveStatusId = $match['live_status_id'] ?? 0;
            return $liveStatusId === 1; // Only matches Pinnacle marks as live
        });

        $deduplicatedMatches = [];
        $seenGames = [];

        foreach ($filteredMatches as $match) {
            // Group matches by: sport_id, league_id, home_team_id, away_team_id, scheduled_time
            $gameKey = $match['sport_id'] . '|' . $match['league_id'] . '|' . $match['home_team_id'] . '|' . $match['away_team_id'] . '|' . $match['scheduled_time'];
            if (!isset($seenGames[$gameKey])) {
                $seenGames[$gameKey] = true;
                $deduplicatedMatches[] = $match;
            }
        }

        // Store current data as stale backup before updating
        $currentData = Cache::get($cacheKey);
        if ($currentData) {
            Cache::put($staleCacheKey, $currentData, 300); // 5 min stale backup
        }

        // Cache deduplicated live matches with 3-minute TTL
        Cache::put($cacheKey, array_values($deduplicatedMatches), 180);

        Log::debug('Cached live matches for league', [
            'cache_key' => $cacheKey,
            'original_count' => count($matches),
            'filtered_count' => count($filteredMatches),
            'ttl_seconds' => 180
        ]);
    }

    private function updateDatabaseSelectively($matches)
    {
        $updatedCount = 0;

        foreach ($matches as $matchData) {
            try {
                $existingMatch = SportsMatch::where('eventId', $matchData['id'])->first();

                // If no exact eventId match, try to find duplicate by teams and time
                if (!$existingMatch) {
                    $existingMatch = $this->findDuplicateMatch($matchData);
                }

                // CRITICAL: Never overwrite matches that are already marked as finished
                // This prevents LiveMatchSyncJob from resetting finished matches back to live
                if ($existingMatch && ($existingMatch->live_status_id == 2 || $existingMatch->live_status_id == -1)) {
                    Log::debug('Skipping update for finished match - preventing reset to live', [
                        'match_id' => $matchData['id'],
                        'existing_live_status_id' => $existingMatch->live_status_id,
                        'pinnacle_live_status_id' => $matchData['live_status_id'] ?? 0,
                        'home_team' => $matchData['home_team'],
                        'away_team' => $matchData['away_team']
                    ]);
                    continue; // Skip finished matches - don't reset them to live
                }

                // IMPORTANT: Always save new matches (even if they don't exist yet)
                // This ensures all Pinnacle live matches are in our database
                // For live matches, always update lastUpdated even if nothing else changed
                $isLiveMatch = ($matchData['live_status_id'] ?? 0) == 1;
                $shouldUpdate = !$existingMatch || $this->hasLiveMatchChanged($existingMatch, $matchData) || $isLiveMatch;
                
                if ($shouldUpdate) {
                    // Validate match type transition (sportsbook safety rules)
                    if ($existingMatch && !$this->isValidMatchTypeTransition($existingMatch->eventType, $matchData['match_type'])) {
                        Log::warning('Invalid match type transition blocked', [
                            'match_id' => $matchData['id'],
                            'current_type' => $existingMatch->eventType,
                            'new_type' => $matchData['match_type'],
                            'live_status_id' => $matchData['live_status_id']
                        ]);
                        continue; // Skip invalid transition
                    }

                    $scheduledTime = null;
                    if ($matchData['scheduled_time'] !== 'TBD') {
                        $scheduledTime = \DateTime::createFromFormat('m/d/Y, H:i:s', $matchData['scheduled_time']);
                    }

                    // If we found a duplicate, update it instead of the eventId
                    $updateKey = $existingMatch ? ['eventId' => $existingMatch->eventId] : ['eventId' => $matchData['id']];

                    // Log if we're merging a duplicate
                    if ($existingMatch && $existingMatch->eventId != $matchData['id']) {
                        Log::info('Merging duplicate match', [
                            'old_event_id' => $existingMatch->eventId,
                            'new_event_id' => $matchData['id'],
                            'home_team' => $matchData['home_team'],
                            'away_team' => $matchData['away_team']
                        ]);
                    }

                    // Build update array - but preserve finished status if match is already finished
                    // IMPORTANT: Save matches even if team_id is null - team resolution can happen later
                    // IMPORTANT: For live matches, always update lastUpdated to show recent activity
                    $updateData = [
                        'homeTeam' => $matchData['home_team'],
                        'awayTeam' => $matchData['away_team'],
                        'home_team_id' => $matchData['home_team_id'] ?? null, // Allow null - will be resolved later
                        'away_team_id' => $matchData['away_team_id'] ?? null, // Allow null - will be resolved later
                        'sportId' => $matchData['sport_id'],
                        'leagueId' => $matchData['league_id'],
                        'leagueName' => $matchData['league_name'] ?? 'Unknown',
                        'startTime' => $scheduledTime,
                        'eventType' => $matchData['match_type'], // Keep eventType for backward compatibility
                        'match_type' => $matchData['match_type'], // Add match_type for consistency
                        'betting_availability' => $matchData['betting_availability'] ?? 'prematch', // New betting availability status
                        'hasOpenMarkets' => $matchData['has_open_markets'],
                        'home_score' => $matchData['home_score'] ?? 0,
                        'away_score' => $matchData['away_score'] ?? 0,
                        'match_duration' => $matchData['match_duration'] ?? null,
                        'lastUpdated' => $matchData['pinnacle_last_update'] ? \Carbon\Carbon::createFromTimestamp($matchData['pinnacle_last_update']) : now()
                    ];

                    // CRITICAL: Only update live_status_id if match is NOT already finished
                    // BUT: If aggregation system says it's live (live_status_id = 1), trust it
                    // This allows aggregation system to restore matches that were incorrectly marked as finished
                    $newLiveStatusId = $matchData['live_status_id'] ?? 0;
                    if (!$existingMatch) {
                        // New match - use the status from aggregation
                        $updateData['live_status_id'] = $newLiveStatusId;
                    } elseif ($existingMatch->live_status_id == 2 || $existingMatch->live_status_id == -1) {
                        // Existing match is marked as finished
                        if ($newLiveStatusId == 1) {
                            // Aggregation says it's live - trust aggregation and restore it
                            $updateData['live_status_id'] = 1;
                            Log::info('Restoring match to live status from aggregation', [
                                'match_id' => $matchData['id'],
                                'previous_status' => $existingMatch->live_status_id,
                                'new_status' => 1
                            ]);
                        } else {
                            // Preserve finished status
                            Log::debug('Preserving finished status for match', [
                                'match_id' => $matchData['id'],
                                'preserved_live_status_id' => $existingMatch->live_status_id,
                                'aggregation_status' => $newLiveStatusId
                            ]);
                        }
                    } else {
                        // Match is not finished - update status normally
                        $updateData['live_status_id'] = $newLiveStatusId;
                    }

                    SportsMatch::updateOrCreate($updateKey, $updateData);

                    // Dispatch venue enrichment job if not already enriched
                    $this->dispatchVenueEnrichmentIfNeeded($matchData['id']);

                    $updatedCount++;
                }

            } catch (\Exception $e) {
                Log::warning('Failed to update live match in database', [
                    'match_id' => $matchData['id'],
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('Database updates completed for live matches', [
            'total_matches' => count($matches),
            'updated_in_db' => $updatedCount
        ]);
    }

    private function hasLiveMatchChanged($existing, $new): bool
    {
        // For live matches, always consider them "changed" to update lastUpdated timestamp
        // This ensures the "Updated Xm ago" display is always current for active live matches
        if (($new['live_status_id'] ?? 0) === 1) {
            return true;
        }
        
        // For other matches, check if critical data changed
        // Allow match_type transitions: LIVE → FINISHED, or status changes
        return $existing->eventType != $new['match_type'] || // Allow match type transitions
               $existing->live_status_id != ($new['live_status_id'] ?? 0) ||
               $existing->hasOpenMarkets != $new['has_open_markets'] ||
               $existing->betting_availability != ($new['betting_availability'] ?? 'prematch') ||
               $existing->startTime != ($new['scheduled_time'] !== 'TBD' ?
                   \DateTime::createFromFormat('m/d/Y, H:i:s', $new['scheduled_time']) : null);
    }

    /**
     * Validate match type transitions according to sportsbook rules
     * Returns true if transition is allowed, false if blocked
     */
    private function isValidMatchTypeTransition(string $currentType, string $newType): bool
    {
        // Pinnacle-central transition rules:
        // - LIVE → can transition to FINISHED (but we don't track finished status)
        // - PREMATCH → can transition to LIVE (when Pinnacle confirms)
        // - PREMATCH must NEVER appear as LIVE unless Pinnacle confirms it
        // - Finished matches must never revert to LIVE

        // Same type is always allowed
        if ($currentType === $newType) {
            return true;
        }

        // PREMATCH → LIVE: Allowed (when Pinnacle confirms via live_status_id)
        if ($currentType === 'prematch' && $newType === 'live') {
            return true;
        }

        // LIVE → PREMATCH: NOT allowed (matches don't go back to prematch)
        if ($currentType === 'live' && $newType === 'prematch') {
            return false;
        }

        // Any other transitions: Allow for now (could add more rules later)
        return true;
    }

    /**
     * Merge live scores and status from API-Football with Pinnacle match data
     */
    private function mergeLiveScoresFromApiFootball($pinnacleMatches, ApiFootballService $apiFootballService)
    {
        try {
            // Fetch all live fixtures from API-Football (soccer)
            $liveFixturesData = $apiFootballService->getFixtures(null, null, true);
            $liveFixtures = $liveFixturesData['response'] ?? [];

            // Fetch recently finished fixtures from multiple dates to filter out finished matches
            $finishedFixtures = [];
            $datesToCheck = [
                date('Y-m-d'), // Today
                date('Y-m-d', strtotime('-1 day')), // Yesterday
                date('Y-m-d', strtotime('-2 days')), // 2 days ago
            ];

            foreach ($datesToCheck as $date) {
                $finishedFixturesData = $apiFootballService->getFinishedFixtures($date);
                $dayFinishedFixtures = $finishedFixturesData['response'] ?? [];
                $finishedFixtures = array_merge($finishedFixtures, $dayFinishedFixtures);
            }

            Log::info('Fetched fixtures from API-Football', [
                'live_fixtures_count' => count($liveFixtures),
                'finished_fixtures_count' => count($finishedFixtures),
                'dates_checked' => $datesToCheck
            ]);

            // Create lookup for finished matches to filter them out
            $finishedMatchesLookup = [];
            $finishedMatchesCacheKeys = [];

            foreach ($finishedFixtures as $fixture) {
                $status = $fixture['fixture']['status']['short'] ?? '';
                // Only consider truly finished matches (FT = Full Time, AET = After Extra Time, etc.)
                if (in_array($status, ['FT', 'AET', 'PEN', 'AWD', 'AET', 'Canc', 'PST'])) {
                    $homeTeam = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                    $awayTeam = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');
                    $key = $homeTeam . '|' . $awayTeam;
                    $finishedMatchesLookup[$key] = [
                        'fixture' => $fixture,
                        'status' => $status,
                        'original_home' => $fixture['teams']['home']['name'] ?? '',
                        'original_away' => $fixture['teams']['away']['name'] ?? ''
                    ];

                    // Collect cache keys that need to be cleared for this sport
                    if ($this->sportId) {
                        $finishedMatchesCacheKeys[] = "live_matches:{$this->sportId}:{$fixture['league']['id']}";
                    }
                }
            }

            // Clear cache entries for finished matches
            // We need to clear cache for ALL leagues since finished matches could be from any league
            $cacheClearedCount = 0;
            if (!empty($finishedMatchesLookup)) {
                // If we have specific leagues requested, only clear those
                $leaguesToClear = !empty($this->leagueIds) ? $this->leagueIds : [];

                // If no specific leagues, we need to clear cache for all leagues that have finished matches
                if (empty($leaguesToClear)) {
                    $leaguesToClear = [];
                    foreach ($finishedFixtures as $fixture) {
                        $leagueId = $fixture['league']['id'] ?? null;
                        if ($leagueId && !in_array($leagueId, $leaguesToClear)) {
                            $leaguesToClear[] = $leagueId;
                        }
                    }
                }

                // Clear live matches cache for affected leagues
                foreach ($leaguesToClear as $leagueId) {
                    $cacheKey = "live_matches:{$this->sportId}:{$leagueId}";
                    \Illuminate\Support\Facades\Cache::forget($cacheKey);
                    $cacheClearedCount++;

                    // Also clear stale cache
                    $staleCacheKey = "live_matches_stale:{$this->sportId}:{$leagueId}";
                    \Illuminate\Support\Facades\Cache::forget($staleCacheKey);
                }

                Log::info('Cleared cache entries for finished matches', [
                    'leagues_cleared' => count($leaguesToClear),
                    'finished_matches_found' => count($finishedMatchesLookup)
                ]);
            }

            // CRITICAL FIX: Don't filter finished matches BEFORE processing
            // This was too aggressive - it removed matches from Pinnacle if API-Football said finished
            // But Pinnacle is authoritative for live betting status
            // Instead, let aggregation system handle deduplication and trust Pinnacle's live_status_id
            // Finished match detection is handled by MatchStatusManager, not pre-filtering
            
            // Use all Pinnacle matches - don't pre-filter based on API-Football finished status
            $filteredPinnacleMatches = $pinnacleMatches;
            
            Log::info('Using all Pinnacle matches (not pre-filtering finished)', [
                'pinnacle_matches' => count($pinnacleMatches),
                'finished_matches_in_lookup' => count($finishedMatchesLookup),
                'note' => 'Finished match detection handled by MatchStatusManager, not pre-filtering'
            ]);

            if (empty($liveFixtures)) {
                Log::info('No live fixtures from API-Football, returning filtered Pinnacle matches');
                return $filteredPinnacleMatches;
            }

            // Create lookup maps for API-Football fixtures
            $liveLookup = [];
            $prematchLookup = [];

            // Index live fixtures by normalized team names
            foreach ($liveFixtures as $fixture) {
                $homeTeam = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                $awayTeam = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');
                $key = $homeTeam . '|' . $awayTeam;
                $liveLookup[$key] = $fixture;

                // Also try reverse order for better matching
                $reverseKey = $awayTeam . '|' . $homeTeam;
                if (!isset($liveLookup[$reverseKey])) {
                    $liveLookup[$reverseKey] = $fixture;
                }
            }

            // For prematch, we could fetch some upcoming fixtures, but for now focus on live
            // This can be enhanced later to also detect upcoming matches

            // Merge live status and scores for each Pinnacle match
            $mergedMatches = [];
            $matchesWithLiveData = 0;
            $matchesAvailableForBetting = 0;

            foreach ($filteredPinnacleMatches as $pinnacleMatch) {
                $originalLiveStatusId = $pinnacleMatch['live_status_id'] ?? 0;

                // Skip betting markets for better matching
                $homeTeam = $this->normalizeTeamName($pinnacleMatch['home'] ?? '');
                $awayTeam = $this->normalizeTeamName($pinnacleMatch['away'] ?? '');

                // Skip if it looks like a betting market
                if ($this->isBettingMarket($pinnacleMatch)) {
                    $mergedMatches[] = $pinnacleMatch;
                    continue;
                }

                $lookupKey = $homeTeam . '|' . $awayTeam;

                // Check if this Pinnacle match has a corresponding live fixture in API-Football
                $apiFootballFixture = $liveLookup[$lookupKey] ?? null;

                // CRITICAL FIX: Trust Pinnacle's live_status_id if match has started
                // Don't downgrade to available_for_betting just because API-Football doesn't have it
                // API-Football is used for score enhancement, NOT status verification
                // Pinnacle is authoritative for betting status
                $matchStartTime = isset($pinnacleMatch['starts']) ? \Carbon\Carbon::parse($pinnacleMatch['starts']) : null;
                $hasMatchStarted = $matchStartTime && $matchStartTime->lte(\Carbon\Carbon::now());

                // If Pinnacle says live AND match has started, trust Pinnacle (even if API-Football missing)
                if ($originalLiveStatusId === 1 && $hasMatchStarted) {
                    // Trust Pinnacle - keep as live, use API-Football only for score enhancement
                    if ($apiFootballFixture) {
                        // API-Football has it - enhance with scores
                        $pinnacleMatch['home_score'] = $apiFootballFixture['goals']['home'] ?? 0;
                        $pinnacleMatch['away_score'] = $apiFootballFixture['goals']['away'] ?? 0;
                        
                        $status = $apiFootballFixture['fixture']['status']['long'] ?? '';
                        $elapsed = $apiFootballFixture['fixture']['status']['elapsed'] ?? null;
                        if ($elapsed) {
                            $pinnacleMatch['clock'] = $elapsed . "' " . $status;
                            $pinnacleMatch['period'] = $status;
                        }
                    }
                    // Keep live_status_id = 1 and betting_availability = 'live'
                    $pinnacleMatch['betting_availability'] = 'live';
                    $mergedMatches[] = $pinnacleMatch;
                    $matchesWithLiveData++;
                    continue;
                }

                // If Pinnacle says live but match hasn't started, mark as available_for_betting
                if ($originalLiveStatusId === 1 && !$hasMatchStarted) {
                    Log::info('Pinnacle marks as live but match has not started yet', [
                        'match_id' => $pinnacleMatch['event_id'] ?? 'unknown',
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'start_time' => $pinnacleMatch['starts'] ?? 'N/A'
                    ]);
                    $pinnacleMatch['live_status_id'] = 0;
                    $pinnacleMatch['betting_availability'] = 'available_for_betting';
                    $mergedMatches[] = $pinnacleMatch;
                    $matchesAvailableForBetting++;
                    continue;
                }

                if ($apiFootballFixture) {
                    // This match is ACTUALLY LIVE! Enhanced with API-Football data
                    $pinnacleMatch['home_score'] = $apiFootballFixture['goals']['home'] ?? 0;
                    $pinnacleMatch['away_score'] = $apiFootballFixture['goals']['away'] ?? 0;

                    // Update match duration with live status
                    $status = $apiFootballFixture['fixture']['status']['long'] ?? '';
                    $elapsed = $apiFootballFixture['fixture']['status']['elapsed'] ?? null;

                    if ($elapsed) {
                        $pinnacleMatch['clock'] = $elapsed . "' " . $status;
                        $pinnacleMatch['period'] = $status;
                    }

                    // Set statuses
                    $pinnacleMatch['live_status_id'] = 1; // Mark as live
                    $pinnacleMatch['betting_availability'] = 'live'; // Actually live with scores

                    $matchesWithLiveData++;

                    Log::debug('Enhanced Pinnacle match with API-Football live data', [
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'score' => $pinnacleMatch['home_score'] . '-' . $pinnacleMatch['away_score'],
                        'status' => $status,
                        'elapsed' => $elapsed,
                        'betting_availability' => 'live'
                    ]);
                } elseif ($originalLiveStatusId === 1) {
                    // CRITICAL FIX: Trust Pinnacle's live_status_id if match has started
                    // Don't downgrade just because API-Football doesn't have it
                    $matchStartTime = isset($pinnacleMatch['starts']) ? \Carbon\Carbon::parse($pinnacleMatch['starts']) : null;
                    $hasMatchStarted = $matchStartTime && $matchStartTime->lte(\Carbon\Carbon::now());

                    if ($hasMatchStarted) {
                        // Match has started - trust Pinnacle, keep as live
                        $pinnacleMatch['betting_availability'] = 'live';
                        Log::debug('Pinnacle match is live (trusting Pinnacle status)', [
                            'home_team' => $pinnacleMatch['home'],
                            'away_team' => $pinnacleMatch['away'],
                            'live_status_id' => $originalLiveStatusId,
                            'betting_availability' => 'live',
                            'note' => 'API-Football not available, but trusting Pinnacle'
                        ]);
                    } else {
                        // Match hasn't started - mark as available for betting
                        $pinnacleMatch['betting_availability'] = 'available_for_betting';
                        $matchesAvailableForBetting++;
                        Log::debug('Pinnacle match available for betting (not started yet)', [
                            'home_team' => $pinnacleMatch['home'],
                            'away_team' => $pinnacleMatch['away'],
                            'live_status_id' => $originalLiveStatusId,
                            'betting_availability' => 'available_for_betting'
                        ]);
                    }
                } else {
                    // Regular prematch match
                    $pinnacleMatch['betting_availability'] = 'prematch';

                    Log::debug('Pinnacle match set as prematch', [
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'betting_availability' => 'prematch'
                    ]);
                }

                $mergedMatches[] = $pinnacleMatch;
            }

            Log::info('Completed live status and score enhancement', [
                'total_pinnacle_matches' => count($filteredPinnacleMatches),
                'matches_enhanced_with_live_data' => $matchesWithLiveData,
                'note' => 'No pre-filtering of finished matches - trust Pinnacle live_status_id'
            ]);

            return $mergedMatches;

        } catch (\Exception $e) {
            Log::error('Failed to merge live scores from API-Football', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return original Pinnacle matches if API-Football fails
            return $pinnacleMatches;
        }
    }

    /**
     * Map Pinnacle sport IDs to API-Football sport IDs
     */
    private function mapPinnacleToApiFootballSportId($pinnacleSportId)
    {
        $sportMapping = [
            1 => 1,  // Soccer
            2 => 2,  // Basketball? (need to verify)
            3 => 3,  // Basketball
            4 => 4,  // American Football
            5 => 5,  // Hockey
            6 => 6,  // Baseball
            7 => 4,  // NFL (American Football)
        ];

        return $sportMapping[$pinnacleSportId] ?? null;
    }

    /**
     * Check if a Pinnacle match appears to be a betting market rather than a real match
     * IMPROVED: Only filter clear betting markets, not all matches with parentheses
     */
    private function isBettingMarket($pinnacleMatch)
    {
        $homeTeam = $pinnacleMatch['home'] ?? '';
        $awayTeam = $pinnacleMatch['away'] ?? '';
        $leagueName = $pinnacleMatch['league_name'] ?? '';

        // IMPROVED: Only filter if parentheses contain clear betting market keywords
        // Don't filter all parentheses - some valid matches have parentheses (e.g., team nicknames)
        $bettingMarketKeywords = [
            'corners', 'corner', 'cards', 'card', 'goals', 'goal', 'shots', 'shot',
            'fouls', 'foul', 'offsides', 'offside', 'penalties', 'penalty',
            'red cards', 'yellow cards', 'total', 'over', 'under', 'handicap',
            'first half', 'second half', '1h', '2h', 'ht', 'ft', 'both teams',
            'btts', 'clean sheet', 'to score', 'assist', 'player', 'prop',
            'bookings', 'throw ins', 'throw-ins'
        ];

        // Check team names for betting market keywords in parentheses
        $homeTeamMatch = preg_match('/\(([^)]+)\)/', $homeTeam, $homeMatches);
        $awayTeamMatch = preg_match('/\(([^)]+)\)/', $awayTeam, $awayMatches);

        if ($homeTeamMatch || $awayTeamMatch) {
            $parenthesesContent = '';
            if ($homeTeamMatch) {
                $parenthesesContent .= ' ' . strtolower($homeMatches[1]);
            }
            if ($awayTeamMatch) {
                $parenthesesContent .= ' ' . strtolower($awayMatches[1]);
            }

            // Only filter if parentheses contain betting market keywords
            foreach ($bettingMarketKeywords as $keyword) {
                if (stripos($parenthesesContent, $keyword) !== false) {
                    Log::debug('Filtered betting market match', [
                        'home_team' => $homeTeam,
                        'away_team' => $awayTeam,
                        'matched_keyword' => $keyword
                    ]);
                    return true;
                }
            }
        }

        // Check for betting market keywords in league name
        if (preg_match('/\b(corners|cards|goals|shots|fouls|offsides|penalties|red cards|yellow cards|total|over|under|handicap|props|bookings|throw.*ins)\b/i', $leagueName)) {
            return true;
        }

        // Check if both team names are very short (likely betting markets like "Over 2.5" vs "Under 2.5")
        if (strlen(trim($homeTeam)) < 5 && strlen(trim($awayTeam)) < 5) {
            // Check if they look like betting lines
            if (preg_match('/^\d+\.?\d*$/', trim($homeTeam)) || preg_match('/^\d+\.?\d*$/', trim($awayTeam))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an API-Football fixture is currently live
     */
    private function isApiFootballMatchLive($apiFootballFixture)
    {
        $status = $apiFootballFixture['fixture']['status']['long'] ?? '';

        $liveStatuses = [
            'First Half',
            'Halftime',
            'Second Half',
            'Extra Time',
            'Penalty',
            'Match Suspended'
        ];

        return in_array($status, $liveStatuses);
    }

    /**
     * Remove finished matches from database
     */
    private function removeFinishedMatches(ApiFootballService $apiFootballService): int
    {
        try {
            // Get finished fixtures from API Football
            $finishedFixtures = $apiFootballService->getFinishedFixtures();

            if (empty($finishedFixtures['response'])) {
                Log::info('No finished fixtures found for cleanup');
                return 0;
            }

            $removedCount = 0;

            foreach ($finishedFixtures['response'] as $fixture) {
                $homeTeam = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                $awayTeam = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');

                // Try to find matching match in database
                $match = SportsMatch::where('sportId', $this->sportId ?? 1)
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
                        'api_football_status' => $fixture['fixture']['status']['short']
                    ]);

                    $match->delete();
                    $removedCount++;
                }
            }

            Log::info('Finished matches cleanup completed', [
                'finished_fixtures_checked' => count($finishedFixtures['response']),
                'matches_removed' => $removedCount
            ]);

            return $removedCount;

        } catch (\Exception $e) {
            Log::error('Error during finished matches cleanup', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Convert aggregated matches to format expected by team resolution
     * 
     * Aggregated matches use a unified structure. This method converts them
     * to the format expected by the existing team resolution logic.
     * 
     * MATCH DEDUPLICATION LOGIC:
     * - Matches are deduplicated by normalized team names + league + start time (±5 min)
     * - Home/away order is handled (both directions checked)
     * - Each real-world match appears only once in the result
     * 
     * @param array $aggregatedMatches Aggregated matches from MatchAggregationService
     * @return array Matches in resolution format
     */
    private function convertAggregatedToResolutionFormat(array $aggregatedMatches): array
    {
        $converted = [];
        
        foreach ($aggregatedMatches as $match) {
            // Use the primary provider's event_id, or first available
            $eventId = $match['event_id'] ?? null;
            if (!$eventId && !empty($match['providers'])) {
                // Try to get event_id from metadata
                $metadata = $match['metadata'] ?? [];
                $eventId = $metadata['pinnacle_event_id'] ?? 
                          $metadata['api_football_fixture_id'] ?? 
                          null;
            }
            
            $converted[] = [
                'event_id' => $eventId,
                'home' => $match['home_team'] ?? 'Unknown',
                'away' => $match['away_team'] ?? 'Unknown',
                'league_id' => $match['league_id'] ?? null,
                'league_name' => $match['league_name'] ?? 'Unknown',
                'sport_id' => $match['sport_id'] ?? $this->sportId ?? 1,
                'starts' => $match['start_time'] instanceof \Carbon\Carbon 
                    ? $match['start_time']->toDateTimeString() 
                    : ($match['start_time'] ?? null),
                'live_status_id' => $match['live_status_id'] ?? 0,
                'betting_availability' => $match['betting_availability'] ?? 'prematch',
                'is_have_open_markets' => $match['has_open_markets'] ?? false,
                'home_score' => $match['home_score'] ?? 0,
                'away_score' => $match['away_score'] ?? 0,
                'clock' => $match['match_duration'] ?? null,
                'period' => $match['period'] ?? null,
                'last' => isset($match['last_updated']) ? strtotime($match['last_updated']) : time(),
                // Preserve aggregation metadata for traceability
                'aggregated_providers' => $match['providers'] ?? [],
                'aggregated_metadata' => $match['metadata'] ?? [],
            ];
        }
        
        return $converted;
    }

    /**
     * Normalize team names for better matching between APIs
     */
    private function normalizeTeamName($teamName)
    {
        if (!$teamName) return '';

        // Convert to lowercase and remove common suffixes/prefixes
        $normalized = strtolower($teamName);

        // Remove common team name variations
        $normalized = preg_replace('/\s+(fc|ac|cf|sc|club|united|city|town|athletic|wanderers|rovers|hotspur|albion|villans?)\b/i', '', $normalized);
        $normalized = preg_replace('/\b(fc|ac|cf|sc|club|united|city|town|athletic|wanderers|rovers|hotspur|albion|villans?)\s+/i', '', $normalized);

        // Remove age group suffixes (U20, U19, etc.)
        $normalized = preg_replace('/\s+u\d+\b/i', '', $normalized);

        // Remove extra spaces and trim
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        return $normalized;
    }

    private function dispatchVenueEnrichmentIfNeeded($matchId): void
    {
        try {
            // Check if venue enrichment already exists and is recent
            $existing = \App\Models\MatchEnrichment::where('match_id', $matchId)->first();
            if ($existing && $existing->last_synced_at && $existing->last_synced_at->diffInDays(now()) < 7) {
                return; // Already enriched and recent
            }

            // Dispatch venue enrichment job (API-Football)
            // \App\Jobs\EnrichMatchVenueJob::dispatch($matchId, null)->onQueue('enrichment');
        } catch (\Exception $e) {
            Log::warning('Failed to dispatch venue enrichment', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Remove finished match from database
     */
    private function removeFinishedMatchFromDatabase(string $homeTeam, string $awayTeam, string $reason): bool
    {
        try {
            // Use direct database query since the table structure is non-standard
            $match = DB::table('matches')
                ->where('sportId', $this->sportId ?? 1)
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
                Log::info('Removing finished match from database (live sync)', [
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
            Log::warning('Failed to remove finished match from database (live sync)', [
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Find duplicate matches by team names and similar timing
     * This prevents creating duplicate records when Pinnacle updates match details
     */
    private function findDuplicateMatch($matchData)
    {
        // Only look for duplicates if we have team IDs and scheduled time
        if (empty($matchData['home_team_id']) || empty($matchData['away_team_id']) || $matchData['scheduled_time'] === 'TBD') {
            return null;
        }

        try {
            $scheduledTime = \DateTime::createFromFormat('m/d/Y, H:i:s', $matchData['scheduled_time']);
            if (!$scheduledTime) {
                return null;
            }

            // Look for matches with same teams within a 4-hour window
            $startWindow = clone $scheduledTime;
            $startWindow->modify('-2 hours');
            $endWindow = clone $scheduledTime;
            $endWindow->modify('+2 hours');

            $duplicate = SportsMatch::where('home_team_id', $matchData['home_team_id'])
                ->where('away_team_id', $matchData['away_team_id'])
                ->whereBetween('startTime', [$startWindow, $endWindow])
                ->where('sportId', $matchData['sport_id'])
                ->where('eventId', '!=', $matchData['id']) // Exclude the current eventId
                ->first();

            if ($duplicate) {
                Log::info('Found duplicate match to merge', [
                    'new_event_id' => $matchData['id'],
                    'existing_event_id' => $duplicate->eventId,
                    'home_team' => $matchData['home_team'],
                    'away_team' => $matchData['away_team'],
                    'scheduled_time_diff' => $duplicate->startTime->diff($scheduledTime)->format('%h hours %i minutes')
                ]);
            }

            return $duplicate;
        } catch (\Exception $e) {
            Log::warning('Error finding duplicate match', [
                'event_id' => $matchData['id'],
                'home_team' => $matchData['home_team'],
                'away_team' => $matchData['away_team'],
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Clean up duplicate matches
     * IMPROVED: More aggressive duplicate detection and cleanup
     * Removes duplicates based on team names (even if team_id is null), league, and start time
     */
    private function cleanupDuplicateMatches(): int
    {
        $cleanedCount = 0;

        try {
            // IMPROVED: Find duplicates by team_id first (most reliable)
            $potentialDuplicates = DB::select("
                SELECT
                    home_team_id,
                    away_team_id,
                    DATE(startTime) as match_date,
                    COUNT(*) as match_count,
                    GROUP_CONCAT(eventId) as event_ids,
                    GROUP_CONCAT(live_status_id) as statuses,
                    GROUP_CONCAT(lastUpdated ORDER BY lastUpdated DESC) as update_times
                FROM matches
                WHERE home_team_id IS NOT NULL
                  AND away_team_id IS NOT NULL
                  AND startTime IS NOT NULL
                  AND sportId = ?
                GROUP BY home_team_id, away_team_id, DATE(startTime)
                HAVING COUNT(*) > 1
                ORDER BY match_date DESC
            ", [$this->sportId ?? 1]);

            // IMPROVED: Also find duplicates by team names (for matches with null team_id)
            $potentialDuplicatesByName = DB::select("
                SELECT
                    homeTeam,
                    awayTeam,
                    DATE(startTime) as match_date,
                    COUNT(*) as match_count,
                    GROUP_CONCAT(eventId) as event_ids,
                    GROUP_CONCAT(live_status_id) as statuses,
                    GROUP_CONCAT(lastUpdated ORDER BY lastUpdated DESC) as update_times
                FROM matches
                WHERE (home_team_id IS NULL OR away_team_id IS NULL)
                  AND startTime IS NOT NULL
                  AND sportId = ?
                GROUP BY homeTeam, awayTeam, DATE(startTime)
                HAVING COUNT(*) > 1
                ORDER BY match_date DESC
            ", [$this->sportId ?? 1]);

            // Combine both result sets
            $allDuplicates = array_merge($potentialDuplicates, $potentialDuplicatesByName);

            foreach ($allDuplicates as $group) {
                $eventIds = explode(',', $group->event_ids);
                $statuses = explode(',', $group->statuses);
                $updateTimes = explode(',', $group->update_times);

                // Find the most recent/live match to keep
                $keepIndex = null;
                $hasLiveMatch = false;
                $mostRecentIndex = 0;
                $mostRecentTime = null;

                foreach ($statuses as $index => $status) {
                    // Priority 1: Live match (status = 1)
                    if ($status == 1) {
                        $keepIndex = $index;
                        $hasLiveMatch = true;
                        break;
                    }
                    
                    // Priority 2: Most recently updated match
                    $updateTime = isset($updateTimes[$index]) ? $updateTimes[$index] : null;
                    if ($updateTime && (!$mostRecentTime || $updateTime > $mostRecentTime)) {
                        $mostRecentTime = $updateTime;
                        $mostRecentIndex = $index;
                    }
                }

                // If no live match, keep the most recent one
                if ($keepIndex === null) {
                    $keepIndex = $mostRecentIndex;
                }

                // If we found a match to keep, remove the others
                if ($keepIndex !== null) {
                    foreach ($eventIds as $index => $eventId) {
                        if ($index != $keepIndex) {
                            $matchToDelete = SportsMatch::where('eventId', $eventId)->first();
                            if ($matchToDelete) {
                                // Handle both team_id-based and team-name-based duplicates
                                $homeTeam = isset($group->homeTeam) ? $group->homeTeam : ($group->home_team_id ?? 'N/A');
                                $awayTeam = isset($group->awayTeam) ? $group->awayTeam : ($group->away_team_id ?? 'N/A');
                                
                                Log::info('Removing duplicate match', [
                                    'event_id' => $eventId,
                                    'home_team' => $homeTeam,
                                    'away_team' => $awayTeam,
                                    'status' => $statuses[$index],
                                    'keeping_event_id' => $eventIds[$keepIndex],
                                    'match_date' => $group->match_date ?? 'N/A'
                                ]);

                                $matchToDelete->delete();
                                $cleanedCount++;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Error cleaning up duplicate matches', [
                'error' => $e->getMessage(),
                'sportId' => $this->sportId
            ]);
        }

        return $cleanedCount;
    }

    /**
     * Update timestamps for matches that are visible as "live" but weren't processed by Pinnacle sync
     * This ensures live-visible matches show current timestamps even if Pinnacle doesn't update them
     */
    private function updateLiveVisibleMatchTimestamps(): int
    {
        $updatedCount = 0;

        try {
            // Find matches that are visible as "live" in the frontend but not updated recently
            // These are matches that:
            // - Have started (startTime <= now)
            // - Are not finished/cancelled (live_status_id not 2 or -1)
            // - Either have live_status_id = 1 OR (have open markets AND are available for betting)
            // - Were last updated more than 5 minutes ago

            // Update timestamps for ALL live matches, not just recent ones
            // This ensures "Updated Xm ago" is always current for active live matches
            // CRITICAL: Update ALL matches that are currently live, regardless of when they started
            $liveVisibleMatches = SportsMatch::where('sportId', $this->sportId ?? 1)
                ->whereNotIn('live_status_id', [-1, 2]) // Not cancelled or finished
                ->where(function($q) {
                    $q->where('live_status_id', 1) // Actually live
                      ->orWhere(function($subQ) {
                          // Matches with open markets and available for betting
                          $subQ->where('hasOpenMarkets', true)
                               ->where('betting_availability', 'available_for_betting');
                      })
                      ->orWhere(function($subQ) {
                          // Matches with scores (indicates they're playing)
                          $subQ->where('home_score', '>', 0)
                               ->orWhere('away_score', '>', 0);
                      })
                      ->orWhere(function($subQ) {
                          // Matches that have started and have live_status_id > 0
                          $subQ->whereNotNull('startTime')
                               ->where('startTime', '<=', now())
                               ->where('live_status_id', '>', 0);
                      });
                })
                ->where('lastUpdated', '<', now()->subMinutes(5)) // Not updated in last 5 minutes
                // REMOVED all time restrictions - update ALL live matches regardless of age
                ->get();

            foreach ($liveVisibleMatches as $match) {
                $match->update(['lastUpdated' => now()]);
                $updatedCount++;

                Log::debug('Updated timestamp for live-visible match', [
                    'event_id' => $match->eventId,
                    'home_team' => $match->homeTeam,
                    'away_team' => $match->awayTeam,
                    'live_status_id' => $match->live_status_id,
                    'has_open_markets' => $match->hasOpenMarkets,
                    'betting_availability' => $match->betting_availability
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error updating live-visible match timestamps', [
                'error' => $e->getMessage(),
                'sportId' => $this->sportId
            ]);
        }

        return $updatedCount;
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('LiveMatchSyncJob failed permanently', [
            'sportId' => $this->sportId,
            'leagueIds' => $this->leagueIds,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
