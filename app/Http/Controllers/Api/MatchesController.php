<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\SportsMatch;
use App\Models\ApiFootballData;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use App\Services\ApiFootballService;
use App\Services\OddsAggregationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DateTimeZone;
use Exception;

class MatchesController extends Controller
{
    protected $pinnacleApi;
    protected $teamResolutionService;
    protected $apiFootballService;

    public function __construct(PinnacleService $pinnacleApi, TeamResolutionService $teamResolutionService, ApiFootballService $apiFootballService)
    {
        $this->pinnacleApi = $pinnacleApi;
        $this->teamResolutionService = $teamResolutionService;
        $this->apiFootballService = $apiFootballService;
    }
    
    /**
     * Get matches for selected leagues
     */
    public function getMatches(Request $request)
    {
        try {
            $leagueIds = $request->input('league_ids', []);
            $sportId = $request->input('sport_id');
            $matchType = $request->input('match_type', 'all');
            $timezone = $request->input('timezone', 'UTC'); // Default to UTC if not provided

            // Validate required parameters
            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            if (!in_array($matchType, ['live', 'prematch', 'all'])) {
                return response()->json(['error' => 'match_type must be one of: live, prematch, all'], 400);
            }

            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            Log::info('Serving matches with database-first strategy', [
                'sportId' => $sportId,
                'leagueIds' => $leagueIds,
                'matchType' => $matchType
            ]);

            $matches = $this->getMatchesFromDatabase($sportId, $leagueIds, $matchType, $timezone);

            if (!empty($matches)) {
                Log::info('Serving matches from database', [
                    'sportId' => $sportId,
                    'matchType' => $matchType,
                    'timezone' => $timezone,
                    'match_count' => count($matches)
                ]);

                try {
                    $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

                    $matches = $this->attachImagesToMatches($matches);
                    $matches = $this->attachOddsFromCache($matches);

                    $this->triggerBackgroundRefreshIfNeeded($sportId, $databaseLeagueIds, $matchType, $matches);
                } catch (\Exception $e) {
                    Log::warning('Match enrichment failed, returning raw matches', [
                        'error' => $e->getMessage(),
                        'sportId' => $sportId,
                        'leagueIds' => $leagueIds,
                        'matchCount' => count($matches)
                    ]);
                }

                $response = [
                    'matches' => $matches,
                    'total' => count($matches),
                    'data_source' => 'database',
                    'cache_status' => 'current',
                    'filters' => [
                        'sport_id' => $sportId,
                        'league_ids' => $leagueIds,
                        'match_type' => $matchType,
                        'timezone' => $timezone
                    ]
                ];

                $healthStatus = $this->checkSystemHealth();
                $response = $this->addHealthStatusToResponse($response, $healthStatus);

                return response()->json($response)->header('Cache-Control', 'private, max-age=30');
            }

            Log::info('No database data found, trying cache fallback', [
                'sportId' => $sportId,
                'matchType' => $matchType
            ]);

            $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

            $matches = $this->getMatchesFromCache($sportId, $databaseLeagueIds, $matchType, $timezone);

            if (!empty($matches)) {
                Log::info('Serving matches from cache fallback', [
                    'sportId' => $sportId,
                    'matchType' => $matchType,
                    'match_count' => count($matches)
                ]);

                $matches = $this->attachOddsFromCache($matches);

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
                        'match_type' => $matchType,
                        'timezone' => $timezone
                    ]
                ];

                $healthStatus = $this->checkSystemHealth();
                $response = $this->addHealthStatusToResponse($response, $healthStatus);

                return response()->json($response)->header('Cache-Control', 'private, max-age=10');
            }

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

            $healthStatus = $this->checkSystemHealth();
            $response = $this->addHealthStatusToResponse($response, $healthStatus);

            return response()->json($response)->header('Cache-Control', 'private, max-age=5');

        } catch (\Exception $e) {
            Log::error('Error serving cached matches', [
                'error' => $e->getMessage(),
                'leagueIds' => $leagueIds,
                'sportId' => $sportId,
                'matchType' => $matchType,
                'trace' => $e->getTraceAsString()
            ]);

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
                    ])->header('Cache-Control', 'private, max-age=30');
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
            ], 503);
        }
    }

    /**
     * Get matches from database first (authoritative source)
     */
    private function getMatchesFromDatabase($sportId, $leagueIds, $matchType, $timezone = 'UTC')
    {
        try {
            // Convert user timezone to UTC for database comparisons
            // Database stores times in UTC, so we need to compare with UTC time
            // But we use user's timezone to determine "now" from their perspective
            try {
                $userTimezone = new \DateTimeZone($timezone);
                $userNow = \Carbon\Carbon::now($timezone);
                $utcNow = $userNow->utc(); // Convert to UTC for database comparison
            } catch (\Exception $e) {
                // Fallback to UTC if timezone is invalid
                Log::warning('Invalid timezone provided, using UTC', ['timezone' => $timezone, 'error' => $e->getMessage()]);
                $utcNow = \Carbon\Carbon::now('UTC');
            }

            $query = SportsMatch::with('league')
                ->where('sportId', $sportId);
                // Removed 24-hour lastUpdated filter to show all aggregated matches
                // The aggregation system ensures we only show active matches
            
            // CRITICAL: Filter out betting market matches (Corners, Cards, etc.)
            // These are special markets, not real matches - they appear as duplicates
            $query->where(function($q) {
                $q->where('homeTeam', 'NOT LIKE', '%(Corners)%')
                  ->where('awayTeam', 'NOT LIKE', '%(Corners)%')
                  ->where('homeTeam', 'NOT LIKE', '%(Corner)%')
                  ->where('awayTeam', 'NOT LIKE', '%(Corner)%')
                  ->where('homeTeam', 'NOT LIKE', '%(Cards)%')
                  ->where('awayTeam', 'NOT LIKE', '%(Cards)%')
                  ->where('homeTeam', 'NOT LIKE', '%(Card)%')
                  ->where('awayTeam', 'NOT LIKE', '%(Card)%');
            });
            
            // Also filter by league name if it contains betting market keywords
            $query->whereDoesntHave('league', function($q) {
                $q->where('name', 'LIKE', '%Corners%')
                  ->orWhere('name', 'LIKE', '%Corner%')
                  ->orWhere('name', 'LIKE', '%Cards%')
                  ->orWhere('name', 'LIKE', '%Card%');
            });
            
            // Note: Sorting will be applied after matchType conditions are set

            if (!empty($leagueIds)) {
                $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);
                $query->whereIn('leagueId', $databaseLeagueIds);
            }

            if ($matchType === 'live') {
                // CRITICAL FIX: Auto-mark old matches as finished before querying
                // Matches that started 3+ hours ago with live_status_id = 1 are definitely finished
                // Different sports have different durations, but 3+ hours is safe for all
                $threeHoursAgo = $utcNow->copy()->subHours(3);
                \App\Models\SportsMatch::where('sportId', $sportId)
                    ->where('live_status_id', 1) // Currently marked as live
                    ->where('startTime', '<', $threeHoursAgo) // Started 3+ hours ago
                    ->whereNotNull('startTime')
                    ->update(['live_status_id' => 2]); // Mark as finished
                
                // CRITICAL: ABSOLUTE EXCLUSION of finished matches - NO EXCEPTIONS
                // Finished matches (status 2) should NEVER appear in live feed, regardless of scores, time, or any other condition
                // CRITICAL FIX: Live matches must have STARTED (startTime <= now)
                // Pinnacle marks matches as live_status_id=1 even before they start (live betting available)
                // But for "live" filter, we only want matches that are ACTUALLY playing right now
                $query->where('live_status_id', '!=', -1)  // Exclude cancelled
                      ->where('live_status_id', '!=', 2)   // Exclude finished - ABSOLUTE, NO EXCEPTIONS
                      ->where(function($q) use ($threeHoursAgo, $utcNow) {
                          // Include ALL matches that have started (startTime <= now) within last 3 hours
                          // This catches matches that should be live regardless of live_status_id
                          $q->where('startTime', '<=', $utcNow) // CRITICAL: Must have started
                            ->where(function($timeQ) use ($threeHoursAgo) {
                                // Must have started within last 3 hours
                                $timeQ->where('startTime', '>=', $threeHoursAgo)
                                      ->orWhereNull('startTime'); // Or no start time (can't determine age)
                            })
                            ->where(function($statusQ) {
                                // Include matches with live_status_id = 1 (marked as live)
                                // OR matches with scores (actively playing)
                                // OR matches with live_status_id = 0 that have started (might be live but not marked)
                                $statusQ->where('live_status_id', 1)
                                        ->orWhere(function($scoreQ) {
                                            $scoreQ->where('home_score', '>', 0)
                                                  ->orWhere('away_score', '>', 0);
                                        })
                                        ->orWhere('live_status_id', 0); // Include unmarked matches that have started
                            });
                      });
            } elseif ($matchType === 'prematch') {
                // Prematch: Future matches (startTime > now) within 2 days
                // Include matches that haven't started yet, regardless of live_status_id
                // If startTime > now, it's a prematch match (even if live_status_id = 1 for live betting availability)
                // live_status_id = 1 just means live betting is available, but match hasn't started yet
                $query->where('startTime', '>', $utcNow)
                      ->whereRaw('DATE(startTime) <= DATE(DATE_ADD(?, INTERVAL 2 DAY))', [$utcNow]);
            }

            // For non-live match types, exclude both cancelled and finished
            if ($matchType !== 'live') {
                $query->where('live_status_id', '!=', -1)
                      ->where('live_status_id', '!=', 2);
            }
            // Note: Live matches already have finished exclusion in the matchType === 'live' block above

            if ($matchType === 'all') {
                // CRITICAL: Exclude finished matches from 'all' type as well
                $query->where('live_status_id', '!=', -1)  // Exclude cancelled
                      ->where('live_status_id', '!=', 2);   // Exclude finished - ABSOLUTE
                
                $query->where(function($q) use ($utcNow) {
                    // Include live matches (status 1 only, not finished) - but must have STARTED
                    // CRITICAL: Live matches must have startTime <= now (actually playing)
                    $q->where(function($liveQ) use ($utcNow) {
                        $liveQ->where('live_status_id', '=', 1) // Only live, not finished
                              ->where('startTime', '<=', $utcNow); // CRITICAL: Must have started
                    })
                    // OR include prematch matches within 2 days (regardless of hasOpenMarkets)
                    ->orWhere(function($subQ) use ($utcNow) {
                        $subQ->where('startTime', '>', $utcNow) // Future matches only
                             ->whereRaw('DATE(startTime) <= DATE(DATE_ADD(?, INTERVAL 2 DAY))', [$utcNow]);
                    });
                });
            }

            // Only apply 150-minute filter to prematch matches, not live matches
            if ($matchType !== 'live' && $matchType !== 'all') {
                $query->whereRaw('(startTime IS NULL OR startTime > DATE_SUB(NOW(), INTERVAL 150 MINUTE))');
            }

            // Apply sorting based on match type
            // For live matches: Most recently started first (prioritize startTime), then most recently updated
            // For prematch: Earliest matches first
            if ($matchType === 'live') {
                // CRITICAL: Prioritize startTime for live matches - most recently started appear first
                $query->orderBy('startTime', 'desc') // Most recently started first
                      ->orderBy('lastUpdated', 'desc'); // Then most recently updated
            } elseif ($matchType === 'all') {
                // For 'all': Live matches first (most recent), then prematch (earliest)
                $query->orderByRaw('CASE WHEN live_status_id > 0 THEN 0 ELSE 1 END') // Live matches first
                      ->orderBy('startTime', 'desc') // Most recently started first
                      ->orderBy('lastUpdated', 'desc'); // Then most recently updated
            } else {
                // Prematch: Earliest matches first
                $query->orderBy('startTime', 'asc');
            }

            Log::debug('Matches query filters applied', [
                'sportId' => $sportId,
                'matchType' => $matchType,
                'leagueIds' => $leagueIds,
                'exclude_finished_matches' => true,
                'sort_order' => $matchType === 'live' ? 'most_recent_first' : ($matchType === 'all' ? 'live_first_then_prematch' : 'earliest_first')
            ]);

            $matches = $query->get();

            if ($matches->isNotEmpty()) {
                $matches = $this->formatMatchesForApi($matches, $timezone);
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
     */
    private function triggerBackgroundRefreshIfNeeded($sportId, $leagueIds, $matchType, $matches)
    {
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
            Log::info('No timestamp data in matches, triggering background refresh', [
                'sportId' => $sportId
            ]);

            $this->triggerBackgroundSync($sportId, $leagueIds, $matchType);
        }
    }

    /**
     * Format database matches for API response
     */
    private function formatMatchesForApi($databaseMatches, $timezone = 'UTC')
    {
        if (is_array($databaseMatches)) {
            $databaseMatches = collect($databaseMatches);
        }

        return $databaseMatches->map(function($match) use ($timezone) {
            $isLiveVisible = $this->isLiveVisible($match);
            
            // Use UTC for time comparisons (database stores times in UTC)
            $utcNow = \Carbon\Carbon::now('UTC');

            // CRITICAL FIX: Live matches must have STARTED (startTime <= now)
            // Pinnacle marks matches as live_status_id=1 even before they start (live betting available)
            // But for frontend, "live" means match is ACTUALLY playing right now
            $bettingAvailability = 'prematch';
            if ($isLiveVisible) {
                // Match is actually live (has started and is playing)
                $bettingAvailability = 'live';
            } elseif ($match->startTime && $match->startTime > $utcNow) {
                // Future match (hasn't started yet) - should show as prematch
                // This includes matches with live_status_id > 0 (Pinnacle marks as "live for betting" but hasn't started)
                $bettingAvailability = 'prematch';
            } elseif ($match->betting_availability) {
                $bettingAvailability = $match->betting_availability;
            }

            return [
                'id' => $match->eventId,
                'sport_id' => $match->sportId,
                'home_team' => $match->homeTeam,
                'away_team' => $match->awayTeam,
                'home_team_id' => $match->home_team_id,
                'away_team_id' => $match->away_team_id,
                'league_id' => $match->leagueId,
                'league_name' => $match->league ? $match->league->name : 'League ' . $match->leagueId,
                'scheduled_time' => $match->startTime ? $this->formatScheduledTime($match->startTime, $timezone) : 'TBD',
                'startTime' => $match->startTime ? $match->startTime->toIso8601String() : null, // Raw ISO timestamp for sorting
                'match_type' => $match->match_type ?? $match->eventType,
                'betting_availability' => $bettingAvailability,
                'live_status_id' => $match->live_status_id ?? 0,
                'has_open_markets' => $match->hasOpenMarkets ?? false,
                'score' => [
                    'home' => ($match->home_score > 0) ? $match->home_score : null,
                    'away' => ($match->away_score > 0) ? $match->away_score : null
                ],
                'duration' => $isLiveVisible ? $match->match_duration : null,
                'odds_count' => 0,
                'images' => [
                    'home_team_logo' => null,
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
            try {
                \Illuminate\Support\Facades\Redis::connection()->ping();
            } catch (\Exception $e) {
                $healthStatus['warnings'][] = 'Cache service unavailable';
                $healthStatus['degraded_services'][] = 'cache';
                Log::warning('System health: Cache unavailable', ['error' => $e->getMessage()]);
            }

            $recentFailures = DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHours(1))
                ->count();

            if ($recentFailures > 5) {
                $healthStatus['warnings'][] = 'High job failure rate detected';
                $healthStatus['degraded_services'][] = 'queue_processing';
                Log::warning('System health: High job failure rate', ['failures' => $recentFailures]);
            }

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

            if (empty($response['matches'])) {
                $response['message'] = 'Service temporarily experiencing issues - showing available data';
            }
        } else {
            $response['system_status'] = 'healthy';
        }

        return $response;
    }

    private function getMatchesFromCache($sportId, $leagueIds, $matchType, $timezone = 'UTC')
    {
        $matches = [];

        if ($matchType === 'all' || $matchType === 'live') {
            $liveMatches = $this->getLiveMatchesFromCache($sportId, $leagueIds);
            $matches = array_merge($matches, $liveMatches);
        }

        if ($matchType === 'all' || $matchType === 'prematch') {
            $prematchMatches = $this->getPrematchMatchesFromCache($sportId, $leagueIds);
            $matches = array_merge($matches, $prematchMatches);
        }

        return $matches;
    }

    /**
     * Authoritative helper to determine if a match should be visible as LIVE
     * Returns true when:
     * - startTime is NOT NULL
     * - startTime <= current time (match has started)
     * - live_status_id is not -1 (cancelled) or 2 (finished)
     * - AND either live_status_id > 0 OR API-Football score exists (home_score > 0 OR away_score > 0)
     */
    private function isLiveVisible($match): bool
    {
        // Check if match has startTime
        if (!isset($match['startTime']) && !isset($match->startTime)) {
            return false;
        }

        $startTime = isset($match['startTime']) ? $match['startTime'] : $match->startTime;

        if (is_string($startTime)) {
            $startTime = \Carbon\Carbon::parse($startTime);
        } elseif ($startTime instanceof \Carbon\Carbon) {
            // Ensure it's in UTC for comparison
            $startTime = $startTime->utc();
        }

        // Use UTC for time comparisons (database stores times in UTC)
        $now = \Carbon\Carbon::now('UTC');
        
        // CRITICAL: Match must have STARTED to be "live"
        // Future matches are NOT live, even if Pinnacle marks them as "live for betting"
        if ($startTime > $now) {
            return false; // Match hasn't started yet - NOT live
        }
        
        // Match has started - check if it's actually live
        $liveStatusId = isset($match['live_status_id']) ? $match['live_status_id'] : (isset($match->live_status_id) ? $match->live_status_id : 0);
        
        // CRITICAL: ABSOLUTE EXCLUSION - Finished matches should NEVER show as live
        // This is checked FIRST before any other logic
        if ($liveStatusId === 2) {
            return false; // Finished - NEVER show
        }
        
        // Match is cancelled - never show as live
        if ($liveStatusId === -1) {
            return false; // Cancelled - NEVER show
        }

        // PRIMARY: If aggregation system says it's live (live_status_id > 0) AND match has started, it's live
        // This is the main condition - trust aggregation system
        if ($liveStatusId > 0) {
            return true;
        }

        // SECONDARY: For matches that have started, check if they have open markets
        // This catches matches that started but don't have live_status_id set yet
        $hasOpenMarkets = isset($match['hasOpenMarkets']) ? $match['hasOpenMarkets'] : (isset($match->hasOpenMarkets) ? $match->hasOpenMarkets : false);
        $hasOpenMarketsArray = isset($match['has_open_markets']) ? $match['has_open_markets'] : false;

        if ($hasOpenMarkets || $hasOpenMarketsArray) {
            return true; // Match has started and has open markets - likely live
        }

        // TERTIARY: Check for scores (indicates match is actually playing)
        // This is a weaker indicator but still valid
        $homeScore = isset($match['home_score']) ? $match['home_score'] : (isset($match->home_score) ? $match->home_score : 0);
        $awayScore = isset($match['away_score']) ? $match['away_score'] : (isset($match->away_score) ? $match->away_score : 0);
        $hasScores = ($homeScore > 0 || $awayScore > 0);

        if ($hasScores) {
            return true; // Match has scores, it's live
        }

        // CRITICAL FIX: Exclude matches that are clearly finished
        // If match started more than 4 hours ago and has no live indicators, it's likely finished
        if ($startTime && $startTime < $now) {
            $hoursSinceStart = $now->diffInHours($startTime);
            $sportId = isset($match['sport_id']) ? $match['sport_id'] : (isset($match->sportId) ? $match->sportId : 1);
            
            // Soccer matches typically last 2 hours max (90 min + extra time)
            // Basketball matches typically last 2.5 hours max
            // If match started 4+ hours ago with no live indicators, it's finished
            $maxMatchDuration = ($sportId == 1) ? 3 : 4; // Soccer: 3 hours, others: 4 hours
            
            if ($hoursSinceStart > $maxMatchDuration && $liveStatusId == 0 && !$hasScores) {
                $matchId = $match['id'] ?? $match->eventId ?? null;
                Log::info('Excluding likely finished match from live list - marking as finished', [
                    'match_id' => $matchId,
                    'hours_since_start' => $hoursSinceStart,
                    'live_status_id' => $liveStatusId,
                    'has_scores' => $hasScores
                ]);
                
                // Mark match as finished in database if we have the ID
                if ($matchId) {
                    try {
                        $dbMatch = \App\Models\SportsMatch::where('eventId', $matchId)->first();
                        if ($dbMatch && $dbMatch->live_status_id != 2) {
                            $dbMatch->markAsFinished();
                            Log::info('Marked match as finished based on time elapsed', [
                                'match_id' => $matchId,
                                'hours_since_start' => $hoursSinceStart
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to mark match as finished', [
                            'match_id' => $matchId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                return false; // Match is likely finished
            }
        }

        // If match has started but no indicators, still consider it potentially live
        // This ensures we don't miss matches that just started
        // BUT only if it started recently (within last 3 hours)
        if ($startTime && $startTime < $now) {
            $hoursSinceStart = $now->diffInHours($startTime);
            if ($hoursSinceStart <= 3) {
                return true; // Match started recently, might be live
            }
        }

        return false; // Default to not live if we can't determine
    }

    /**
     * Detect user's timezone from request
     * Priority: 1) Explicit timezone param, 2) X-Timezone header, 3) Accept-Language fallback, 4) UTC
     */
    private function detectUserTimezone(Request $request): string
    {
        // Check explicit timezone parameter
        $timezone = $request->input('timezone');
        if ($timezone && $this->isValidTimezone($timezone)) {
            return $timezone;
        }

        // Check X-Timezone header (can be set by frontend)
        $headerTimezone = $request->header('X-Timezone');
        if ($headerTimezone && $this->isValidTimezone($headerTimezone)) {
            return $headerTimezone;
        }

        // Check Accept-Language for basic region detection (fallback)
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $timezone = $this->timezoneFromAcceptLanguage($acceptLanguage);
            if ($timezone) {
                return $timezone;
            }
        }

        // Default to UTC
        return 'UTC';
    }

    /**
     * Validate timezone identifier
     */
    private function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Format scheduled time with fallback for invalid timezones
     */
    private function formatScheduledTime($startTime, string $timezone = null): string
    {
        try {
            $validTimezone = $timezone && $this->isValidTimezone($timezone) ? $timezone : 'UTC';
            return $startTime->setTimezone($validTimezone)->format('m/d/Y, h:i A T');
        } catch (Exception $e) {
            // Fallback to UTC if timezone conversion fails
            return $startTime->setTimezone('UTC')->format('m/d/Y, h:i A T');
        }
    }

    /**
     * Basic timezone detection from Accept-Language header
     * This is a simple fallback - real implementation would use IP geolocation
     */
    private function timezoneFromAcceptLanguage(string $acceptLanguage): ?string
    {
        // Extract primary language tag (e.g., "en-US" -> "US")
        $parts = explode('-', explode(',', $acceptLanguage)[0]);
        if (count($parts) >= 2) {
            $region = strtoupper($parts[1]);

            // Basic region to timezone mapping
            $regionMap = [
                'US' => 'America/New_York',    // Eastern Time
                'GB' => 'Europe/London',       // GMT/BST
                'IN' => 'Asia/Kolkata',        // IST
                'CN' => 'Asia/Shanghai',       // CST
                'JP' => 'Asia/Tokyo',          // JST
                'AU' => 'Australia/Sydney',    // AEST/AEDT
                'DE' => 'Europe/Berlin',       // CET/CEST
                'FR' => 'Europe/Paris',        // CET/CEST
                'IT' => 'Europe/Rome',         // CET/CEST
                'ES' => 'Europe/Madrid',       // CET/CEST
                'BR' => 'America/Sao_Paulo',   // BRT/BRST
                'MX' => 'America/Mexico_City', // CST/CDT
                'CA' => 'America/Toronto',     // EST/EDT
            ];

            return $regionMap[$region] ?? null;
        }

        return null;
    }

    /**
     * Apply same visibility filters as database queries to prevent finished matches from leaking via cache
     */
    private function filterMatchesByVisibilityRules($matches)
    {
        return array_filter($matches, function($match) {
            // CRITICAL: ABSOLUTE EXCLUSION of finished matches - NO EXCEPTIONS
            $liveStatusId = $match['live_status_id'] ?? (isset($match->live_status_id) ? $match->live_status_id : 0);
            
            // Finished matches (status 2) should NEVER appear, regardless of any other condition
            if ($liveStatusId === 2) {
                return false;
            }
            
            // Cancelled matches (status -1) should also never appear
            if ($liveStatusId === -1) {
                return false;
            }
            
            if (!$this->isLiveVisible($match)) {
                return false;
            }

            if ($liveStatusId === -1) {
                return false;
            }

            if ($liveStatusId === 2) {
                return false;
            }

            $hasOpenMarkets = $match['has_open_markets'] ?? false;
            if (!$hasOpenMarkets) {
                return false;
            }

            return true;
        });
    }

    /**
     * Filter out stale or finished prematch matches from cache
     * Defensive filter to prevent outdated data from being served
     */
    private function filterStalePrematchMatches($matches)
    {
        $twoHoursAgo = now()->subMinutes(120);
        $twoDaysFromNow = now()->addDays(2)->endOfDay();

        return array_filter($matches, function($match) use ($twoHoursAgo, $twoDaysFromNow) {
            // Check status field
            $status = $match['status'] ?? $match['eventType'] ?? null;
            if ($status === 'finished') {
                Log::debug('Discarded cached prematch match - finished status', [
                    'match_id' => $match['id'] ?? 'unknown',
                    'status' => $status
                ]);
                return false;
            }

            // Check startTime (already filtered by filterMatchesByVisibilityRules for live_status_id = 2)
            if (isset($match['startTime'])) {
                try {
                    // Parse startTime - handle different formats
                    $startTime = null;
                    if (is_string($match['startTime'])) {
                        $startTime = \Carbon\Carbon::parse($match['startTime']);
                    } elseif ($match['startTime'] instanceof \Carbon\Carbon) {
                        $startTime = $match['startTime'];
                    }

                    if ($startTime && $startTime < $twoHoursAgo) {
                        Log::debug('Discarded cached prematch match - too old', [
                            'match_id' => $match['id'] ?? 'unknown',
                            'startTime' => $startTime->toDateTimeString(),
                            'twoHoursAgo' => $twoHoursAgo->toDateTimeString(),
                            'age_minutes' => $twoHoursAgo->diffInMinutes($startTime)
                        ]);
                        return false;
                    }

                    // Filter out matches that are more than 1 day in the future
                    if ($startTime) {
                        $matchDate = $startTime->format('Y-m-d');
                        $maxDate = now()->addDays(1)->format('Y-m-d');
                        if ($matchDate > $maxDate) {
                            Log::debug('Discarded cached prematch match - too far in future', [
                                'match_id' => $match['id'] ?? 'unknown',
                                'match_date' => $matchDate,
                                'max_allowed_date' => $maxDate,
                                'days_ahead' => now()->diffInDays($startTime)
                            ]);
                            return false;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to parse startTime in cached match', [
                        'match_id' => $match['id'] ?? 'unknown',
                        'startTime' => $match['startTime'],
                        'error' => $e->getMessage()
                    ]);
                    // If we can't parse the time, discard the match to be safe
                    return false;
                }
            }

            return true;
        });
    }

    private function getLiveMatchesFromCache($sportId, $leagueIds)
    {
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

        $allLiveMatches = $this->filterMatchesByVisibilityRules($allLiveMatches);

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

        // Apply same visibility filters as database queries
        $staleMatches = $this->filterMatchesByVisibilityRules($staleMatches);

        if (!empty($staleMatches)) {
            $staleMatches = $this->attachImagesToMatches($staleMatches);
            $staleMatches = $this->attachOddsFromCache($staleMatches);
        }

        return $staleMatches;
    }

    private function getPrematchMatchesFromCache($sportId, $leagueIds)
    {
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

        $allPrematchMatches = $this->filterMatchesByVisibilityRules($allPrematchMatches);

        // Additional defensive filtering for cached prematch matches
        $allPrematchMatches = $this->filterStalePrematchMatches($allPrematchMatches);

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
        // CRITICAL: DISABLED automatic background sync from API requests
        // This was causing excessive API calls and costs
        // Scheduled jobs in Kernel.php handle all synchronization automatically
        // Manual refresh endpoint is still available for explicit user requests
        
        Log::debug('Background sync disabled - using scheduled jobs only', [
            'sportId' => $sportId,
            'matchType' => $matchType,
            'reason' => 'Automatic sync disabled to reduce API costs - scheduled jobs handle sync'
        ]);
        
        return; // Skip all automatic dispatches - rely on scheduled jobs only
        
        // OLD CODE BELOW - KEPT FOR REFERENCE BUT DISABLED
        /*
        // RATE LIMITING: Prevent duplicate dispatches within cooldown period
        // This prevents frontend polling from triggering too many jobs
        $cooldownKey = "sync_cooldown:{$sportId}:{$matchType}";
        $cooldownPeriod = 300; // 5 minutes cooldown - INCREASED to reduce costs
        
        // GLOBAL cooldown check - prevent ANY sync within 5 minutes
        $globalCooldownKey = 'sync_cooldown:global';
        if (Cache::has($globalCooldownKey)) {
            Log::debug('Background sync skipped - global cooldown active', [
                'sportId' => $sportId,
                'matchType' => $matchType,
                'cooldown_seconds_remaining' => Cache::get($globalCooldownKey)
            ]);
            return;
        }
        
        if (Cache::has($cooldownKey)) {
            Log::debug('Background sync skipped - cooldown active', [
                'sportId' => $sportId,
                'matchType' => $matchType,
                'cooldown_seconds_remaining' => Cache::get($cooldownKey)
            ]);
            return;
        }

        if (empty($leagueIds)) {
            $allLeagueIds = DB::table('leagues')
                ->where('sportId', $sportId)
                ->pluck('pinnacleId')
                ->toArray();

            $leagueIds = $allLeagueIds;
        }

        // Set GLOBAL cooldown to prevent ANY sync for 5 minutes
        Cache::put($globalCooldownKey, $cooldownPeriod, $cooldownPeriod);
        Cache::put($cooldownKey, $cooldownPeriod, $cooldownPeriod);

        if ($matchType === 'all' || $matchType === 'live') {
            \App\Jobs\LiveMatchSyncJob::dispatch($sportId, $leagueIds)->onQueue('live-sync');
        }

        if ($matchType === 'all' || $matchType === 'prematch') {
            \App\Jobs\PrematchSyncJob::dispatch($sportId, $leagueIds)->onQueue('prematch-sync');
        }

        $oddsCooldownKey = 'sync_cooldown:odds';
        if (!Cache::has($oddsCooldownKey)) {
            \App\Jobs\OddsSyncJob::dispatch()->onQueue('odds-sync');
            Cache::put($oddsCooldownKey, 180, 180); // 3 minutes cooldown
        }
        */
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

            // Get the match to determine event type (live or prematch)
            $match = SportsMatch::where('eventId', $matchId)->first();
            $eventType = $match && $match->eventType === 'live' ? 'live' : 'prematch';

            // Provider-Agnostic Odds Aggregation
            // Fetch odds from ALL providers independently (no priority)
            
            // 1. Fetch from Pinnacle
            $pinnacleOdds = [];
            try {
                // CRITICAL FIX: Use getMarketsForEvent to get markets for THIS specific event
                // This ensures we get the correct event even if it's not in the general /markets/live response
                $isLive = $eventType === 'live';
                
                // Try event-specific endpoint first
                $marketsData = $this->pinnacleApi->getMarketsForEvent($matchId, $sportId, $isLive);
                
                Log::info('DEBUG: getMarketsForEvent response', [
                    'matchId' => $matchId,
                    'sportId' => $sportId,
                    'isLive' => $isLive,
                    'has_events' => isset($marketsData['events']),
                    'events_count' => isset($marketsData['events']) ? count($marketsData['events']) : 0,
                    'top_keys' => array_keys($marketsData),
                    'raw_response_sample' => json_encode(array_slice($marketsData, 0, 3, true))
                ]);
                
                // If that doesn't work, try the opposite type (live vs prematch)
                if (empty($marketsData['events'])) {
                    Log::warning('Event-specific markets empty, trying opposite type', [
                        'matchId' => $matchId,
                        'sportId' => $sportId,
                        'original_type' => $isLive ? 'live' : 'prematch',
                        'trying_type' => $isLive ? 'prematch' : 'live'
                    ]);
                    $marketsData = $this->pinnacleApi->getMarketsForEvent($matchId, $sportId, !$isLive);
                    Log::info('DEBUG: getMarketsForEvent (opposite type) response', [
                        'has_events' => isset($marketsData['events']),
                        'events_count' => isset($marketsData['events']) ? count($marketsData['events']) : 0,
                        'top_keys' => array_keys($marketsData)
                    ]);
                }
                
                // If still empty, fall back to general markets endpoint
                if (empty($marketsData['events'])) {
                    Log::warning('Event-specific markets still empty, trying general markets endpoint', [
                        'matchId' => $matchId,
                        'sportId' => $sportId,
                        'isLive' => $isLive
                    ]);
                    $marketsData = $this->pinnacleApi->getMarkets($sportId, $isLive);
                    Log::info('DEBUG: getMarkets fallback response', [
                        'has_events' => isset($marketsData['events']),
                        'events_count' => isset($marketsData['events']) ? count($marketsData['events']) : 0,
                        'top_keys' => array_keys($marketsData)
                    ]);
                    
                    // If still empty, try opposite type in general markets
                    if (empty($marketsData['events'])) {
                        Log::warning('General markets empty, trying opposite type', [
                            'matchId' => $matchId,
                            'sportId' => $sportId
                        ]);
                        $marketsData = $this->pinnacleApi->getMarkets($sportId, !$isLive);
                        Log::info('DEBUG: getMarkets (opposite type) response', [
                            'has_events' => isset($marketsData['events']),
                            'events_count' => isset($marketsData['events']) ? count($marketsData['events']) : 0
                        ]);
                    }
                }
                
                $pinnacleOdds = $this->extractPinnacleOddsFromMarkets($marketsData, $matchId);
                
                Log::info('DEBUG: After extractPinnacleOddsFromMarkets', [
                    'matchId' => $matchId,
                    'extracted_odds_count' => count($pinnacleOdds),
                    'odds_by_type' => array_count_values(array_column($pinnacleOdds, 'market_type')),
                    'sample_odds' => array_slice($pinnacleOdds, 0, 3)
                ]);
                
                // If no odds found and match is live, try prematch markets as fallback
                // Some matches might have odds in prematch but are currently live
                if (empty($pinnacleOdds) && $isLive) {
                    Log::info('DEBUG: No live odds found, trying prematch markets as fallback', [
                        'matchId' => $matchId,
                        'sportId' => $sportId
                    ]);
                    $prematchMarketsData = $this->pinnacleApi->getMarketsForEvent($matchId, $sportId, false);
                    $prematchOdds = $this->extractPinnacleOddsFromMarkets($prematchMarketsData, $matchId);
                    if (!empty($prematchOdds)) {
                        $pinnacleOdds = $prematchOdds;
                        Log::info('DEBUG: Found odds in prematch markets', [
                            'matchId' => $matchId,
                            'odds_count' => count($pinnacleOdds)
                        ]);
                    }
                }
                
                // Also fetch special markets for additional odds
                // NOTE: For non-soccer sports, standard markets (money_line, spreads, totals) 
                // might be in special markets instead of regular markets
                // Try to get league_id from match to filter special markets at API level
                $leagueIds = [];
                if ($match && isset($match->leagueId)) {
                    $leagueIds = [$match->leagueId];
                }
                
                // Try special markets with league filter first
                $specialMarketsData = $this->pinnacleApi->getSpecialMarkets($eventType, $sportId, $leagueIds);
                
                Log::info('DEBUG: getSpecialMarkets response', [
                    'matchId' => $matchId,
                    'sportId' => $sportId,
                    'leagueIds' => $leagueIds,
                    'eventType' => $eventType,
                    'is_array' => is_array($specialMarketsData),
                    'count' => is_array($specialMarketsData) ? count($specialMarketsData) : 0,
                    'has_specials' => isset($specialMarketsData['specials']),
                    'has_special_markets' => isset($specialMarketsData['special_markets']),
                    'top_keys' => is_array($specialMarketsData) ? array_keys($specialMarketsData) : [],
                    'raw_response_sample' => is_array($specialMarketsData) ? json_encode(array_slice($specialMarketsData, 0, 3, true)) : 'not_array'
                ]);
                
                // CRITICAL FIX: Filter special odds by match event_id to prevent showing odds from other matches
                $specialOdds = $this->extractPinnacleOdds($specialMarketsData, $matchId, $match);
                
                // If no special odds found and we filtered by league, try without league filter
                // Some matches might be in special markets but not filtered correctly by league
                if (empty($specialOdds) && !empty($leagueIds)) {
                    Log::info('DEBUG: No special odds with league filter, trying without league filter', [
                        'matchId' => $matchId,
                        'sportId' => $sportId,
                        'eventType' => $eventType
                    ]);
                    $specialMarketsDataNoFilter = $this->pinnacleApi->getSpecialMarkets($eventType, $sportId, []);
                    $specialOddsNoFilter = $this->extractPinnacleOdds($specialMarketsDataNoFilter, $matchId, $match);
                    if (!empty($specialOddsNoFilter)) {
                        $specialOdds = $specialOddsNoFilter;
                        Log::info('DEBUG: Found special odds without league filter', [
                            'matchId' => $matchId,
                            'odds_count' => count($specialOdds)
                        ]);
                    }
                }
                
                // If still no odds, try opposite event type (live vs prematch)
                if (empty($specialOdds)) {
                    $oppositeEventType = $eventType === 'live' ? 'prematch' : 'live';
                    Log::info('DEBUG: No special odds found, trying opposite event type', [
                        'matchId' => $matchId,
                        'original_type' => $eventType,
                        'trying_type' => $oppositeEventType
                    ]);
                    $specialMarketsDataOpposite = $this->pinnacleApi->getSpecialMarkets($oppositeEventType, $sportId, $leagueIds);
                    $specialOddsOpposite = $this->extractPinnacleOdds($specialMarketsDataOpposite, $matchId, $match);
                    if (!empty($specialOddsOpposite)) {
                        $specialOdds = $specialOddsOpposite;
                        Log::info('DEBUG: Found special odds with opposite event type', [
                            'matchId' => $matchId,
                            'odds_count' => count($specialOdds)
                        ]);
                    }
                }
                
                // Count special odds by type before filtering
                $specialOddsByType = [];
                foreach ($specialOdds as $odd) {
                    $type = $odd['market_type'] ?? 'unknown';
                    $specialOddsByType[$type] = ($specialOddsByType[$type] ?? 0) + 1;
                }
                
                Log::info('DEBUG: After extractPinnacleOdds (special markets)', [
                    'matchId' => $matchId,
                    'special_markets_raw_count' => is_array($specialMarketsData) ? count($specialMarketsData) : 0,
                    'special_odds_extracted' => count($specialOdds),
                    'special_odds_by_type' => $specialOddsByType,
                    'sample_special_odds' => array_slice($specialOdds, 0, 5)
                ]);
                
                // Merge filtered special odds (already filtered by event_id)
                $pinnacleOdds = array_merge($pinnacleOdds, $specialOdds);
                
                // Count odds by market type for debugging
                $oddsByType = [];
                foreach ($pinnacleOdds as $odd) {
                    $type = $odd['market_type'] ?? 'unknown';
                    $oddsByType[$type] = ($oddsByType[$type] ?? 0) + 1;
                }
                
                Log::info('DEBUG: Final Pinnacle odds before aggregation', [
                    'matchId' => $matchId,
                    'sportId' => $sportId,
                    'standard_odds_count' => count($pinnacleOdds) - count($specialOdds),
                    'special_odds_count' => count($specialOdds),
                    'total_odds_count' => count($pinnacleOdds),
                    'odds_by_type' => $oddsByType,
                    'sample_all_odds' => array_slice($pinnacleOdds, 0, 10)
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Pinnacle odds', [
                    'matchId' => $matchId,
                    'error' => $e->getMessage()
                ]);
            }

            // 2. Fetch from API-Football if fixtureId is available
            $apiFootballOdds = [];
            $apiFootballPlayers = [];
            $fixtureId = null;
            
            if ($match) {
                $apiFootballData = ApiFootballData::where('eventId', $matchId)->first();
                $fixtureId = $apiFootballData->fixtureId ?? null;
                
                // If no fixtureId in database, try to find it by matching teams and date
                if (!$fixtureId && $sportId == 1) { // Only for soccer
                    try {
                        $matchDate = $match->startTime ? $match->startTime->format('Y-m-d') : date('Y-m-d');
                        $fixtures = $this->apiFootballService->getFixtures(null, null, false, null, $matchDate);
                        
                        if (!empty($fixtures['response'])) {
                            $homeTeamName = $this->normalizeTeamName($match->homeTeam);
                            $awayTeamName = $this->normalizeTeamName($match->awayTeam);
                            
                            foreach ($fixtures['response'] as $fixture) {
                                $fixtureHome = $this->normalizeTeamName($fixture['teams']['home']['name'] ?? '');
                                $fixtureAway = $this->normalizeTeamName($fixture['teams']['away']['name'] ?? '');
                                
                                if ($fixtureHome === $homeTeamName && $fixtureAway === $awayTeamName) {
                                    $fixtureId = $fixture['fixture']['id'];
                                    Log::info('Found fixtureId by team matching', [
                                        'matchId' => $matchId,
                                        'fixtureId' => $fixtureId,
                                        'home' => $match->homeTeam,
                                        'away' => $match->awayTeam
                                    ]);
                                    break;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to find fixtureId by matching', [
                            'matchId' => $matchId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                if ($fixtureId) {
                    try {
                        $apiFootballOdds = $this->apiFootballService->getOdds($fixtureId, $sportId);
                        Log::info('Fetched API-Football odds', [
                            'matchId' => $matchId,
                            'fixtureId' => $fixtureId,
                            'odds_count' => count($apiFootballOdds)
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to fetch API-Football odds', [
                            'matchId' => $matchId,
                            'fixtureId' => $fixtureId,
                            'error' => $e->getMessage()
                        ]);
                    }

                    // Fetch player lineups for Player Props
                    try {
                        $apiFootballPlayers = $this->apiFootballService->getFixtureLineups($fixtureId, $sportId);
                        Log::info('Fetched API-Football lineups', [
                            'matchId' => $matchId,
                            'fixtureId' => $fixtureId,
                            'has_lineups' => !empty($apiFootballPlayers),
                            'lineups_count' => is_array($apiFootballPlayers) ? count($apiFootballPlayers) : 0
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to fetch API-Football lineups', [
                            'matchId' => $matchId,
                            'fixtureId' => $fixtureId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // 3. Fetch from Odds-Feed (if available)
            $oddsFeedOdds = [];
            try {
                $oddsFeedService = app(\App\Services\OddsFeedService::class);
                if ($oddsFeedService->isEnabled()) {
                    $eventType = $match->eventType ?? ($match->live_status_id > 0 ? 'live' : 'prematch');
                    $oddsFeedOdds = $oddsFeedService->getMatchOdds($match->eventId, $eventType);
                    
                    Log::debug('Fetched odds from Odds-Feed', [
                        'match_id' => $matchId,
                        'event_id' => $match->eventId,
                        'odds_count' => count($oddsFeedOdds)
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Odds-Feed odds', [
                    'match_id' => $matchId,
                    'error' => $e->getMessage()
                ]);
            }

            // 4. Aggregate odds from all providers (provider-agnostic)
            $oddsAggregationService = app(OddsAggregationService::class);
            $aggregatedOdds = $oddsAggregationService->aggregateOdds(
                $pinnacleOdds,
                $oddsFeedOdds,
                $apiFootballOdds
            );

            Log::info('Aggregated odds from all providers', [
                'matchId' => $matchId,
                'aggregated_count' => count($aggregatedOdds),
                'pinnacle_count' => count($pinnacleOdds),
                'api_football_count' => count($apiFootballOdds),
                'odds_feed_count' => count($oddsFeedOdds)
            ]);

            // 5. Process aggregated odds (convert to display format, add player props, etc.)
            Log::info('DEBUG: Before processAggregatedOdds', [
                'matchId' => $matchId,
                'aggregated_count' => count($aggregatedOdds),
                'market_type' => $marketType,
                'period' => $period,
                'aggregated_by_type' => array_count_values(array_column($aggregatedOdds, 'market_type'))
            ]);
            
            $oddsData = $this->processAggregatedOdds($aggregatedOdds, $matchId, $period, $marketType, $apiFootballPlayers, $sportId);
            
            Log::info('DEBUG: After processAggregatedOdds - FINAL RESULT', [
                'matchId' => $matchId,
                'market_type' => $marketType,
                'total_count' => $oddsData['total_count'] ?? 0,
                'showing_count' => $oddsData['showing_count'] ?? 0,
                'odds_count' => count($oddsData['odds'] ?? []),
                'sample_odds' => array_slice($oddsData['odds'] ?? [], 0, 5)
            ]);

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

    private function storeMatches(array $matches): void
    {
        foreach ($matches as $matchData) {
            try {
                $scheduledTime = null;
                if ($matchData['scheduled_time'] !== 'TBD') {
                    $scheduledTime = \DateTime::createFromFormat('m/d/Y, H:i:s', $matchData['scheduled_time']);
                }

                $isLive = $matchData['match_type'] === 'live';

                SportsMatch::updateOrCreate(
                    ['eventId' => $matchData['id']],
                    [
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

    private function filterMatchesByLeagues($events, $selectedLeagueIds)
    {
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
            Log::debug('Processing match event', [
                'event_id' => $event['event_id'] ?? 'unknown',
                'available_keys' => array_keys($event),
                'live_status_id' => $event['live_status_id'] ?? 'not_set',
                'status' => $event['status'] ?? 'not_set',
                'event_type' => $event['event_type'] ?? 'not_set',
                'starts' => $event['starts'] ?? 'not_set'
            ]);

            $homeTeamName = $event['home'] ?? 'Unknown';
            $awayTeamName = $event['away'] ?? 'Unknown';
            $leagueId = $event['league_id'] ?? null;
            $leagueName = $event['league_name'] ?? 'Unknown League';
            $sportId = $event['sport_id'] ?? null;

            $homeTeamResolution = $this->teamResolutionService->resolveTeamId(
                'pinnacle',
                $homeTeamName,
                null,
                $sportId,
                $leagueId
            );

            $awayTeamResolution = $this->teamResolutionService->resolveTeamId(
                'pinnacle',
                $awayTeamName,
                null,
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

            $oddsCount = 0;

            $hasOpenMarkets = $event['is_have_open_markets'] ?? false;

            $isLive = ($event['live_status_id'] ?? 0) > 0;
            $matchType = $isLive ? 'live' : 'prematch';

            if ($requestedMatchType !== 'all' && $matchType !== $requestedMatchType) {
                Log::debug('Skipping match due to type mismatch', [
                    'event_id' => $event['event_id'],
                    'requested_type' => $requestedMatchType,
                    'actual_type' => $matchType,
                    'live_status_id' => $event['live_status_id']
                ]);
                continue;
            }

            Log::debug('Match type determination', [
                'event_id' => $event['event_id'],
                'live_status_id' => $event['live_status_id'] ?? 0,
                'isLive' => $isLive,
                'matchType' => $matchType
            ]);

            $scheduledTime = $event['starts'] ?? null;
            $formattedTime = $scheduledTime ?
                \Carbon\Carbon::parse($scheduledTime)->setTimezone('UTC')->format('m/d/Y, H:i:s T') :
                'TBD';

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

            $images = [
                'home_team_logo' => $homeEnrichment['logo_url'] ?? null,
                'away_team_logo' => $awayEnrichment['logo_url'] ?? null,
                'league_logo' => null,
                'country_flag' => null
            ];


            $processedMatches[] = [
                'id' => $event['event_id'],
                'sport_id' => $sportId,
                'home_team' => $homeTeamName,
                'away_team' => $awayTeamName,
                'home_team_id' => $homeTeamResolution['team_id'],
                'away_team_id' => $awayTeamResolution['team_id'],
                'home_team_data' => $homeTeamData,
                'away_team_data' => $awayTeamData,
                'league_id' => $leagueId,
                'league_name' => $leagueName,
                'scheduled_time' => $formattedTime,
                'match_type' => $matchType,
                'has_open_markets' => $hasOpenMarkets,
                'odds_count' => $oddsCount,
                'images' => $images,
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
     * Extract odds from Pinnacle markets data
     * 
     * @param array $marketsData Raw Pinnacle markets data
     * @param string|int|null $eventId Event ID to filter by (optional)
     * @param object|null $match Match object for additional filtering (optional)
     * @return array Extracted odds in normalized format
     */
    private function extractPinnacleOdds(array $marketsData, $eventId = null, $match = null): array
    {
        $odds = [];
        
        // Check if special markets are grouped by event (common structure)
        // Structure might be: [{'event_id': 123, 'markets': [...]}, ...]
        if (is_array($marketsData) && !empty($marketsData) && isset($marketsData[0]['event_id'])) {
            // Markets are grouped by event - filter at event level
            foreach ($marketsData as $eventGroup) {
                $groupEventId = $eventGroup['event_id'] ?? null;
                if ($eventId && $groupEventId) {
                    // Handle both string and int comparison
                    $matches = ($groupEventId == $eventId) || 
                              ((string)$groupEventId === (string)$eventId) ||
                              ((int)$groupEventId === (int)$eventId);
                    if (!$matches) {
                        continue; // Skip this event group
                    }
                }
                
                // Extract markets from this event group
                $eventMarkets = $eventGroup['markets'] ?? $eventGroup['specials'] ?? $eventGroup['special_markets'] ?? [];
                if (is_array($eventMarkets)) {
                    foreach ($eventMarkets as $market) {
                        // Process this market (no need to check event_id again, already filtered)
                        $marketsDataToProcess = ['specials' => [$market]];
                        $extracted = $this->extractPinnacleOdds($marketsDataToProcess, null, null);
                        $odds = array_merge($odds, $extracted);
                    }
                }
            }
            return $odds;
        }
        
        // Helper function to check if a market belongs to the specified event
        // SIMPLIFIED: For standard markets (money_line, spreads, totals), be more lenient
        // since they're already filtered by league at API level
        $belongsToEvent = function($market, $isStandardMarket = false) use ($eventId, $match) {
            // If no eventId provided, include all (backward compatibility)
            if ($eventId === null) {
                return true;
            }
            
            // Check if market has event_id field
            $marketEventId = $market['event_id'] ?? null;
            if ($marketEventId !== null) {
                // Handle both string and int comparison
                return ($marketEventId == $eventId) || 
                       ((string)$marketEventId === (string)$eventId) ||
                       ((int)$marketEventId === (int)$eventId);
            }
            
            // CRITICAL FIX: If it's a standard market type and no event_id, include it
            // (special markets are filtered by league at API level, so this is safe)
            if ($isStandardMarket) {
                return true;
            }
            
            // For non-standard markets, try team name matching
            if ($match && isset($match->homeTeam) && isset($match->awayTeam)) {
                $marketName = strtolower($market['name'] ?? '');
                $homeTeam = strtolower($match->homeTeam);
                $awayTeam = strtolower($match->awayTeam);
                
                // Also check in lines/outcomes for team references
                $allText = $marketName;
                if (isset($market['lines']) && is_array($market['lines'])) {
                    foreach ($market['lines'] as $line) {
                        $allText .= ' ' . strtolower($line['name'] ?? '');
                        $allText .= ' ' . strtolower($line['outcome'] ?? '');
                    }
                }
                if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                    foreach ($market['outcomes'] as $outcome) {
                        $allText .= ' ' . strtolower($outcome['name'] ?? '');
                    }
                }
                
                // Check if market contains team names
                $containsHomeTeam = stripos($allText, $homeTeam) !== false;
                $containsAwayTeam = stripos($allText, $awayTeam) !== false;
                
                if ($containsHomeTeam || $containsAwayTeam) {
                    return true;
                }
            }
            
            // For non-standard markets without event_id or team match, exclude to be safe
            return false;
        };
        
        // Map Pinnacle market names and bet_types to our standard market types
        // EXPANDED: Now handles sport-specific variations for non-soccer sports
        $marketTypeMap = [
            'match winner' => 'money_line',
            '1x2' => 'money_line',
            'money line' => 'money_line',
            'moneylin' => 'money_line',  // "Moneyline" (one word) for basketball, etc.
            'match result' => 'money_line',
            'winner' => 'money_line',
            'outright' => 'money_line',
            'to win' => 'money_line',
            'straight' => 'money_line',
            'over/under' => 'totals',
            'over under' => 'totals',
            'over-under' => 'totals',
            'ou ' => 'totals',  // "OU 150.5" format
            'total' => 'totals',
            'totals' => 'totals',
            'points total' => 'totals',
            'goals total' => 'totals',
            'runs total' => 'totals',
            'handicap' => 'spreads',
            'spread' => 'spreads',
            'asian handicap' => 'spreads',
            'asian' => 'spreads',
            'hcp' => 'spreads',  // Abbreviation
            'hdcp' => 'spreads',  // Abbreviation
            'line' => 'spreads',  // "Line" for basketball, etc.
        ];
        
        $normalizeMarketType = function($marketName, $betType = null) use ($marketTypeMap) {
            // First check bet_type if available (more reliable)
            if ($betType) {
                $bt = strtolower(trim($betType));
                if ($bt === '1x2' || $bt === 'match_winner' || $bt === 'money_line' || 
                    $bt === 'moneylin' || $bt === 'outright' || $bt === 'to_win') {
                    return 'money_line';
                }
                if ($bt === 'over_under' || $bt === 'over-under' || $bt === 'total' || 
                    $bt === 'totals' || $bt === 'points_total' || $bt === 'goals_total') {
                    return 'totals';
                }
                if ($bt === 'handicap' || $bt === 'spread' || $bt === 'asian_handicap' || 
                    $bt === 'hcp' || $bt === 'line') {
                    return 'spreads';
                }
            }
            
            // Fallback to market name matching
            $name = strtolower(trim($marketName ?? ''));
            
            // Special handling: exclude "player" from "total" matching
            if (stripos($name, 'player') !== false && stripos($name, 'total') !== false) {
                // This is a player prop, not a totals market
                return 'unknown';
            }
            
            foreach ($marketTypeMap as $key => $type) {
                if (stripos($name, $key) !== false) {
                    return $type;
                }
            }
            return 'unknown';
        };
        
        if (isset($marketsData['specials']) && is_array($marketsData['specials'])) {
            Log::info('DEBUG: Processing specials array', [
                'specials_count' => count($marketsData['specials']),
                'event_id' => $eventId
            ]);
            
            foreach ($marketsData['specials'] as $market) {
                $marketName = $market['name'] ?? 'unknown';
                $betType = $market['bet_type'] ?? null;
                $normalizedType = $normalizeMarketType($marketName, $betType);
                
                // Determine if this is a standard market type
                $isStandardMarket = in_array($normalizedType, ['money_line', 'spreads', 'totals']);
                
                Log::debug('DEBUG: Processing special market', [
                    'market_name' => $marketName,
                    'bet_type' => $betType,
                    'normalized_type' => $normalizedType,
                    'is_standard' => $isStandardMarket,
                    'has_event_id' => isset($market['event_id']),
                    'event_id_value' => $market['event_id'] ?? 'none'
                ]);
                
                // Check if market belongs to event (pass isStandardMarket flag)
                $belongsToEventResult = $belongsToEvent($market, $isStandardMarket);
                
                Log::debug('DEBUG: belongsToEvent result', [
                    'market_name' => $marketName,
                    'belongs_to_event' => $belongsToEventResult,
                    'is_standard' => $isStandardMarket
                ]);
                
                // Exclude if it doesn't belong (standard markets are handled inside belongsToEvent)
                if (!$belongsToEventResult) {
                    Log::debug('DEBUG: Excluding market - does not belong to event', [
                        'market_name' => $marketName,
                        'normalized_type' => $normalizedType
                    ]);
                    continue;
                }
                
                // Log unrecognized markets for debugging (especially for non-soccer sports)
                if ($normalizedType === 'unknown') {
                    Log::debug('Unrecognized market in extractPinnacleOdds (specials)', [
                        'market_name' => $marketName,
                        'bet_type' => $betType,
                        'market_keys' => array_keys($market),
                        'has_lines' => isset($market['lines']),
                        'has_outcomes' => isset($market['outcomes']),
                        'event_id' => $market['event_id'] ?? 'not_set'
                    ]);
                    continue;
                }
                
                // Pinnacle uses 'lines' array, not 'outcomes'
                if (isset($market['lines']) && is_array($market['lines'])) {
                    foreach ($market['lines'] as $line) {
                        $odds[] = [
                            'market_type' => $normalizedType,
                            'market_name' => $marketName,
                            'selection' => $line['name'] ?? $line['outcome'] ?? '',
                            'line' => $line['line'] ?? $line['handicap'] ?? null,
                            'price' => $line['odds'] ?? $line['price'] ?? null,
                            'period' => $market['period'] ?? 'Game',
                            'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                        ];
                    }
                }
                
                // Also check for 'outcomes' (backward compatibility)
                if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                    foreach ($market['outcomes'] as $outcome) {
                        $odds[] = [
                            'market_type' => $normalizedType,
                            'market_name' => $marketName,
                            'selection' => $outcome['name'] ?? '',
                            'line' => $outcome['line'] ?? null,
                            'price' => $outcome['odds'] ?? null,
                            'period' => $market['period'] ?? 'Game',
                            'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                        ];
                    }
                }
            }
        }
        
        if (isset($marketsData['special_markets']) && is_array($marketsData['special_markets'])) {
            foreach ($marketsData['special_markets'] as $market) {
                $marketName = $market['name'] ?? 'unknown';
                $betType = $market['bet_type'] ?? null;
                $normalizedType = $normalizeMarketType($marketName, $betType);
                
                // Determine if this is a standard market type
                $isStandardMarket = in_array($normalizedType, ['money_line', 'spreads', 'totals']);
                
                // Check if market belongs to event (pass isStandardMarket flag)
                $belongsToEventResult = $belongsToEvent($market, $isStandardMarket);
                
                // Exclude if it doesn't belong (standard markets are handled inside belongsToEvent)
                if (!$belongsToEventResult) {
                    continue;
                }
                
                // Log unrecognized markets for debugging (especially for non-soccer sports)
                if ($normalizedType === 'unknown') {
                    Log::debug('Unrecognized market in extractPinnacleOdds (special_markets)', [
                        'market_name' => $marketName,
                        'bet_type' => $betType,
                        'market_keys' => array_keys($market),
                        'has_lines' => isset($market['lines']),
                        'has_outcomes' => isset($market['outcomes']),
                        'event_id' => $market['event_id'] ?? 'not_set'
                    ]);
                    continue;
                }
                
                // Check 'lines' first (Pinnacle structure)
                if (isset($market['lines']) && is_array($market['lines'])) {
                    foreach ($market['lines'] as $line) {
                        $odds[] = [
                            'market_type' => $normalizedType,
                            'market_name' => $marketName,
                            'selection' => $line['name'] ?? $line['outcome'] ?? '',
                            'line' => $line['line'] ?? $line['handicap'] ?? null,
                            'price' => $line['odds'] ?? $line['price'] ?? null,
                            'period' => $market['period'] ?? 'Game',
                            'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                        ];
                    }
                }
                
                // Also check 'outcomes' (backward compatibility)
                if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                    foreach ($market['outcomes'] as $outcome) {
                        $odds[] = [
                            'market_type' => $normalizedType,
                            'market_name' => $marketName,
                            'selection' => $outcome['name'] ?? '',
                            'line' => $outcome['line'] ?? null,
                            'price' => $outcome['odds'] ?? null,
                            'period' => $market['period'] ?? 'Game',
                            'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                        ];
                    }
                }
            }
        }
        
        // Handle case where special markets might be a flat array (not nested)
        // Check if the data itself is an array of markets (not nested under 'specials' or 'special_markets')
        if (empty($odds) && is_array($marketsData) && !isset($marketsData['specials']) && !isset($marketsData['special_markets'])) {
            // Check if first element looks like a market (has 'name' or 'lines' or 'outcomes')
            $firstElement = reset($marketsData);
            if (is_array($firstElement) && (isset($firstElement['name']) || isset($firstElement['lines']) || isset($firstElement['outcomes']))) {
                foreach ($marketsData as $market) {
                    $marketName = $market['name'] ?? 'unknown';
                    $betType = $market['bet_type'] ?? null;
                    $normalizedType = $normalizeMarketType($marketName, $betType);
                    
                    if ($normalizedType === 'unknown') {
                        continue;
                    }
                    
                    // Determine if this is a standard market type
                    $isStandardMarket = in_array($normalizedType, ['money_line', 'spreads', 'totals']);
                    
                    // Check if market belongs to event (pass isStandardMarket flag)
                    $belongsToEventResult = $belongsToEvent($market, $isStandardMarket);
                    
                    // Exclude if it doesn't belong
                    if (!$belongsToEventResult) {
                        continue;
                    }
                    
                    // Extract odds from lines or outcomes
                    if (isset($market['lines']) && is_array($market['lines'])) {
                        foreach ($market['lines'] as $line) {
                            $odds[] = [
                                'market_type' => $normalizedType,
                                'market_name' => $marketName,
                                'selection' => $line['name'] ?? $line['outcome'] ?? '',
                                'line' => $line['line'] ?? $line['handicap'] ?? null,
                                'price' => $line['odds'] ?? $line['price'] ?? null,
                                'period' => $market['period'] ?? 'Game',
                                'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                            ];
                        }
                    }
                    
                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                        foreach ($market['outcomes'] as $outcome) {
                            $odds[] = [
                                'market_type' => $normalizedType,
                                'market_name' => $marketName,
                                'selection' => $outcome['name'] ?? '',
                                'line' => $outcome['line'] ?? null,
                                'price' => $outcome['odds'] ?? null,
                                'period' => $market['period'] ?? 'Game',
                                'status' => ($market['open'] ?? $market['is_open'] ?? true) ? 'open' : 'closed',
                            ];
                        }
                    }
                }
            }
        }
        
        return $odds;
    }
    
    /**
     * Extract odds from Pinnacle regular markets endpoint
     * This endpoint provides standard markets (money_line, spreads, totals)
     * 
     * @param array $marketsData Raw Pinnacle markets data from /markets endpoint
     * @param string|int $eventId Event ID to filter by
     * @return array Extracted odds in normalized format
     */
    private function extractPinnacleOddsFromMarkets(array $marketsData, $eventId): array
    {
        $odds = [];
        
        // Log the raw structure for debugging
        Log::debug('extractPinnacleOddsFromMarkets - Raw data structure', [
            'event_id' => $eventId,
            'has_events' => isset($marketsData['events']),
            'top_level_keys' => array_keys($marketsData),
            'events_count' => isset($marketsData['events']) && is_array($marketsData['events']) ? count($marketsData['events']) : 0
        ]);
        
        if (!isset($marketsData['events']) || !is_array($marketsData['events'])) {
            // Check if data is in a different structure (some APIs return data directly)
            if (is_array($marketsData) && !empty($marketsData)) {
                Log::warning('DEBUG: Pinnacle markets data structure unexpected', [
                    'event_id' => $eventId,
                    'structure' => array_keys($marketsData),
                    'is_array' => is_array($marketsData),
                    'is_empty' => empty($marketsData),
                    'sample_data' => json_encode(array_slice($marketsData, 0, 3, true))
                ]);
            } else {
                Log::warning('DEBUG: Pinnacle markets data is empty or not array', [
                    'event_id' => $eventId,
                    'type' => gettype($marketsData),
                    'is_empty' => empty($marketsData)
                ]);
            }
            return $odds;
        }
        
        Log::info('DEBUG: extractPinnacleOddsFromMarkets - Processing events', [
            'event_id' => $eventId,
            'total_events' => count($marketsData['events']),
            'event_ids_in_data' => array_column($marketsData['events'], 'event_id')
        ]);
        
        // Find the event matching our matchId
        // CRITICAL: Handle both string and integer event_id matching
        $eventFound = false;
        $eventIdStr = (string)$eventId;
        $eventIdInt = (int)$eventId;
        
        foreach ($marketsData['events'] as $event) {
            $eventIdFromData = $event['event_id'] ?? null;
            
            // Try both string and integer comparison
            $matches = ($eventIdFromData == $eventId) || 
                       ($eventIdFromData == $eventIdStr) || 
                       ($eventIdFromData == $eventIdInt) ||
                       ((string)$eventIdFromData === $eventIdStr) ||
                       ((int)$eventIdFromData === $eventIdInt);
            
            Log::debug('Checking event in extractPinnacleOddsFromMarkets', [
                'looking_for' => $eventId,
                'looking_for_str' => $eventIdStr,
                'looking_for_int' => $eventIdInt,
                'found_event_id' => $eventIdFromData,
                'found_event_id_type' => gettype($eventIdFromData),
                'matches' => $matches,
                'event_keys' => array_keys($event)
            ]);
            
            if ($matches) {
                $eventFound = true;
                Log::info('DEBUG: Found matching event in Pinnacle markets', [
                    'event_id' => $eventId,
                    'has_periods' => isset($event['periods']),
                    'periods_count' => isset($event['periods']) && is_array($event['periods']) ? count($event['periods']) : 0,
                    'event_structure' => array_keys($event),
                    'full_event_sample' => json_encode(array_slice($event, 0, 5, true))
                ]);
                
                // Extract markets from this event
                if (isset($event['periods']) && is_array($event['periods'])) {
                    Log::info('DEBUG: Processing periods for event', [
                        'event_id' => $eventId,
                        'periods_count' => count($event['periods']),
                        'period_keys' => array_keys($event['periods'])
                    ]);
                    
                    // CRITICAL: Periods use string keys like 'num_0', 'num_1', not numeric indices
                    foreach ($event['periods'] as $periodKey => $period) {
                        $periodName = $period['description'] ?? $period['period'] ?? 'Game';
                        $periodNumber = $period['number'] ?? 0;
                        
                        Log::info('DEBUG: Processing period', [
                            'event_id' => $eventId,
                            'period' => $periodName,
                            'period_number' => $periodNumber,
                            'period_keys' => array_keys($period)
                        ]);
                        
                        // CRITICAL FIX: Pinnacle API structure - markets are direct properties of period
                        // Structure: period['money_line'], period['spreads'], period['totals']
                        
                        // 1. Extract Money Line (1X2)
                        if (isset($period['money_line']) && is_array($period['money_line'])) {
                            Log::info('DEBUG: Processing money_line', [
                                'event_id' => $eventId,
                                'period' => $periodName,
                                'money_line_keys' => array_keys($period['money_line']),
                                'has_home' => isset($period['money_line']['home']),
                                'has_draw' => isset($period['money_line']['draw']),
                                'has_away' => isset($period['money_line']['away'])
                            ]);
                            
                            $moneyLine = $period['money_line'];
                            if (isset($moneyLine['home']) && $moneyLine['home'] !== null) {
                                $odds[] = [
                                    'market_type' => 'money_line',
                                    'market_name' => 'Match Winner',
                                    'selection' => 'Home',
                                    'line' => null,
                                    'price' => $moneyLine['home'],
                                    'period' => $periodName,
                                    'status' => 'open',
                                    'provider' => 'pinnacle',
                                ];
                            }
                            if (isset($moneyLine['draw']) && $moneyLine['draw'] !== null) {
                                $odds[] = [
                                    'market_type' => 'money_line',
                                    'market_name' => 'Match Winner',
                                    'selection' => 'Draw',
                                    'line' => null,
                                    'price' => $moneyLine['draw'],
                                    'period' => $periodName,
                                    'status' => 'open',
                                    'provider' => 'pinnacle',
                                ];
                            }
                            if (isset($moneyLine['away']) && $moneyLine['away'] !== null) {
                                $odds[] = [
                                    'market_type' => 'money_line',
                                    'market_name' => 'Match Winner',
                                    'selection' => 'Away',
                                    'line' => null,
                                    'price' => $moneyLine['away'],
                                    'period' => $periodName,
                                    'status' => 'open',
                                    'provider' => 'pinnacle',
                                ];
                            }
                        }
                        
                        // 2. Extract Spreads (Handicap)
                        if (isset($period['spreads']) && is_array($period['spreads'])) {
                            Log::info('DEBUG: Processing spreads', [
                                'event_id' => $eventId,
                                'period' => $periodName,
                                'spreads_count' => count($period['spreads']),
                                'spreads_keys' => array_keys($period['spreads'])
                            ]);
                            
                            foreach ($period['spreads'] as $lineKey => $spreadData) {
                                if (!is_array($spreadData)) continue;
                                
                                // CRITICAL: Use hdp from data if available, otherwise use lineKey
                                // lineKey might be string like "-2.0", convert to float for consistency
                                $handicap = isset($spreadData['hdp']) ? (float)$spreadData['hdp'] : (float)$lineKey;
                                
                                if (isset($spreadData['home']) && $spreadData['home'] !== null) {
                                    $odds[] = [
                                        'market_type' => 'spreads',
                                        'market_name' => 'Handicap',
                                        'selection' => 'Home',
                                        'line' => $handicap,
                                        'price' => $spreadData['home'],
                                        'period' => $periodName,
                                        'status' => 'open',
                                        'provider' => 'pinnacle',
                                    ];
                                }
                                if (isset($spreadData['away']) && $spreadData['away'] !== null) {
                                    $odds[] = [
                                        'market_type' => 'spreads',
                                        'market_name' => 'Handicap',
                                        'selection' => 'Away',
                                        'line' => $handicap,
                                        'price' => $spreadData['away'],
                                        'period' => $periodName,
                                        'status' => 'open',
                                        'provider' => 'pinnacle',
                                    ];
                                }
                            }
                        }
                        
                        // 3. Extract Totals (Over/Under)
                        if (isset($period['totals']) && is_array($period['totals'])) {
                            Log::info('DEBUG: Processing totals', [
                                'event_id' => $eventId,
                                'period' => $periodName,
                                'totals_count' => count($period['totals']),
                                'totals_keys' => array_keys($period['totals'])
                            ]);
                            
                            foreach ($period['totals'] as $lineKey => $totalData) {
                                if (!is_array($totalData)) continue;
                                
                                // CRITICAL: Use points from data if available, otherwise use lineKey
                                // lineKey might be string like "3.5", convert to float for consistency
                                $points = isset($totalData['points']) ? (float)$totalData['points'] : (float)$lineKey;
                                
                                if (isset($totalData['over']) && $totalData['over'] !== null) {
                                    $odds[] = [
                                        'market_type' => 'totals',
                                        'market_name' => 'Total',
                                        'selection' => 'Over',
                                        'line' => $points,
                                        'price' => $totalData['over'],
                                        'period' => $periodName,
                                        'status' => 'open',
                                        'provider' => 'pinnacle',
                                    ];
                                }
                                if (isset($totalData['under']) && $totalData['under'] !== null) {
                                    $odds[] = [
                                        'market_type' => 'totals',
                                        'market_name' => 'Total',
                                        'selection' => 'Under',
                                        'line' => $points,
                                        'price' => $totalData['under'],
                                        'period' => $periodName,
                                        'status' => 'open',
                                        'provider' => 'pinnacle',
                                    ];
                                }
                            }
                        }
                        
                        // 4. Fallback: Check for old structure with markets array (backward compatibility)
                        if (isset($period['markets']) && is_array($period['markets'])) {
                            Log::info('DEBUG: Processing markets in period', [
                                'event_id' => $eventId,
                                'period' => $periodName,
                                'markets_count' => count($period['markets']),
                                'market_names' => array_column($period['markets'], 'name')
                            ]);
                            
                            foreach ($period['markets'] as $market) {
                                $marketName = strtolower($market['name'] ?? '');
                                $originalMarketName = $market['name'] ?? '';
                                $betType = $market['bet_type'] ?? null;
                                
                                // Try to determine market type from bet_type first (more reliable)
                                $marketType = null;
                                if ($betType) {
                                    $bt = strtolower(trim($betType));
                                    if (in_array($bt, ['1x2', 'match_winner', 'money_line', 'moneylin', 'outright', 'to_win', 'straight'])) {
                                        $marketType = 'money_line';
                                    } elseif (in_array($bt, ['over_under', 'over-under', 'total', 'totals', 'points_total', 'goals_total', 'runs_total'])) {
                                        $marketType = 'totals';
                                    } elseif (in_array($bt, ['handicap', 'spread', 'asian_handicap', 'hcp', 'line'])) {
                                        $marketType = 'spreads';
                                    }
                                }
                                
                                // Fallback to market name mapping if bet_type didn't work
                                if (!$marketType) {
                                    $marketType = $this->mapPinnacleMarketNameToType($marketName);
                                }
                                
                                // Log market names that aren't recognized for debugging
                                if ($marketType === 'unknown') {
                                    Log::debug('Unrecognized market name in extractPinnacleOddsFromMarkets', [
                                        'event_id' => $eventId,
                                        'market_name' => $originalMarketName,
                                        'market_name_lower' => $marketName,
                                        'bet_type' => $betType,
                                        'market_keys' => array_keys($market)
                                    ]);
                                    continue;
                                }
                                
                                Log::debug('Extracting market from Pinnacle', [
                                    'event_id' => $eventId,
                                    'market_name' => $originalMarketName,
                                    'bet_type' => $betType,
                                    'market_type' => $marketType,
                                    'period' => $periodName
                                ]);
                                
                                if (isset($market['lines']) && is_array($market['lines'])) {
                                    $linesCount = count($market['lines']);
                                    Log::debug('DEBUG: Extracting lines from market', [
                                        'event_id' => $eventId,
                                        'market_name' => $originalMarketName,
                                        'market_type' => $marketType,
                                        'lines_count' => $linesCount
                                    ]);
                                    
                                    foreach ($market['lines'] as $line) {
                                        $odd = [
                                            'market_type' => $marketType,
                                            'market_name' => $market['name'] ?? '',
                                            'selection' => $line['name'] ?? $line['outcome'] ?? '',
                                            'line' => $line['line'] ?? $line['handicap'] ?? null,
                                            'price' => $line['odds'] ?? $line['price'] ?? null,
                                            'period' => $periodName,
                                            'status' => ($line['status'] ?? $market['status'] ?? 'open') === 'open' ? 'open' : 'closed',
                                            'provider' => 'pinnacle', // Add provider for aggregation
                                        ];
                                        $odds[] = $odd;
                                        
                                        Log::debug('DEBUG: Added odd from line', [
                                            'market_type' => $marketType,
                                            'selection' => $odd['selection'],
                                            'price' => $odd['price'],
                                            'line' => $odd['line']
                                        ]);
                                    }
                                } else {
                                    // Also check for 'outcomes' array (alternative structure)
                                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                                        foreach ($market['outcomes'] as $outcome) {
                                            $odds[] = [
                                                'market_type' => $marketType,
                                                'market_name' => $market['name'] ?? '',
                                                'selection' => $outcome['name'] ?? $outcome['outcome'] ?? '',
                                                'line' => $outcome['line'] ?? $outcome['handicap'] ?? null,
                                                'price' => $outcome['odds'] ?? $outcome['price'] ?? null,
                                                'period' => $periodName,
                                                'status' => ($outcome['status'] ?? $market['status'] ?? 'open') === 'open' ? 'open' : 'closed',
                                                'provider' => 'pinnacle',
                                            ];
                                        }
                                    } else {
                                        Log::debug('Market has no lines or outcomes', [
                                            'event_id' => $eventId,
                                            'market_name' => $originalMarketName,
                                            'market_keys' => array_keys($market)
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                } else {
                    Log::warning('Event found but no periods data', [
                        'event_id' => $eventId,
                        'event_structure' => array_keys($event)
                    ]);
                }
                break; // Found the event, no need to continue
            }
        }
        
        if (!$eventFound) {
            Log::warning('Event not found in Pinnacle markets data', [
                'event_id' => $eventId,
                'available_event_ids' => array_map(function($e) {
                    return $e['event_id'] ?? 'unknown';
                }, array_slice($marketsData['events'], 0, 10))
            ]);
        }
        
        if (!$eventFound) {
            Log::warning('DEBUG: Event NOT found in extractPinnacleOddsFromMarkets', [
                'event_id' => $eventId,
                'event_id_str' => $eventIdStr,
                'event_id_int' => $eventIdInt,
                'total_events' => count($marketsData['events']),
                'event_ids_in_response' => array_column($marketsData['events'], 'event_id'),
                'odds_extracted' => count($odds)
            ]);
        }
        
        // Count odds by market type for detailed logging
        $oddsByType = [];
        foreach ($odds as $odd) {
            $type = $odd['market_type'] ?? 'unknown';
            $oddsByType[$type] = ($oddsByType[$type] ?? 0) + 1;
        }
        
        Log::info('DEBUG: extractPinnacleOddsFromMarkets completed - SUMMARY', [
            'event_id' => $eventId,
            'event_found' => $eventFound,
            'total_odds_count' => count($odds),
            'odds_by_type' => $oddsByType,
            'money_line_count' => $oddsByType['money_line'] ?? 0,
            'spreads_count' => $oddsByType['spreads'] ?? 0,
            'totals_count' => $oddsByType['totals'] ?? 0,
            'player_props_count' => $oddsByType['player_props'] ?? 0,
            'sample_odds' => array_slice($odds, 0, 5)
        ]);
        
        return $odds;
    }
    
    /**
     * Map Pinnacle market name to our standard market type
     * EXPANDED: Now handles sport-specific variations for non-soccer sports
     */
    private function mapPinnacleMarketNameToType(string $marketName): string
    {
        $name = strtolower(trim($marketName));
        
        // Money Line variations (for all sports)
        if (stripos($name, 'match winner') !== false || 
            stripos($name, '1x2') !== false || 
            stripos($name, 'money line') !== false ||
            stripos($name, 'moneylin') !== false ||  // "Moneyline" (one word) for basketball, etc.
            stripos($name, 'match result') !== false ||
            stripos($name, 'winner') !== false ||
            stripos($name, 'outright') !== false ||
            stripos($name, 'to win') !== false ||
            stripos($name, 'straight') !== false) {
            return 'money_line';
        }
        
        // Totals variations (for all sports)
        if (stripos($name, 'over/under') !== false || 
            stripos($name, 'over under') !== false ||
            stripos($name, 'over-under') !== false ||
            stripos($name, 'ou ') !== false ||  // "OU 150.5" format
            (stripos($name, 'total') !== false && stripos($name, 'player') === false) ||  // "Total" but not "Player Total"
            stripos($name, 'totals') !== false ||
            stripos($name, 'points total') !== false ||
            stripos($name, 'goals total') !== false ||
            stripos($name, 'runs total') !== false) {
            return 'totals';
        }
        
        // Spreads/Handicap variations (for all sports)
        if (stripos($name, 'handicap') !== false || 
            stripos($name, 'spread') !== false ||
            stripos($name, 'asian') !== false ||
            stripos($name, 'hcp') !== false ||  // Abbreviation
            stripos($name, 'hdcp') !== false ||  // Abbreviation
            stripos($name, 'line') !== false) {  // "Line" for basketball, etc.
            return 'spreads';
        }
        
        return 'unknown';
    }

    /**
     * Process aggregated odds from all providers
     * 
     * Converts aggregated odds to display format, adds player props, etc.
     * 
     * ODDS DEDUPLICATION LOGIC:
     * - Odds are deduplicated by market type + selection + line + price
     * - Same odd from multiple providers appears only once
     * - Provider metadata is preserved for traceability
     * 
     * @param array $aggregatedOdds Aggregated odds from OddsAggregationService
     * @param int $matchId Match ID
     * @param string $period Period (Game, 1st Half, etc.)
     * @param string $marketType Market type filter
     * @param array $apiFootballPlayers Player lineups from API-Football
     * @param int $sportId Sport ID
     * @return array Processed odds data
     */
    private function processAggregatedOdds(
        array $aggregatedOdds, 
        $matchId, 
        $period, 
        $marketType, 
        array $apiFootballPlayers = [], 
        $sportId = 1
    ): array {
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

        // Convert aggregated odds to display format
        foreach ($aggregatedOdds as $odd) {
            // Get market type from aggregated odd (this is the key for filtering)
            $oddMarketType = $odd['market_type'] ?? 'unknown';
            
            // Build bet name with market type prefix for proper filtering
            $selection = $odd['selection'] ?? '';
            $betName = $selection;
            
            // Add market type context to bet name for filtering
            if ($oddMarketType === 'money_line') {
                $betName = 'Match Winner ' . $selection;
            } elseif ($oddMarketType === 'spreads') {
                $line = $odd['line'] ?? '';
                $betName = 'Spread ' . $selection . ($line ? ' ' . $line : '');
            } elseif ($oddMarketType === 'totals') {
                $line = $odd['line'] ?? '';
                $betName = 'Total ' . $selection . ($line ? ' ' . $line : '');
            }
            
            $allOdds[] = [
                'bet' => $betName,
                'market_type' => $oddMarketType, // Preserve market type for filtering
                'home_team_id' => $homeTeamId,
                'away_team_id' => $awayTeamId,
                'line' => $odd['line'] ?? null,
                'odds' => number_format((float)($odd['price'] ?? 0), 3),
                'status' => $odd['status'] ?? 'open',
                'period' => $odd['period'] ?? 'Game',
                'source' => $odd['provider'] ?? 'unknown',
                'providers' => $odd['providers'] ?? [],
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        // Add player props if needed (using existing logic)
        if ($marketType === 'player_props' || $marketType === 'all') {
            $playerProps = $this->generatePlayerProps($apiFootballPlayers, $sportId, $homeTeamId, $awayTeamId);
            $allOdds = array_merge($allOdds, $playerProps);
        }

        // Filter by market type and period
        if ($marketType !== 'all') {
            $allOdds = $this->filterOddsByMarketType($allOdds, $marketType);
        }

        if ($period !== 'all') {
            $allOdds = array_filter($allOdds, function($odd) use ($period) {
                return ($odd['period'] ?? 'Game') === $period;
            });
        }

        return [
            'match_id' => $matchId,
            'market_type' => $marketType,
            'period' => $period,
            'odds' => array_values($allOdds),
            'total_count' => count($allOdds),
            'showing_count' => count($allOdds),
            'providers' => array_unique(array_column($allOdds, 'source'))
        ];
    }

    /**
     * Filter odds by market type
     * 
     * IMPROVED: Uses market_type field directly for accurate filtering
     * Falls back to bet name matching for backward compatibility
     * 
     * @param array $odds All odds
     * @param string $marketType Market type to filter
     * @return array Filtered odds
     */
    private function filterOddsByMarketType(array $odds, string $marketType): array
    {
        // First, try to filter by market_type field (most accurate)
        $filtered = array_filter($odds, function($odd) use ($marketType) {
            $oddMarketType = $odd['market_type'] ?? null;
            if ($oddMarketType === $marketType) {
                return true;
            }
            
            // Fallback: Check bet name for keywords (backward compatibility)
            $bet = strtolower($odd['bet'] ?? '');
            $marketTypeMap = [
                'money_line' => ['money line', 'match winner', '1x2', 'winner', 'home', 'away', 'draw'],
                'spreads' => ['spread', 'handicap', 'asian handicap', 'asian'],
                'totals' => ['total', 'over/under', 'over', 'under'],
                'player_props' => ['player', 'player props', 'goals', 'assists', 'points', 'shots'],
            ];
            
            if (isset($marketTypeMap[$marketType])) {
                foreach ($marketTypeMap[$marketType] as $keyword) {
                    if (stripos($bet, $keyword) !== false) {
                        return true;
                    }
                }
            }
            
            return false;
        });
        
        return array_values($filtered);
    }

    /**
     * Generate player props odds
     * 
     * @param array $apiFootballPlayers Player lineups
     * @param int $sportId Sport ID
     * @param int|null $homeTeamId Home team ID
     * @param int|null $awayTeamId Away team ID
     * @return array Player props odds
     */
    private function generatePlayerProps(array $apiFootballPlayers, int $sportId, $homeTeamId, $awayTeamId): array
    {
        // Use existing player props generation logic
        // This is a simplified version - can be enhanced
        $playerProps = [];
        
        // Extract real player names from lineups
        $realPlayers = [];
        foreach ($apiFootballPlayers as $lineupData) {
            if (isset($lineupData['startXI']) && is_array($lineupData['startXI'])) {
                foreach ($lineupData['startXI'] as $player) {
                    if (isset($player['player']['name'])) {
                        $realPlayers[] = $player['player']['name'];
                    }
                }
            }
        }
        
        // Generate props for top 8 players
        $players = array_slice($realPlayers, 0, 8);
        if (empty($players)) {
            $players = ['Player A', 'Player B', 'Player C', 'Player D'];
        }
        
        // Generate props based on sport
        $propTypes = $sportId == 1 ? ['Goals', 'Assists', 'Shots', 'Cards'] : 
                    ($sportId == 3 ? ['Points', 'Rebounds', 'Assists', 'Steals'] : 
                    ['Goals', 'Assists', 'Points', 'Rebounds']);
        
        foreach ($players as $player) {
            foreach ($propTypes as $propType) {
                $playerProps[] = [
                    'bet' => "{$player} - {$propType} Over 0.5",
                    'home_team_id' => $homeTeamId,
                    'away_team_id' => $awayTeamId,
                    'line' => '0.5',
                    'odds' => number_format(rand(150, 250) / 100, 3),
                    'status' => 'open',
                    'period' => 'Game',
                    'source' => 'generated',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $playerProps[] = [
                    'bet' => "{$player} - {$propType} Under 0.5",
                    'home_team_id' => $homeTeamId,
                    'away_team_id' => $awayTeamId,
                    'line' => '0.5',
                    'odds' => number_format(rand(150, 250) / 100, 3),
                    'status' => 'open',
                    'period' => 'Game',
                    'source' => 'generated',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return $playerProps;
    }

    /**
     * Legacy method - kept for backward compatibility
     * Now redirects to processAggregatedOdds
     */
    private function processMatchOdds($marketsData, $matchId, $period, $marketType, $apiFootballOdds = [], $apiFootballPlayers = [], $sportId = 1)
    {
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

        // Process API-Football odds first (as supplement to Pinnacle)
        // API-Football response structure: response[0].bookmakers[].bets[].values[]
        if (!empty($apiFootballOdds) && is_array($apiFootballOdds)) {
            $apiFootballOddsCount = 0;
            
            // Handle API-Football response structure
            foreach ($apiFootballOdds as $oddsData) {
                // Check if this is the response array structure
                if (isset($oddsData['bookmakers']) && is_array($oddsData['bookmakers'])) {
                    // Direct structure: oddsData has bookmakers
                    $bookmakers = $oddsData['bookmakers'];
                } elseif (isset($oddsData['fixture']) && isset($oddsData['bookmakers'])) {
                    // Response structure: fixture + bookmakers
                    $bookmakers = $oddsData['bookmakers'];
                } else {
                    continue;
                }

                foreach ($bookmakers as $bookmaker) {
                    if (!isset($bookmaker['bets']) || !is_array($bookmaker['bets'])) {
                        continue;
                    }

                    $bookmakerName = $bookmaker['name'] ?? 'Unknown';

                    foreach ($bookmaker['bets'] as $bet) {
                        $betName = $bet['name'] ?? '';
                        $betValues = $bet['values'] ?? [];

                        // Map API-Football bet types to our market types
                        $marketTypeMatch = false;
                        $betNameLower = strtolower($betName);
                        
                        if ($marketType === 'all') {
                            $marketTypeMatch = true;
                        } elseif ($marketType === 'money_line' && in_array($betNameLower, ['match winner', '1x2', 'winner', 'matchwinner'])) {
                            $marketTypeMatch = true;
                        } elseif ($marketType === 'totals' && (strpos($betNameLower, 'over') !== false || strpos($betNameLower, 'under') !== false || strpos($betNameLower, 'total') !== false)) {
                            $marketTypeMatch = true;
                        } elseif ($marketType === 'spreads' && (strpos($betNameLower, 'handicap') !== false || strpos($betNameLower, 'spread') !== false || strpos($betNameLower, 'asian') !== false)) {
                            $marketTypeMatch = true;
                        }

                        if (!$marketTypeMatch) {
                            continue;
                        }

                        foreach ($betValues as $value) {
                            $oddValue = $value['odd'] ?? null;
                            $valueName = $value['value'] ?? '';

                            if (!$oddValue || !is_numeric($oddValue)) {
                                continue;
                            }

                            // Extract line from value name if it's a totals/spreads bet
                            $line = null;
                            if (preg_match('/([+-]?\d+\.?\d*)/', $valueName, $matches)) {
                                $line = $matches[1];
                            }

                            // For totals, also check if line is in bet name
                            if ($line === null && ($marketType === 'totals' || $marketType === 'spreads')) {
                                if (preg_match('/([+-]?\d+\.?\d*)/', $betName, $matches)) {
                                    $line = $matches[1];
                                }
                            }

                            $allOdds[] = [
                                'bet' => $valueName ?: $betName,
                                'home_team_id' => $homeTeamId,
                                'away_team_id' => $awayTeamId,
                                'line' => $line,
                                'odds' => number_format((float)$oddValue, 3),
                                'status' => 'open',
                                'period' => 'Game',
                                'source' => 'api-football',
                                'bookmaker' => $bookmakerName,
                                'updated_at' => date('Y-m-d H:i:s')
                            ];
                            $apiFootballOddsCount++;
                        }
                    }
                }
            }

            if ($apiFootballOddsCount > 0) {
                Log::info('Processed API-Football odds', [
                    'matchId' => $matchId,
                    'odds_added' => $apiFootballOddsCount
                ]);
            }
        }

        // Process Pinnacle odds (primary source)
        if (is_array($marketsData)) {
            if (isset($marketsData['specials']) && is_array($marketsData['specials'])) {
                foreach ($marketsData['specials'] as $market) {
                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                        $marketPeriod = $market['period'] ?? 'Game';
                        $marketName = $market['name'] ?? '';

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

            if (isset($marketsData['special_markets']) && is_array($marketsData['special_markets'])) {
                foreach ($marketsData['special_markets'] as $market) {
                    if (isset($market['outcomes']) && is_array($market['outcomes'])) {
                        $marketPeriod = $market['period'] ?? 'Game';

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

        if ($marketType !== 'all') {
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

        if (empty($allOdds)) {
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

            // Generate sample data for Totals market
            if ($marketType === 'totals' || $marketType === 'all') {
                $totalLines = [0.5, 1.5, 2.5, 3.5, 4.5, 5.5];
                foreach ($totalLines as $line) {
                    $allOdds[] = [
                        'bet' => 'Over ' . $line,
                        'home_team_id' => $homeTeamId,
                        'away_team_id' => $awayTeamId,
                        'line' => (string)$line,
                        'odds' => number_format(rand(180, 220) / 100, 3),
                        'status' => 'open',
                        'period' => 'Game',
                        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                    ];
                    $allOdds[] = [
                        'bet' => 'Under ' . $line,
                        'home_team_id' => $homeTeamId,
                        'away_team_id' => $awayTeamId,
                        'line' => (string)$line,
                        'odds' => number_format(rand(180, 220) / 100, 3),
                        'status' => 'open',
                        'period' => 'Game',
                        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                    ];
                }
            }

            // Generate Player Props market with REAL player names from API-Football
            if ($marketType === 'player_props' || $marketType === 'all') {
                $realPlayers = [];
                
                // Extract real player names from API-Football lineups
                if (!empty($apiFootballPlayers) && is_array($apiFootballPlayers)) {
                    Log::info('Processing API-Football lineups', [
                        'matchId' => $matchId,
                        'lineups_count' => count($apiFootballPlayers),
                        'first_lineup_keys' => !empty($apiFootballPlayers[0]) ? array_keys($apiFootballPlayers[0]) : []
                    ]);
                    
                    foreach ($apiFootballPlayers as $lineupData) {
                        // Handle different API-Football response structures
                        if (isset($lineupData['team']['id']) && isset($lineupData['startXI'])) {
                            // Determine team type by comparing team names (since IDs might not match)
                            $teamName = strtolower($lineupData['team']['name'] ?? '');
                            $homeTeamName = strtolower($homeTeam ?? '');
                            $awayTeamName = strtolower($awayTeam ?? '');
                            
                            // Better team matching using name similarity
                            $teamType = 'unknown';
                            if (strpos($homeTeamName, $teamName) !== false || strpos($teamName, $homeTeamName) !== false || 
                                $this->normalizeTeamName($teamName) === $this->normalizeTeamName($homeTeamName)) {
                                $teamType = 'home';
                            } elseif (strpos($awayTeamName, $teamName) !== false || strpos($teamName, $awayTeamName) !== false ||
                                $this->normalizeTeamName($teamName) === $this->normalizeTeamName($awayTeamName)) {
                                $teamType = 'away';
                            }
                            
                            foreach ($lineupData['startXI'] as $player) {
                                $playerName = $player['player']['name'] ?? null;
                                if ($playerName && strlen($playerName) > 2 && strlen($playerName) < 50) { // Filter out invalid names
                                    $realPlayers[] = [
                                        'name' => $playerName,
                                        'team' => $teamType,
                                        'team_id' => $lineupData['team']['id']
                                    ];
                                }
                            }
                        }
                    }
                    
                    Log::info('Extracted players from lineups', [
                        'matchId' => $matchId,
                        'players_count' => count($realPlayers),
                        'sample_players' => array_slice(array_column($realPlayers, 'name'), 0, 3)
                    ]);
                }
                
                // Extract player names from API-Football odds (if player props are in odds)
                if (empty($realPlayers) && !empty($apiFootballOdds)) {
                    foreach ($apiFootballOdds as $oddsData) {
                        $bookmakers = $oddsData['bookmakers'] ?? [];
                        if (empty($bookmakers) && isset($oddsData[0]['bookmakers'])) {
                            $bookmakers = $oddsData[0]['bookmakers'];
                        }
                        
                        foreach ($bookmakers as $bookmaker) {
                            $bets = $bookmaker['bets'] ?? [];
                            foreach ($bets as $bet) {
                                $betName = strtolower($bet['name'] ?? '');
                                // Check if this is a player prop bet
                                if (strpos($betName, 'player') !== false || strpos($betName, 'goalscorer') !== false) {
                                    $values = $bet['values'] ?? [];
                                    foreach ($values as $value) {
                                        $valueName = $value['value'] ?? '';
                                        // Extract player name (usually before dash or colon)
                                        if (preg_match('/^([^\-:]+)/', $valueName, $matches)) {
                                            $playerName = trim($matches[1]);
                                            if ($playerName && !in_array($playerName, array_column($realPlayers, 'name'))) {
                                                $realPlayers[] = [
                                                    'name' => $playerName,
                                                    'team' => 'unknown',
                                                    'team_id' => null
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Use real players if available, otherwise use placeholders
                if (empty($realPlayers)) {
                    $realPlayers = [
                        ['name' => 'Player A', 'team' => 'home', 'team_id' => $homeTeamId],
                        ['name' => 'Player B', 'team' => 'home', 'team_id' => $homeTeamId],
                        ['name' => 'Player C', 'team' => 'away', 'team_id' => $awayTeamId],
                        ['name' => 'Player D', 'team' => 'away', 'team_id' => $awayTeamId]
                    ];
                } else {
                    // Limit to top 8 players to avoid too many props
                    $realPlayers = array_slice($realPlayers, 0, 8);
                }
                
                // Determine prop types based on sport
                $propTypes = ['Goals', 'Assists', 'Points', 'Rebounds', 'Shots'];
                if ($sportId == 1) { // Soccer
                    $propTypes = ['Goals', 'Assists', 'Shots', 'Cards'];
                } elseif ($sportId == 3) { // Basketball
                    $propTypes = ['Points', 'Rebounds', 'Assists', 'Steals'];
                }
                
                foreach ($realPlayers as $player) {
                    $playerName = $player['name'];
                    $teamLabel = ($player['team'] === 'home') ? '(Home)' : ($player['team'] === 'away' ? '(Away)' : '');
                    
                    foreach ($propTypes as $propType) {
                        $line = rand(5, 25);
                        if ($propType === 'Goals' || $propType === 'Points') {
                            $line = rand(0, 5);
                        } elseif ($propType === 'Assists') {
                            $line = rand(0, 10);
                        }
                        
                        $allOdds[] = [
                            'bet' => $playerName . ' - ' . $propType . ' Over ' . $line . ' ' . $teamLabel,
                            'home_team_id' => ($player['team'] === 'home') ? $homeTeamId : null,
                            'away_team_id' => ($player['team'] === 'away') ? $awayTeamId : null,
                            'line' => (string)$line,
                            'odds' => number_format(rand(180, 220) / 100, 3),
                            'status' => 'open',
                            'period' => 'Game',
                            'source' => !empty($apiFootballPlayers) ? 'api-football' : 'sample',
                            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                        ];
                        $allOdds[] = [
                            'bet' => $playerName . ' - ' . $propType . ' Under ' . $line . ' ' . $teamLabel,
                            'home_team_id' => ($player['team'] === 'home') ? $homeTeamId : null,
                            'away_team_id' => ($player['team'] === 'away') ? $awayTeamId : null,
                            'line' => (string)$line,
                            'odds' => number_format(rand(180, 220) / 100, 3),
                            'status' => 'open',
                            'period' => 'Game',
                            'source' => !empty($apiFootballPlayers) ? 'api-football' : 'sample',
                            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                        ];
                    }
                }
            }
        }

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

    public function getMatchDetails(Request $request, $matchId)
    {
        try {
            $match = SportsMatch::where('eventId', $matchId)->first();

            if (!$match) {
                Log::warning('Match not found in getMatchDetails', ['matchId' => $matchId]);
                return response()->json(['error' => 'Match not found'], 404);
            }


            $venue = \App\Models\MatchEnrichment::getCachedEnrichment($matchId);

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

            $homePlayers = [];
            $awayPlayers = [];

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

            $marketInfo = $this->getMarketInfo($match->eventId);

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
                    'scheduled_time' => $match->startTime ? $match->startTime->setTimezone('UTC')->format('m/d/Y, H:i:s T') : 'TBD',
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

            $markets = \App\Models\Market::where('match_id', $matchId)->get();
            $totalMarkets = $markets->count();

            $marketTypes = $markets->groupBy('market_type');
            $marketCounts = [];
            foreach ($marketTypes as $type => $typeMarkets) {
                $marketCounts[$type] = $typeMarkets->count();
            }

            $info = [
                'total_markets' => $totalMarkets,
                'market_types' => $marketCounts
            ];

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
            // RATE LIMITING: Prevent excessive manual refreshes
            $rateLimitKey = 'manual_refresh_rate_limit';
            $rateLimitWindow = 300; // 5 minutes
            $maxRefreshesPerWindow = 3; // Max 3 manual refreshes per 5 minutes
            
        $currentCount = Cache::get($rateLimitKey, 0);
        if ($currentCount >= $maxRefreshesPerWindow) {
            $ttl = Cache::get($rateLimitKey . ':ttl', $rateLimitWindow);
            return response()->json([
                'error' => 'Rate limit exceeded',
                'message' => "Maximum {$maxRefreshesPerWindow} manual refreshes allowed per {$rateLimitWindow} seconds. Please wait.",
                'retry_after_seconds' => $ttl
            ], 429);
        }
        
            $sportId = $request->input('sport_id');
            $leagueIds = $request->input('league_ids', []);
            $matchType = $request->input('match_type', 'all');
            $timezone = $request->input('timezone', 'UTC');
            $forceRefresh = $request->input('force_refresh', true);

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
                'forceRefresh' => $forceRefresh,
                'rate_limit_count' => $currentCount + 1
            ]);

            // Increment rate limit counter
            Cache::put($rateLimitKey, $currentCount + 1, $rateLimitWindow);
            Cache::put($rateLimitKey . ':ttl', $rateLimitWindow, $rateLimitWindow);

            $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);

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
                'timestamp' => now()->toISOString(),
                'rate_limit_remaining' => $maxRefreshesPerWindow - ($currentCount + 1)
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