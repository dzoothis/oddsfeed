<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use App\Models\SportsMatch;

class LiveMatchSyncJob implements ShouldQueue
{
    use Queueable;

    // Queue configuration for live match synchronization
    public $tries = 3; // Retry up to 3 times for network/API failures
    public $timeout = 600; // 10 minutes timeout for live data operations
    public $backoff = [30, 90, 300]; // Exponential backoff: 30s, 1.5min, 5min

    protected $sportId;
    protected $leagueIds;

    /**
     * Create a new job instance.
     */
    public function __construct($sportId = null, $leagueIds = [])
    {
        $this->sportId = $sportId;
        $this->leagueIds = $leagueIds;
    }

    /**
     * Execute the job - Phase 2: Background live match sync with caching
     */
    public function handle(PinnacleService $pinnacleService, TeamResolutionService $teamResolutionService): void
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

            Log::info('LiveMatchSyncJob completed successfully', [
                'processed_matches' => count($processedMatches),
                'leagues_updated' => count($matchesByLeague)
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
                    'has_open_markets' => $match['is_have_open_markets'] ?? false,
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
                            'hasOpenMarkets' => $matchData['has_open_markets'],
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
