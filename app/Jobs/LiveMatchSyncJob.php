<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use App\Services\ApiFootballService;
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
        $this->sportId = $sportId;
        $this->leagueIds = $leagueIds;
    }

    /**
     * Execute the job - Phase 2: Background live match sync with caching
     */
    public function handle(PinnacleService $pinnacleService, TeamResolutionService $teamResolutionService, ApiFootballService $apiFootballService): void
    {
        Log::info('LiveMatchSyncJob started - Phase 2 optimization', [
            'sportId' => $this->sportId,
            'leagueIds' => $this->leagueIds
        ]);

        try {
            // Step 1: Fetch ALL live matches from Pinnacle for this sport
            // Note: Pinnacle API ignores league filtering when event_type=live, so we fetch all and filter client-side
            $liveMatchesData = $pinnacleService->getMatchesByLeagues(
                $this->sportId ?? 7, // Default to NFL if no sport specified
                [], // Always fetch ALL leagues (empty array)
                'live'
            );

            $allLiveMatches = $liveMatchesData['events'] ?? [];

            Log::info('Fetched ALL live matches from Pinnacle', [
                'total_match_count' => count($allLiveMatches),
                'sport_id' => $this->sportId,
                'requested_leagues' => $this->leagueIds
            ]);

            // Step 1.5: Apply client-side league filtering
            $liveMatches = [];
            if (!empty($this->leagueIds)) {
                foreach ($allLiveMatches as $match) {
                    if (in_array($match['league_id'], $this->leagueIds)) {
                        $liveMatches[] = $match;
                    }
                }
            } else {
                // If no specific leagues requested, include all matches
                $liveMatches = $allLiveMatches;
            }

            Log::info('After client-side league filtering', [
                'filtered_match_count' => count($liveMatches),
                'requested_leagues' => $this->leagueIds
            ]);

            if (empty($liveMatches)) {
                Log::info('No live matches found for requested leagues, skipping processing', [
                    'requested_leagues' => $this->leagueIds
                ]);
                return;
            }

            // Step 1.75: Fetch live scores from API-Football and merge with Pinnacle data
            $liveMatches = $this->mergeLiveScoresFromApiFootball($liveMatches, $apiFootballService);

            // Step 2: Process and resolve teams for all matches
            $processedMatches = $this->processMatchesWithTeamResolution(
                $liveMatches,
                $teamResolutionService,
                $this->sportId ?? 7
            );

            // Step 3: Group by league and cache
            $matchesByLeague = $this->groupMatchesByLeague($processedMatches);

            foreach ($matchesByLeague as $leagueId => $leagueMatches) {
                $this->cacheLiveMatchesForLeague($leagueId, $leagueMatches, $this->sportId ?? 7);
            }

            // Step 4: Update database selectively (only changed matches)
            $this->updateDatabaseSelectively($processedMatches);

        // Update all existing matches with live_status_id = 1 to available_for_betting
        // This ensures matches that are available for betting get the correct status
        // even if they weren't in the current Pinnacle response
        $availableForBettingUpdated = SportsMatch::where('live_status_id', 1)
            ->where('betting_availability', '!=', 'live') // Don't override actual live matches
            ->update(['betting_availability' => 'available_for_betting']);

        if ($availableForBettingUpdated > 0) {
            Log::info('Updated existing matches to available_for_betting', [
                'updated_count' => $availableForBettingUpdated
            ]);
        }

        // Remove finished matches from database
        $finishedMatchesRemoved = $this->removeFinishedMatches($apiFootballService);

        Log::info('LiveMatchSyncJob completed successfully', [
            'processed_matches' => count($processedMatches),
            'leagues_updated' => count($matchesByLeague),
            'available_for_betting_updated' => $availableForBettingUpdated,
            'finished_matches_removed' => $finishedMatchesRemoved
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
                // IF pinnacle_event.live_status_id === 1 → match_type = "live" ELSE → match_type = "prematch"
                $liveStatusId = $match['live_status_id'] ?? 0;
                $matchType = ($liveStatusId === 1) ? 'live' : 'prematch';

                $processedMatch = [
                    'id' => $match['event_id'],
                    'sport_id' => $sportId,
                    'home_team' => $match['home'] ?? 'Unknown',
                    'away_team' => $match['away'] ?? 'Unknown',
                    'home_team_id' => $homeTeamResolution['team_id'],
                    'away_team_id' => $awayTeamResolution['team_id'],
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

                $processedMatches[] = $processedMatch;

            } catch (\Exception $e) {
                Log::warning('Failed to process live match', [
                    'match_id' => $match['event_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                continue; // Skip this match, continue with others
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

                // Only update if match doesn't exist or key data changed
                if (!$existingMatch || $this->hasLiveMatchChanged($existingMatch, $matchData)) {
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

                    SportsMatch::updateOrCreate(
                        ['eventId' => $matchData['id']],
                        [
                            'homeTeam' => $matchData['home_team'],
                            'awayTeam' => $matchData['away_team'],
                            'home_team_id' => $matchData['home_team_id'],
                            'away_team_id' => $matchData['away_team_id'],
                            'sportId' => $matchData['sport_id'],
                            'leagueId' => $matchData['league_id'],
                            'startTime' => $scheduledTime,
                            'eventType' => $matchData['match_type'], // Keep eventType for backward compatibility
                            'match_type' => $matchData['match_type'], // Add match_type for consistency
                            'live_status_id' => $matchData['live_status_id'], // Add live_status_id
                            'betting_availability' => $matchData['betting_availability'] ?? 'prematch', // New betting availability status
                            'hasOpenMarkets' => $matchData['has_open_markets'],
                            'home_score' => $matchData['home_score'] ?? 0,
                            'away_score' => $matchData['away_score'] ?? 0,
                            'match_duration' => $matchData['match_duration'] ?? null,
                            'lastUpdated' => now()
                        ]
                    );

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
        // For live matches, check if critical live data changed
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

            // Filter out finished matches from Pinnacle matches BEFORE processing
            $filteredPinnacleMatches = [];
            $finishedMatchesFiltered = 0;

            foreach ($pinnacleMatches as $pinnacleMatch) {
                $homeTeam = $this->normalizeTeamName($pinnacleMatch['home'] ?? '');
                $awayTeam = $this->normalizeTeamName($pinnacleMatch['away'] ?? '');
                $lookupKey = $homeTeam . '|' . $awayTeam;

                // Try multiple variations of team name matching
                $foundInFinished = false;
                if (isset($finishedMatchesLookup[$lookupKey])) {
                    $foundInFinished = true;
                } else {
                    // Try reverse order
                    $reverseKey = $awayTeam . '|' . $homeTeam;
                    if (isset($finishedMatchesLookup[$reverseKey])) {
                        $foundInFinished = true;
                        $lookupKey = $reverseKey;
                    }
                }

                if ($foundInFinished) {
                    $finishedMatchesFiltered++;
                    Log::info('Filtered out finished match from live processing', [
                        'match_id' => $pinnacleMatch['event_id'] ?? 'unknown',
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'normalized_key' => $lookupKey,
                        'api_football_status' => $finishedMatchesLookup[$lookupKey]['status']
                    ]);

                    // Remove from database as well
                    $this->removeFinishedMatchFromDatabase(
                        $finishedMatchesLookup[$lookupKey]['original_home'],
                        $finishedMatchesLookup[$lookupKey]['original_away'],
                        'live_sync_' . $finishedMatchesLookup[$lookupKey]['status']
                    );

                    continue; // Skip this finished match
                }

                $filteredPinnacleMatches[] = $pinnacleMatch;
            }

            Log::info('Filtered finished matches from live processing', [
                'original_pinnacle_matches' => count($pinnacleMatches),
                'filtered_matches' => count($filteredPinnacleMatches),
                'finished_matches_removed' => $finishedMatchesFiltered
            ]);

            // If no matches remain after filtering, return early
            if (empty($filteredPinnacleMatches)) {
                Log::info('No live matches remain after filtering finished matches');
                return [];
            }

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

                // If Pinnacle says it's live but API-Football doesn't show it as live, mark it as not actually live
                if ($originalLiveStatusId === 1 && !$apiFootballFixture) {
                    Log::info('Pinnacle claims live but API-Football shows no live fixture', [
                        'match_id' => $pinnacleMatch['event_id'] ?? 'unknown',
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'lookup_key' => $lookupKey
                    ]);

                    // Mark as available for betting but not actually live
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
                    // Pinnacle marks this as live for betting, but no API-Football enhancement
                    $pinnacleMatch['betting_availability'] = 'available_for_betting';
                    $matchesAvailableForBetting++;

                    Log::debug('Pinnacle match available for betting (not actually live)', [
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'live_status_id' => $originalLiveStatusId,
                        'betting_availability' => 'available_for_betting'
                    ]);
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
                'total_filtered_pinnacle_matches' => count($filteredPinnacleMatches),
                'matches_enhanced_with_live_data' => $matchesWithLiveData,
                'finished_matches_filtered_out' => $finishedMatchesFiltered
            ]);

            return $mergedMatches;

            Log::info('Fetched live fixtures from API-Football', [
                'live_fixtures_count' => count($liveFixtures)
            ]);

            if (empty($liveFixtures)) {
                Log::info('No live fixtures from API-Football, returning original Pinnacle matches');
                return $pinnacleMatches;
            }

            // Create lookup map for API-Football fixtures by normalized team names
            $footballLookup = [];
            foreach ($liveFixtures as $fixture) {
                $homeTeam = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                $awayTeam = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');
                $key = $homeTeam . '|' . $awayTeam;
                $footballLookup[$key] = $fixture;
            }

            // Merge scores for each Pinnacle match
            $mergedMatches = [];
            $matchesWithScores = 0;

            foreach ($pinnacleMatches as $pinnacleMatch) {
                $homeTeam = $this->normalizeTeamName($pinnacleMatch['home'] ?? '');
                $awayTeam = $this->normalizeTeamName($pinnacleMatch['away'] ?? '');
                $lookupKey = $homeTeam . '|' . $awayTeam;

                // Try to find matching fixture in API-Football data
                $footballFixture = $footballLookup[$lookupKey] ?? null;

                if ($footballFixture) {
                    // Merge live scores and duration from API-Football
                    $pinnacleMatch['home_score'] = $footballFixture['goals']['home'] ?? 0;
                    $pinnacleMatch['away_score'] = $footballFixture['goals']['away'] ?? 0;

                    // Add match duration (clock/period from API-Football)
                    $status = $footballFixture['fixture']['status']['long'] ?? '';
                    $elapsed = $footballFixture['fixture']['status']['elapsed'] ?? null;

                    if ($elapsed) {
                        $pinnacleMatch['clock'] = $elapsed . "' " . $status;
                        $pinnacleMatch['period'] = $status;
                    }

                    $matchesWithScores++;

                    Log::debug('Merged live scores for match', [
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away'],
                        'home_score' => $pinnacleMatch['home_score'],
                        'away_score' => $pinnacleMatch['away_score'],
                        'duration' => $pinnacleMatch['clock'] ?? null
                    ]);
                } else {
                    // No matching fixture found, keep original Pinnacle data (scores = 0)
                    Log::debug('No API-Football match found for', [
                        'home_team' => $pinnacleMatch['home'],
                        'away_team' => $pinnacleMatch['away']
                    ]);
                }

                $mergedMatches[] = $pinnacleMatch;
            }

            Log::info('Completed live score merging', [
                'total_pinnacle_matches' => count($pinnacleMatches),
                'matches_with_scores' => $matchesWithScores
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
     */
    private function isBettingMarket($pinnacleMatch)
    {
        $homeTeam = $pinnacleMatch['home'] ?? '';
        $awayTeam = $pinnacleMatch['away'] ?? '';
        $leagueName = $pinnacleMatch['league_name'] ?? '';

        // Check for parentheses in team names (indicates betting markets)
        if (preg_match('/\([^)]*\)/', $homeTeam) || preg_match('/\([^)]*\)/', $awayTeam)) {
            return true;
        }

        // Check for betting market keywords in league name
        if (preg_match('/corners|cards|bookings|offsides|throw.*ins/i', $leagueName)) {
            return true;
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
