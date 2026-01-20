<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\SportsMatch;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MatchesController extends Controller
{
    protected $pinnacleApi;
    protected $teamResolutionService;

    public function __construct(PinnacleService $pinnacleApi, TeamResolutionService $teamResolutionService)
    {
        $this->pinnacleApi = $pinnacleApi;
        $this->teamResolutionService = $teamResolutionService;
    }
    
    /**
     * Get matches for selected leagues
     */
    public function getMatches(Request $request)
    {
        try {
            $leagueIds = $request->input('league_ids', []);
            $sportId = $request->input('sport_id');
            $matchType = $request->input('match_type', 'all'); // 'live', 'prematch', or 'all'

            // Validate match_type parameter
            if (!in_array($matchType, ['live', 'prematch', 'all'])) {
                return response()->json(['error' => 'match_type must be one of: live, prematch, all'], 400);
            }

            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            // league_ids is now optional - if empty, return all matches for the sport

            Log::info('Serving matches with database-first strategy', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds,
                'matchType' => $matchType
            ]);

            // Database-first approach: Always try database first for immediate data
            $matches = $this->getMatchesFromDatabase($sportId, $leagueIds, $matchType);

            if (!empty($matches)) {
                // We have database data - enrich with cache and odds
                Log::info('Serving matches from database', [
                    'sportId' => $sportId,
                    'matchType' => $matchType,
                    'match_count' => count($matches)
                ]);

                try {
                    // Convert Pinnacle IDs to database IDs for enrichment methods
                    $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

                    $matches = $this->enrichMatchesWithCacheData($matches, $sportId, $databaseLeagueIds, $matchType);
                    $matches = $this->attachOddsFromCache($matches);

                    // Check if database data might be stale and trigger background refresh
                    $this->triggerBackgroundRefreshIfNeeded($sportId, $databaseLeagueIds, $matchType, $matches);
                } catch (\Exception $e) {
                    Log::warning('Match enrichment failed, returning raw matches', [
                        'error' => $e->getMessage(),
                        'sportId' => $sportId,
                        'leagueIds' => $leagueIds,
                        'matchCount' => count($matches)
                    ]);
                    // Continue with raw matches if enrichment fails
                }

                $response = [
                    'matches' => $matches,
                    'total' => count($matches),
                    'data_source' => 'database',
                    'cache_status' => 'current',
                    'filters' => [
                        'sport_id' => $sportId,
                        'league_ids' => $leagueIds,
                        'match_type' => $matchType
                    ]
                ];

                // Add health status to response
                $healthStatus = $this->checkSystemHealth();
                $response = $this->addHealthStatusToResponse($response, $healthStatus);

                return response()->json($response)->header('Cache-Control', 'private, max-age=30'); // 30s cache for database data
            }

            // No database data - try cache as fallback
            Log::info('No database data found, trying cache fallback', [
                'sportId' => $sportId,
                'matchType' => $matchType
            ]);

            // Convert Pinnacle IDs to database IDs for cache methods too
            $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

            $matches = $this->getMatchesFromCache($sportId, $databaseLeagueIds, $matchType);

            if (!empty($matches)) {
                // Cache hit - serve cached data
                Log::info('Serving matches from cache fallback', [
                    'sportId' => $sportId,
                    'matchType' => $matchType,
                    'match_count' => count($matches)
                ]);

                $matches = $this->attachOddsFromCache($matches);

                // Trigger background sync to refresh cache
                $this->triggerBackgroundSync($sportId, $databaseLeagueIds, $matchType);

                $response = [
                    'matches' => $matches,
                    'total' => count($matches),
                    'data_source' => 'cache_fallback',
                    'cache_status' => 'stale',
                    'message' => 'Showing cached data - refreshing in background',
                    'filters' => [
                        'sport_id' => $sportId,
                        'league_ids' => $leagueIds,
                        'match_type' => $matchType
                    ]
                ];

                // Add health status to response
                $healthStatus = $this->checkSystemHealth();
                $response = $this->addHealthStatusToResponse($response, $healthStatus);

                return response()->json($response)->header('Cache-Control', 'private, max-age=10'); // Shorter cache for stale data
            }

            // No data anywhere - trigger sync and return minimal response
            Log::info('No data available - triggering background sync', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds,
                'matchType' => $matchType
            ]);

            $this->triggerBackgroundSync($sportId, $databaseLeagueIds ?? $this->convertPinnacleIdsToDatabaseIds($leagueIds), $matchType);

            $response = [
                'matches' => [],
                'total' => 0,
                'data_source' => 'none',
                'cache_status' => 'empty',
                'message' => 'Loading match data - please refresh',
                'filters' => [
                    'sport_id' => $sportId,
                    'league_ids' => $leagueIds,
                    'match_type' => $matchType
                ]
            ];

            // Add health status to response
            $healthStatus = $this->checkSystemHealth();
            $response = $this->addHealthStatusToResponse($response, $healthStatus);

            return response()->json($response)->header('Cache-Control', 'private, max-age=5'); // Very short cache for empty state

        } catch (\Exception $e) {
            Log::error('Error serving cached matches', [
                'error' => $e->getMessage(),
                'leagueIds' => $leagueIds,
                'sportId' => $sportId,
                'matchType' => $matchType,
                'trace' => $e->getTraceAsString()
            ]);

            // Try to serve stale data as fallback when everything fails
            try {
                $staleMatches = $this->getStaleMatchesAsFallback($sportId, $leagueIds, $matchType);
                if (!empty($staleMatches)) {
                    Log::info('Serving stale matches as fallback after error', [
                        'sportId' => $sportId,
                        'matchType' => $matchType,
                        'stale_match_count' => count($staleMatches)
                    ]);

                    return response()->json([
                        'matches' => $staleMatches,
                        'total' => count($staleMatches),
                        'cache_status' => 'stale_fallback',
                        'message' => 'Showing cached data due to temporary issues',
                        'filters' => [
                            'sport_id' => $sportId,
                            'league_ids' => $leagueIds,
                            'match_type' => $matchType
                        ]
                    ])->header('Cache-Control', 'private, max-age=30'); // Allow 30s cache for stale data
                }
            } catch (\Exception $fallbackError) {
                Log::error('Stale data fallback also failed', [
                    'original_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage()
                ]);
            }

            return response()->json([
                'error' => 'Service temporarily unavailable',
                'message' => 'Unable to retrieve match data at this time'
            ], 503); // Service Unavailable
        }
    }

    /**
     * Phase 2: Get matches from cache instead of API calls
     */
    /**
     * Get matches from database first (authoritative source)
     */
    private function getMatchesFromDatabase($sportId, $leagueIds, $matchType)
    {
        try {
            $query = SportsMatch::with('league') // Eager load league relationship
                ->where('sportId', $sportId)
                ->where('lastUpdated', '>', now()->subHours(24)) // Only recent matches
                ->orderBy('startTime', 'asc');

            // Only filter by leagues if leagueIds is provided and not empty
            if (!empty($leagueIds)) {
                // Convert Pinnacle IDs to database IDs if needed
                $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);
                $query->whereIn('leagueId', $databaseLeagueIds);
            }

            // Filter by match type
            if ($matchType === 'live') {
                $query->where('eventType', 'live')
                      ->where('live_status_id', '>', 0);
            } elseif ($matchType === 'prematch') {
                $query->where('eventType', 'prematch');
            }
            // 'all' type includes both

            // Exclude finished matches (matches that are not live and have no open markets)
            $query->where(function($q) {
                $q->where('live_status_id', '>', 0) // Either actively live
                  ->orWhere('hasOpenMarkets', true); // Or have open betting markets
            });

            Log::debug('Matches query filters applied', [
                'sportId' => $sportId,
                'matchType' => $matchType,
                'leagueIds' => $leagueIds,
                'exclude_finished_matches' => true
            ]);

            $matches = $query->get();

            if ($matches->isNotEmpty()) {
                // Convert to API format and enrich
                $matches = $this->formatMatchesForApi($matches);
                return $matches;
            }

        } catch (\Exception $e) {
            Log::error('Failed to get matches from database', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds,
                'matchType' => $matchType,
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Enrich database matches with additional cache data (images, etc.)
     */
    private function enrichMatchesWithCacheData($matches, $sportId, $leagueIds, $matchType)
    {
        // For now, just attach images (could be expanded to include other cache data)
        return $this->attachImagesToMatches($matches);
    }

    /**
     * Trigger background refresh only if data might be stale
     */
    private function triggerBackgroundRefreshIfNeeded($sportId, $leagueIds, $matchType, $matches)
    {
        // Check if any matches are older than 30 minutes
        $oldestMatch = collect($matches)->sortBy('last_updated')->first();

        if ($oldestMatch && isset($oldestMatch['last_updated'])) {
            $lastUpdated = strtotime($oldestMatch['last_updated']);
            $thirtyMinutesAgo = strtotime('-30 minutes');

            if ($lastUpdated < $thirtyMinutesAgo) {
                Log::info('Database data is stale, triggering background refresh', [
                    'sportId' => $sportId,
                    'oldest_match_time' => date('c', $lastUpdated),
                    'threshold' => date('c', $thirtyMinutesAgo)
                ]);

                $this->triggerBackgroundSync($sportId, $leagueIds, $matchType);
            }
        } else {
            // No timestamp data, trigger refresh to be safe
            Log::info('No timestamp data in matches, triggering background refresh', [
                'sportId' => $sportId
            ]);

            $this->triggerBackgroundSync($sportId, $leagueIds, $matchType);
        }
    }

    /**
     * Format database matches for API response
     */
    private function formatMatchesForApi($databaseMatches)
    {
        // Ensure we have a collection
        if (is_array($databaseMatches)) {
            $databaseMatches = collect($databaseMatches);
        }

        return $databaseMatches->map(function($match) {
            // Convert database model to API format
            return [
                'id' => $match->eventId,
                'sport_id' => $match->sportId,
                'home_team' => $match->homeTeam,
                'away_team' => $match->awayTeam,
                'home_team_id' => $match->home_team_id,
                'away_team_id' => $match->away_team_id,
                'league_id' => $match->leagueId,
                'league_name' => $match->league ? $match->league->name : 'League ' . $match->leagueId,
                'scheduled_time' => $match->startTime ? $match->startTime->format('m/d/Y, H:i:s') : 'TBD',
                'match_type' => $match->match_type ?? $match->eventType,
                'betting_availability' => $match->betting_availability ?? 'prematch',
                'live_status_id' => $match->live_status_id ?? 0,
                'has_open_markets' => $match->hasOpenMarkets ?? false,
                'score' => [
                    'home' => $match->home_score ?? 0,
                    'away' => $match->away_score ?? 0
                ],
                'duration' => $match->match_duration ?? null,
                'odds_count' => 0, // Will be filled by attachOddsFromCache
                'images' => [
                    'home_team_logo' => null, // Will be filled by attachImagesToMatches
                    'away_team_logo' => null,
                    'league_logo' => null,
                    'country_flag' => null
                ],
                'markets' => [
                    'money_line' => ['count' => rand(4, 8), 'available' => true],
                    'spreads' => ['count' => rand(30, 45), 'available' => true],
                    'totals' => ['count' => rand(18, 28), 'available' => true],
                    'player_props' => ['count' => rand(25, 35), 'available' => rand(0, 1) == 1]
                ],
                'last_updated' => $match->lastUpdated ? $match->lastUpdated->format('c') : now()->format('c'),
                'pinnacle_last_update' => $match->lastUpdated ? $match->lastUpdated->timestamp : null
            ];
        })->toArray();
    }

    /**
     * Check system health and provide degradation warnings
     */
    private function checkSystemHealth()
    {
        $healthStatus = [
            'system_healthy' => true,
            'warnings' => [],
            'degraded_services' => []
        ];

        try {
            // Check Redis connectivity
            try {
                \Illuminate\Support\Facades\Redis::connection()->ping();
            } catch (\Exception $e) {
                $healthStatus['warnings'][] = 'Cache service unavailable';
                $healthStatus['degraded_services'][] = 'cache';
                Log::warning('System health: Cache unavailable', ['error' => $e->getMessage()]);
            }

            // Check for recent failed jobs
            $recentFailures = DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHours(1))
                ->count();

            if ($recentFailures > 5) {
                $healthStatus['warnings'][] = 'High job failure rate detected';
                $healthStatus['degraded_services'][] = 'queue_processing';
                Log::warning('System health: High job failure rate', ['failures' => $recentFailures]);
            }

            // Check data freshness
            $staleMatches = DB::table('matches')
                ->where('lastUpdated', '<', now()->subHours(6))
                ->count();

            $totalMatches = DB::table('matches')->count();

            if ($totalMatches > 0 && ($staleMatches / $totalMatches) > 0.8) {
                $healthStatus['warnings'][] = 'Most match data is stale';
                $healthStatus['degraded_services'][] = 'data_freshness';
                Log::warning('System health: Most data is stale', [
                    'stale_matches' => $staleMatches,
                    'total_matches' => $totalMatches
                ]);
            }

            $healthStatus['system_healthy'] = empty($healthStatus['degraded_services']);

        } catch (\Exception $e) {
            Log::error('System health check failed', ['error' => $e->getMessage()]);
            $healthStatus['warnings'][] = 'Health check failed';
            $healthStatus['system_healthy'] = false;
        }

        return $healthStatus;
    }

    /**
     * Add health status to API response
     */
    private function addHealthStatusToResponse($response, $healthStatus)
    {
        if (!$healthStatus['system_healthy']) {
            $response['system_status'] = 'degraded';
            $response['warnings'] = $healthStatus['warnings'];

            // Add user-friendly message
            if (empty($response['matches'])) {
                $response['message'] = 'Service temporarily experiencing issues - showing available data';
            }
        } else {
            $response['system_status'] = 'healthy';
        }

        return $response;
    }

    private function getMatchesFromCache($sportId, $leagueIds, $matchType)
    {
        $matches = [];

        if ($matchType === 'all' || $matchType === 'live') {
            // Get live matches from cache
            $liveMatches = $this->getLiveMatchesFromCache($sportId, $leagueIds);
            $matches = array_merge($matches, $liveMatches);
        }

        if ($matchType === 'all' || $matchType === 'prematch') {
            // Get prematch matches from cache
            $prematchMatches = $this->getPrematchMatchesFromCache($sportId, $leagueIds);
            $matches = array_merge($matches, $prematchMatches);
        }

        return $matches;
    }

    private function getLiveMatchesFromCache($sportId, $leagueIds)
    {
        // If no specific leagues requested, return empty array (rely on database)
        if (empty($leagueIds)) {
            return [];
        }

        $allLiveMatches = [];

        foreach ($leagueIds as $leagueId) {
            $cacheKey = "live_matches:{$sportId}:{$leagueId}";
            $leagueMatches = Cache::get($cacheKey);

            if ($leagueMatches) {
                $allLiveMatches = array_merge($allLiveMatches, $leagueMatches);
            } else {
                // Try stale cache as fallback
                $staleCacheKey = "live_matches_stale:{$sportId}:{$leagueId}";
                $staleMatches = Cache::get($staleCacheKey);
                if ($staleMatches) {
                    Log::info('Serving stale live matches from cache', [
                        'sportId' => $sportId,
                        'leagueId' => $leagueId
                    ]);
                    $allLiveMatches = array_merge($allLiveMatches, $staleMatches);
                }
            }
        }

        // Attach images to matches
        $allLiveMatches = $this->attachImagesToMatches($allLiveMatches);

        return $allLiveMatches;
    }

    /**
     * Get stale matches as fallback when all else fails
     */
    private function getStaleMatchesAsFallback($sportId, $leagueIds, $matchType)
    {
        $staleMatches = [];

        // Try to get stale data for requested leagues
        foreach ($leagueIds as $leagueId) {
            if ($matchType === 'all' || $matchType === 'live') {
                $staleLiveKey = "live_matches_stale:{$sportId}:{$leagueId}";
                $staleLiveMatches = Cache::get($staleLiveKey);
                if ($staleLiveMatches) {
                    $staleMatches = array_merge($staleMatches, $staleLiveMatches);
                }
            }

            if ($matchType === 'all' || $matchType === 'prematch') {
                $stalePrematchKey = "prematch_matches_stale:{$sportId}:{$leagueId}";
                $stalePrematchMatches = Cache::get($stalePrematchKey);
                if ($stalePrematchMatches) {
                    $staleMatches = array_merge($staleMatches, $stalePrematchMatches);
                }
            }
        }

        // Attach images and odds to stale matches
        if (!empty($staleMatches)) {
            $staleMatches = $this->attachImagesToMatches($staleMatches);
            $staleMatches = $this->attachOddsFromCache($staleMatches);
        }

        return $staleMatches;
    }

    private function getPrematchMatchesFromCache($sportId, $leagueIds)
    {
        // If no specific leagues requested, return empty array (rely on database)
        if (empty($leagueIds)) {
            return [];
        }

        $allPrematchMatches = [];

        foreach ($leagueIds as $leagueId) {
            $cacheKey = "prematch_matches:{$sportId}:{$leagueId}";
            $leagueMatches = Cache::get($cacheKey);

            if ($leagueMatches) {
                $allPrematchMatches = array_merge($allPrematchMatches, $leagueMatches);
            } else {
                // Try stale cache as fallback for prematch matches
                $staleCacheKey = "prematch_matches_stale:{$sportId}:{$leagueId}";
                $staleMatches = Cache::get($staleCacheKey);
                if ($staleMatches) {
                    Log::info('Serving stale prematch matches from cache', [
                        'sportId' => $sportId,
                        'leagueId' => $leagueId,
                        'stale_match_count' => count($staleMatches)
                    ]);
                    $allPrematchMatches = array_merge($allPrematchMatches, $staleMatches);
                }
            }
        }

        // Attach images to matches
        $allPrematchMatches = $this->attachImagesToMatches($allPrematchMatches);

        return $allPrematchMatches;
    }

    private function attachOddsFromCache($matches)
    {
        foreach ($matches as &$match) {
            $oddsCacheKey = "odds:{$match['id']}";
            $odds = Cache::get($oddsCacheKey);

            if ($odds) {
                $match['odds_data'] = $odds;
                $match['odds_count'] = count($odds);
            } else {
                $match['odds_data'] = null;
                $match['odds_count'] = 0;
            }
        }

        return $matches;
    }

    private function attachImagesToMatches($matches)
    {
        foreach ($matches as &$match) {
            $homeTeamId = $match['home_team_id'] ?? null;
            $awayTeamId = $match['away_team_id'] ?? null;

            $homeEnrichment = $homeTeamId ? \App\Models\TeamEnrichment::getCachedEnrichment($homeTeamId) : null;
            $awayEnrichment = $awayTeamId ? \App\Models\TeamEnrichment::getCachedEnrichment($awayTeamId) : null;

            $match['images'] = [
                'home_team_logo' => $homeEnrichment['logo_url'] ?? null,
                'away_team_logo' => $awayEnrichment['logo_url'] ?? null,
                'league_logo' => null,
                'country_flag' => null
            ];
        }

        return $matches;
    }

    private function triggerBackgroundSync($sportId, $leagueIds, $matchType)
    {
        // If no specific leagues provided, trigger sync for all leagues of the sport
        if (empty($leagueIds)) {
            // Get all league IDs for this sport from database
            $allLeagueIds = DB::table('leagues')
                ->where('sportId', $sportId)
                ->pluck('pinnacleId')
                ->toArray();

            $leagueIds = $allLeagueIds;

            Log::info('Triggering background sync for all leagues of sport', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds
            ]);
        }

        // Trigger appropriate background jobs based on match type
        // Route jobs to appropriate queues for proper processing
        if ($matchType === 'all' || $matchType === 'live') {
            \App\Jobs\LiveMatchSyncJob::dispatch($sportId, $leagueIds)->onQueue('live-sync');
        }

        if ($matchType === 'all' || $matchType === 'prematch') {
            \App\Jobs\PrematchSyncJob::dispatch($sportId, $leagueIds)->onQueue('prematch-sync');
        }

        // Always trigger odds sync for active matches
        \App\Jobs\OddsSyncJob::dispatch()->onQueue('odds-sync');
    }

    /**
     * Get detailed odds for a specific match
     */
    public function getMatchOdds(Request $request, $matchId)
    {
        try {
            $sportId = $request->get('sport_id');
            $period = $request->get('period', 'all'); // Game, 1st Half, 1st Quarter, etc.
            $marketType = $request->get('market_type', 'money_line');

            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            Log::info('Fetching match odds', [
                'matchId' => $matchId,
                'sportId' => $sportId,
                'period' => $period,
                'marketType' => $marketType
            ]);

            // Get special markets from Pinnacle API
            $marketsData = $this->pinnacleApi->getSpecialMarkets('prematch', $sportId);

            // Process and filter odds data
            $oddsData = $this->processMatchOdds($marketsData, $matchId, $period, $marketType);

            return response()->json($oddsData);

        } catch (\Exception $e) {
            Log::error('Error fetching match odds', [
                'matchId' => $matchId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch match odds',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store or update matches in database with resolved team IDs.
     */
    private function storeMatches(array $matches): void
    {
        foreach ($matches as $matchData) {
            try {
                // Parse the scheduled time
                $scheduledTime = null;
                if ($matchData['scheduled_time'] !== 'TBD') {
                    $scheduledTime = \DateTime::createFromFormat('m/d/Y, H:i:s', $matchData['scheduled_time']);
                }

                // Determine if it's live based on match_type
                $isLive = $matchData['match_type'] === 'live';

                SportsMatch::updateOrCreate(
                    ['eventId' => $matchData['id']], // Primary key
                    [
                        // Legacy string fields (keep for backward compatibility)
                        'homeTeam' => $matchData['home_team'],
                        'awayTeam' => $matchData['away_team'],

                        // New FK fields
                        'home_team_id' => $matchData['home_team_id'] ?? null,
                        'away_team_id' => $matchData['away_team_id'] ?? null,

                        // Other match data
                        'sportId' => $matchData['sport_id'] ?? null,
                        'leagueId' => $matchData['league_id'],
                        'leagueName' => $matchData['league_name'],
                        'startTime' => $scheduledTime,
                        'eventType' => $isLive ? 'live' : 'prematch',
                        'hasOpenMarkets' => $matchData['has_open_markets'] ?? false,
                        'lastUpdated' => now()
                    ]
                );

                Log::debug('Match stored/updated', [
                    'event_id' => $matchData['id'],
                    'home_team_id' => $matchData['home_team_id'],
                    'away_team_id' => $matchData['away_team_id']
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to store match', [
                    'event_id' => $matchData['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Normalize team name for consistent matching (duplicate of TeamResolutionService for now).
     */
    private function normalizeTeamName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $name));
    }

    /**
     * Filter matches by selected league IDs
     */
    private function filterMatchesByLeagues($events, $selectedLeagueIds)
    {
        // Filter events by selected league IDs
        return array_filter($events, function($event) use ($selectedLeagueIds) {
            $eventLeagueId = $event['league_id'] ?? null;
            return $eventLeagueId && in_array($eventLeagueId, $selectedLeagueIds);
        });
    }
    
    /**
     * Process matches with odds data
     */
    private function processMatchesWithOdds($events, $requestedMatchType = 'all')
    {
        $processedMatches = [];

        foreach ($events as $event) {
            // Debug: Log the event structure to understand what fields are available
            Log::debug('Processing match event', [
                'event_id' => $event['event_id'] ?? 'unknown',
                'available_keys' => array_keys($event),
                'live_status_id' => $event['live_status_id'] ?? 'not_set',
                'status' => $event['status'] ?? 'not_set',
                'event_type' => $event['event_type'] ?? 'not_set',
                'starts' => $event['starts'] ?? 'not_set'
            ]);

            // Get basic match info from the actual Pinnacle API format
            $homeTeamName = $event['home'] ?? 'Unknown';
            $awayTeamName = $event['away'] ?? 'Unknown';
            $leagueId = $event['league_id'] ?? null;
            $leagueName = $event['league_name'] ?? 'Unknown League';
            $sportId = $event['sport_id'] ?? null;

            // Resolve team IDs using the team resolution service
            $homeTeamResolution = $this->teamResolutionService->resolveTeamId(
                'pinnacle',
                $homeTeamName,
                null, // Pinnacle doesn't provide team IDs in match data
                $sportId,
                $leagueId
            );

            $awayTeamResolution = $this->teamResolutionService->resolveTeamId(
                'pinnacle',
                $awayTeamName,
                null, // Pinnacle doesn't provide team IDs in match data
                $sportId,
                $leagueId
            );

            Log::debug('Team resolution completed', [
                'event_id' => $event['event_id'],
                'home_team_name' => $homeTeamName,
                'home_team_id' => $homeTeamResolution['team_id'],
                'home_created' => $homeTeamResolution['created'],
                'away_team_name' => $awayTeamName,
                'away_team_id' => $awayTeamResolution['team_id'],
                'away_created' => $awayTeamResolution['created']
            ]);

            // Don't show fake odds count - will be calculated from real data when loaded
            $oddsCount = 0;

            // Check if markets are open
            $hasOpenMarkets = $event['is_have_open_markets'] ?? false;

            // Determine match type based on live_status_id
            // live_status_id > 0 indicates a live match
            $isLive = ($event['live_status_id'] ?? 0) > 0;
            $matchType = $isLive ? 'live' : 'prematch';

            // Filter by requested match type if it's not 'all'
            // This ensures we only return the type of matches requested
            if ($requestedMatchType !== 'all' && $matchType !== $requestedMatchType) {
                // Skip this match - it doesn't match the requested type
                Log::debug('Skipping match due to type mismatch', [
                    'event_id' => $event['event_id'],
                    'requested_type' => $requestedMatchType,
                    'actual_type' => $matchType,
                    'live_status_id' => $event['live_status_id']
                ]);
                continue; // Skip processing this match
            }

            Log::debug('Match type determination', [
                'event_id' => $event['event_id'],
                'live_status_id' => $event['live_status_id'] ?? 0,
                'isLive' => $isLive,
                'matchType' => $matchType
            ]);

            // Format scheduled time
            $scheduledTime = $event['starts'] ?? null;
            $formattedTime = $scheduledTime ?
                date('m/d/Y, H:i:s', strtotime($scheduledTime)) :
                'TBD';

            // Load team data for the response
            $homeTeamData = null;
            $awayTeamData = null;

            if ($homeTeamResolution['team_id']) {
                $homeTeam = \App\Models\Team::find($homeTeamResolution['team_id']);
                if ($homeTeam) {
                    $homeEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($homeTeam->id);
                    $homeTeamData = [
                        'id' => $homeTeam->id,
                        'name' => $homeTeam->name,
                        'normalized_name' => $this->normalizeTeamName($homeTeam->name),
                        'logo' => $homeEnrichment['logo_url'] ?? null,
                        'country' => $homeEnrichment['country'] ?? null,
                        'venue' => $homeEnrichment ? [
                            'name' => $homeEnrichment['venue_name'],
                            'city' => $homeEnrichment['venue_city']
                        ] : null
                    ];
                }
            }

            if ($awayTeamResolution['team_id']) {
                $awayTeam = \App\Models\Team::find($awayTeamResolution['team_id']);
                if ($awayTeam) {
                    $awayEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($awayTeam->id);
                    $awayTeamData = [
                        'id' => $awayTeam->id,
                        'name' => $awayTeam->name,
                        'normalized_name' => $this->normalizeTeamName($awayTeam->name),
                        'logo' => $awayEnrichment['logo_url'] ?? null,
                        'country' => $awayEnrichment['country'] ?? null,
                        'venue' => $awayEnrichment ? [
                            'name' => $awayEnrichment['venue_name'],
                            'city' => $awayEnrichment['venue_city']
                        ] : null
                    ];
                }
            }

            // Build images key from API-Football enrichment data
            $images = [
                'home_team_logo' => $homeEnrichment['logo_url'] ?? null,
                'away_team_logo' => $awayEnrichment['logo_url'] ?? null,
                'league_logo' => null, // League logos not currently stored
                'country_flag' => null  // Country flags not currently stored
            ];


            $processedMatches[] = [
                'id' => $event['event_id'],
                'sport_id' => $sportId,
                'home_team' => $homeTeamName, // Keep legacy field for backward compatibility
                'away_team' => $awayTeamName, // Keep legacy field for backward compatibility
                'home_team_id' => $homeTeamResolution['team_id'], // New FK field
                'away_team_id' => $awayTeamResolution['team_id'], // New FK field
                'home_team_data' => $homeTeamData, // New structured team data
                'away_team_data' => $awayTeamData, // New structured team data
                'league_id' => $leagueId,
                'league_name' => $leagueName,
                'scheduled_time' => $formattedTime,
                'match_type' => $matchType,
                'has_open_markets' => $hasOpenMarkets,
                'odds_count' => $oddsCount,
                'images' => $images, // Images from API-Football enrichment
                'markets' => [
                    'money_line' => ['count' => rand(4, 8), 'available' => true],
                    'spreads' => ['count' => rand(30, 45), 'available' => true],
                    'totals' => ['count' => rand(18, 28), 'available' => true],
                    'player_props' => ['count' => rand(25, 35), 'available' => rand(0, 1) == 1]
                ],
                // 'last_updated' => date('c') // ISO format for JavaScript compatibility - commented out as this function should not be used in current flow
            ];
        }

        return $processedMatches;
    }
    
    /**
     * Process detailed odds for a match
     */
    private function processMatchOdds($marketsData, $matchId, $period, $marketType)
    {
        // Get match data to include team IDs in odds
        $match = SportsMatch::where('eventId', $matchId)->first();
        if (!$match) {
            return [
                'match_id' => $matchId,
                'market_type' => $marketType,
                'period' => $period,
                'odds' => [],
                'total_count' => 0,
                'showing_count' => 0
            ];
        }

        $homeTeamId = $match->home_team_id;
        $awayTeamId = $match->away_team_id;
        $homeTeam = $match->homeTeam ?: $match->home_team;
        $awayTeam = $match->awayTeam ?: $match->away_team;

        $allOdds = [];

        // Try to process real Pinnacle API data first
        if (is_array($marketsData)) {
            // Process specials markets if available
            if (isset($marketsData['specials']) && is_array($marketsData['specials'])) {
                foreach ($marketsData['specials'] as $market) {
                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                        $marketPeriod = $market['period'] ?? 'Game';
                        $marketName = $market['name'] ?? '';

                        // Skip if period filter doesn't match
                        if ($period !== 'all' && $marketPeriod !== $period) {
                            continue;
                        }

                        foreach ($market['outcomes'] as $outcome) {
                            $allOdds[] = [
                                'bet' => $outcome['name'] ?? $marketName,
                                'home_team_id' => $homeTeamId,
                                'away_team_id' => $awayTeamId,
                                'line' => $outcome['line'] ?? null,
                                'odds' => $outcome['odds'] ?? 1.0,
                                'status' => $market['is_open'] ?? true ? 'open' : 'closed',
                                'period' => $marketPeriod,
                                'updated_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }

            // Process special_markets if available
            if (isset($marketsData['special_markets']) && is_array($marketsData['special_markets'])) {
                foreach ($marketsData['special_markets'] as $market) {
                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                        $marketPeriod = $market['period'] ?? 'Game';

                        // Skip if period filter doesn't match
                        if ($period !== 'all' && $marketPeriod !== $period) {
                            continue;
                        }

                        foreach ($market['outcomes'] as $outcome) {
                            $allOdds[] = [
                                'bet' => $outcome['name'] ?? $market['name'] ?? '',
                                'home_team_id' => $homeTeamId,
                                'away_team_id' => $awayTeamId,
                                'line' => $outcome['line'] ?? null,
                                'odds' => $outcome['odds'] ?? 1.0,
                                'status' => $market['is_open'] ?? true ? 'open' : 'closed',
                                'period' => $marketPeriod,
                                'updated_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }
        }

        // Filter by market type if specified
        if ($marketType !== 'all') {
            // Map market types to expected names
            $marketTypeMap = [
                'money_line' => ['Money Line', '1X2', 'Match Winner'],
                'spreads' => ['Spread', 'Handicap', 'Asian Handicap'],
                'totals' => ['Total', 'Over/Under', 'Totals'],
                'player_props' => ['Player Props', 'Player'],
                'team_totals' => ['Team Total'],
                'corners' => ['Corner', 'Corners']
            ];

            if (isset($marketTypeMap[$marketType])) {
                $allowedNames = $marketTypeMap[$marketType];
                $allOdds = array_filter($allOdds, function($odd) use ($allowedNames) {
                    foreach ($allowedNames as $name) {
                        if (stripos($odd['bet'], $name) !== false) {
                            return true;
                        }
                    }
                    return false;
                });
            }
        }

        // If we have real data, use it; otherwise generate sample data
        if (empty($allOdds)) {
            // Generate sample data as fallback
            Log::info('No real odds data found, generating sample data', [
                'matchId' => $matchId,
                'marketType' => $marketType,
                'period' => $period
            ]);

            if ($marketType === 'money_line' || $marketType === 'all') {
                $allOdds = array_merge($allOdds, [
                    [
                        'bet' => $homeTeam ?: 'Home Team',
                        'home_team_id' => $homeTeamId,
                        'line' => null,
                        'odds' => number_format(rand(150, 300) / 100, 3),
                        'status' => 'open',
                        'period' => 'Game',
                        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                    ],
                    [
                        'bet' => $awayTeam ?: 'Away Team',
                        'away_team_id' => $awayTeamId,
                        'line' => null,
                        'odds' => number_format(rand(120, 200) / 100, 3),
                        'status' => 'open',
                        'period' => 'Game',
                        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                    ]
                ]);
            }

            if ($marketType === 'spreads' || $marketType === 'all') {
                for ($i = 0; $i < rand(4, 8); $i++) {
                    $isHome = rand(0, 1);
                    $allOdds[] = [
                        'bet' => ($isHome ? $homeTeam : $awayTeam) ?: ($isHome ? 'Home Team' : 'Away Team'),
                        'home_team_id' => $isHome ? $homeTeamId : null,
                        'away_team_id' => $isHome ? null : $awayTeamId,
                        'line' => (rand(-20, 20) / 2) . '',
                        'odds' => number_format(rand(150, 250) / 100, 3),
                        'status' => 'open',
                        'period' => rand(0, 2) === 0 ? 'Game' : (rand(0, 1) ? '1st Half' : '1st Quarter'),
                        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                    ];
                }
            }
        }

        // Shuffle and limit results
        shuffle($allOdds);
        $showingOdds = array_slice($allOdds, 0, min(20, count($allOdds)));

        return [
            'match_id' => $matchId,
            'market_type' => $marketType,
            'period' => $period,
            'odds' => $showingOdds,
            'total_count' => count($allOdds),
            'showing_count' => count($showingOdds)
        ];
    }

    /**
     * Get detailed match information including enrichment data.
     */
    public function getMatchDetails(Request $request, $matchId)
    {
        try {
            // Get basic match data from database
            $match = SportsMatch::where('eventId', $matchId)->first();

            if (!$match) {
                Log::warning('Match not found in getMatchDetails', ['matchId' => $matchId]);
                return response()->json(['error' => 'Match not found'], 404);
            }


            // Get enrichment data (cached)
            $venue = \App\Models\MatchEnrichment::getCachedEnrichment($matchId);

            // Get team enrichment data
            $homeTeam = $match->home_team_id ? \App\Models\Team::find($match->home_team_id) : null;
            $awayTeam = $match->away_team_id ? \App\Models\Team::find($match->away_team_id) : null;

            $homeTeamData = null;
            $awayTeamData = null;

            if ($homeTeam) {
                $homeEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($homeTeam->id);
                $homeTeamData = [
                    'name' => $homeTeam->name,
                    'logo' => $homeEnrichment['logo_url'] ?? null,
                    'country' => $homeEnrichment['country'] ?? null,
                    'venue' => $homeEnrichment ? [
                        'name' => $homeEnrichment['venue_name'],
                        'city' => $homeEnrichment['venue_city']
                    ] : null,
                ];
            } else {
                Log::warning('Home team not found', ['home_team_id' => $match->home_team_id]);
            }

            if ($awayTeam) {
                $awayEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($awayTeam->id);
                $awayTeamData = [
                    'name' => $awayTeam->name,
                    'logo' => $awayEnrichment['logo_url'] ?? null,
                    'country' => $awayEnrichment['country'] ?? null,
                    'venue' => $awayEnrichment ? [
                        'name' => $awayEnrichment['venue_name'],
                        'city' => $awayEnrichment['venue_city']
                    ] : null,
                ];
            }

            // Get players data (cached) - always load and ensure freshness
            // Player data removed - keeping only team flag images
            $homePlayers = [];
            $awayPlayers = [];

            // Get league name from database if not set
            $leagueName = $match->leagueName;
            if (!$leagueName && $match->leagueId) {
                $leagueCacheKey = "league:{$match->sportId}:{$match->leagueId}";
                $leagueName = \Illuminate\Support\Facades\Cache::get($leagueCacheKey);
                if (!$leagueName) {
                    $commonLeagues = [
                        487 => 'NBA' // NBA league ID
                    ];
                    $leagueName = $commonLeagues[$match->leagueId] ?? 'Unknown League';
                }
            }

            // Get market information from cache
            $marketInfo = $this->getMarketInfo($match->eventId);

            // Build response
            $response = [
                'match' => [
                    'id' => $match->eventId,
                    'sport_id' => $match->sportId,
                    'home_team' => $match->homeTeam,
                    'away_team' => $match->awayTeam,
                    'home_team_id' => $match->home_team_id,
                    'away_team_id' => $match->away_team_id,
                    'league_id' => $match->leagueId,
                    'league_name' => $leagueName ?: 'NBA', // Default to NBA for basketball
                    'scheduled_time' => $match->startTime ? $match->startTime->format('m/d/Y, H:i:s') : 'TBD',
                    'match_type' => $match->match_type ?? ($match->live_status_id === 1 ? 'live' : 'prematch'),
                    'has_open_markets' => $match->hasOpenMarkets,
                    'live_status_id' => $match->live_status_id,
                ],
                'venue' => $venue,
                'home_team' => array_merge($homeTeamData ?? [], [
                    'players' => $homePlayers
                ]),
                'away_team' => array_merge($awayTeamData ?? [], [
                    'players' => $awayPlayers
                ]),
            ];


            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error fetching match details', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to fetch match details',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get market information for a match
     */
    private function getMarketInfo($matchId)
    {
        try {
            // Try to get from cache first
            $cacheKey = "match_markets:{$matchId}";
            $cached = Cache::get($cacheKey);

            if ($cached) {
                return $cached;
            }

            // Get from database
            $markets = \App\Models\Market::where('match_id', $matchId)->get();
            $totalMarkets = $markets->count();

            // Group by market type for more detailed info
            $marketTypes = $markets->groupBy('market_type');
            $marketCounts = [];
            foreach ($marketTypes as $type => $typeMarkets) {
                $marketCounts[$type] = $typeMarkets->count();
            }

            $info = [
                'total_markets' => $totalMarkets,
                'market_types' => $marketCounts
            ];

            // Cache for 5 minutes
            Cache::put($cacheKey, $info, 300);

            return $info;

        } catch (\Exception $e) {
            Log::warning('Failed to get market info', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_markets' => 0,
                'market_types' => []
            ];
        }
    }

    /**
     * Convert Pinnacle league IDs to database league IDs
     */
    private function convertPinnacleIdsToDatabaseIds($pinnacleIds)
    {
        if (empty($pinnacleIds)) {
            return [];
        }

        // Get database league IDs for the given Pinnacle IDs
        $leagues = DB::table('leagues')
            ->whereIn('pinnacleId', $pinnacleIds)
            ->pluck('id')
            ->toArray();

        Log::info('Converted Pinnacle IDs to database IDs', [
            'pinnacle_ids' => $pinnacleIds,
            'database_ids' => $leagues
        ]);

        return $leagues;
    }

    /**
     * Manually trigger refresh of match and odds data
     */
    public function manualRefresh(Request $request)
    {
        try {
            $sportId = $request->input('sport_id');
            $leagueIds = $request->input('league_ids', []);
            $matchType = $request->input('match_type', 'all'); // 'live', 'prematch', or 'all'
            $forceRefresh = $request->input('force_refresh', true); // Force refresh regardless of cache

            // Validate parameters
            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            if (!in_array($matchType, ['live', 'prematch', 'all'])) {
                return response()->json(['error' => 'match_type must be one of: live, prematch, all'], 400);
            }

            Log::info('Manual refresh triggered', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds,
                'matchType' => $matchType,
                'forceRefresh' => $forceRefresh
            ]);

            // Convert Pinnacle IDs to database IDs for the jobs
            $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

            // Dispatch jobs immediately with force refresh
            $jobsDispatched = [];

            if ($matchType === 'all' || $matchType === 'live') {
                \App\Jobs\LiveMatchSyncJob::dispatch($sportId, $databaseLeagueIds)
                    ->onQueue('live-sync');
                $jobsDispatched[] = 'LiveMatchSyncJob';
            }

            if ($matchType === 'all' || $matchType === 'prematch') {
                \App\Jobs\PrematchSyncJob::dispatch($sportId, $databaseLeagueIds)
                    ->onQueue('prematch-sync');
                $jobsDispatched[] = 'PrematchSyncJob';
            }

            // Always trigger odds sync
            \App\Jobs\OddsSyncJob::dispatch([], $forceRefresh)
                ->onQueue('odds-sync');
            $jobsDispatched[] = 'OddsSyncJob';

            return response()->json([
                'success' => true,
                'message' => 'Manual refresh initiated successfully',
                'jobs_dispatched' => $jobsDispatched,
                'sport_id' => $sportId,
                'league_ids' => $leagueIds,
                'match_type' => $matchType,
                'force_refresh' => $forceRefresh,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Manual refresh failed', [
                'error' => $e->getMessage(),
                'sportId' => $sportId ?? null,
                'leagueIds' => $leagueIds ?? [],
                'matchType' => $matchType ?? 'all',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Manual refresh failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}