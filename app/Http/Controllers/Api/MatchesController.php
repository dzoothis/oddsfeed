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
use DateTimeZone;
use Exception;

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
            $matchType = $request->input('match_type', 'all');
            $timezone = $request->input('timezone', 'UTC'); // Default to UTC if not provided

            // Validate required parameters
            if (!$sportId) {
                return response()->json(['error' => 'sport_id is required'], 400);
            }

            if (!in_array($matchType, ['live', 'prematch', 'available_for_betting', 'all'])) {
                return response()->json(['error' => 'match_type must be one of: live, prematch, available_for_betting, all'], 400);
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
                ->where('sportId', $sportId)
                ->where('lastUpdated', '>', $utcNow->copy()->subHours(24));
            
            // Note: Sorting will be applied after matchType conditions are set

            if (!empty($leagueIds)) {
                $databaseLeagueIds = $this->convertPinnacleIdsToDatabaseIds($leagueIds);
                $query->whereIn('leagueId', $databaseLeagueIds);
            }

            if ($matchType === 'live') {
                $query->where('startTime', '<=', $utcNow)
                      ->where('startTime', '!=', null)
                      ->where(function($q) {
                          $q->where('live_status_id', '>', 0)
                            ->orWhere('home_score', '>', 0)
                            ->orWhere('away_score', '>', 0);
                      })
                      // Exclude matches that are likely finished:
                      // 1. Matches that started more than 4 hours ago AND haven't been updated in the last 30 minutes
                      // 2. Matches that started more than 3 hours ago AND have scores but no recent updates
                      ->where(function($q) use ($utcNow) {
                          $q->where(function($subQ) use ($utcNow) {
                              // Matches updated recently (within last 30 minutes) are considered active
                              $subQ->where('lastUpdated', '>', $utcNow->copy()->subMinutes(30));
                          })->orWhere(function($subQ) use ($utcNow) {
                              // OR matches that started within the last 3 hours (likely still live)
                              $subQ->where('startTime', '>', $utcNow->copy()->subHours(3));
                          })->orWhere(function($subQ) use ($utcNow) {
                              // OR matches with live_status_id = 1 (confirmed live by Pinnacle) that started within last 4 hours
                              $subQ->where('live_status_id', '=', 1)
                                   ->where('startTime', '>', $utcNow->copy()->subHours(4));
                          });
                      });
            } elseif ($matchType === 'prematch') {
                $query->where('eventType', 'prematch')
                      ->where('betting_availability', '!=', 'available_for_betting')
                      ->where('startTime', '>', $utcNow)
                      ->whereRaw('DATE(startTime) <= DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))');
            } elseif ($matchType === 'available_for_betting') {
                $query->where('betting_availability', 'available_for_betting')
                      ->where('live_status_id', '!=', -1)
                      ->where('live_status_id', '=', 0)
                      ->where('startTime', '>', $utcNow);
            }

            $query->where('live_status_id', '!=', -1)
                  ->where('live_status_id', '!=', 2);

            if ($matchType === 'all') {
                $query->where(function($q) use ($utcNow) {
                    // Include live matches (regardless of age)
                    $q->where(function($liveQ) use ($utcNow) {
                        $liveQ->where('live_status_id', '>', 0)
                              ->where('startTime', '<=', $utcNow); // Live matches should not be in the future (timezone-aware)
                    })
                    // OR include prematch matches with open markets within 2 days
                    ->orWhere(function($subQ) use ($utcNow) {
                        $subQ->where('hasOpenMarkets', true)
                             ->whereRaw('DATE(startTime) <= DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))')
                             ->where('startTime', '>', $utcNow); // Future matches only (timezone-aware)
                    });
                });
            }

            // Only apply 150-minute filter to prematch matches, not live matches
            if ($matchType !== 'live' && $matchType !== 'all') {
                $query->whereRaw('(startTime IS NULL OR startTime > DATE_SUB(NOW(), INTERVAL 150 MINUTE))');
            }

            // Apply sorting based on match type
            // For live matches: Most recent first (most recently updated, then most recently started)
            // For prematch: Earliest matches first
            if ($matchType === 'live') {
                $query->orderBy('lastUpdated', 'desc')
                      ->orderBy('startTime', 'desc');
            } elseif ($matchType === 'all') {
                // For 'all': Live matches first (most recent), then prematch (earliest)
                $query->orderByRaw('CASE WHEN live_status_id > 0 THEN 0 ELSE 1 END') // Live matches first
                      ->orderBy('lastUpdated', 'desc') // Most recently updated first
                      ->orderBy('startTime', 'desc'); // Most recently started first
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
                'match_type' => $match->match_type ?? $match->eventType,
                'betting_availability' => $isLiveVisible ? 'live' : ($match->betting_availability ?? 'prematch'),
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

        if ($matchType === 'available_for_betting') {
            $matches = array_filter($matches, function ($m) {
                return (isset($m['betting_availability']) && $m['betting_availability'] === 'available_for_betting');
            });
            $matches = array_values($matches);
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
        if (!isset($match['startTime']) && !isset($match->startTime)) {
            return false;
        }

        $startTime = isset($match['startTime']) ? $match['startTime'] : $match->startTime;

        if (is_string($startTime)) {
            $startTime = \Carbon\Carbon::parse($startTime);
        }

        $now = \Carbon\Carbon::now();
        if ($startTime > $now) {
            Log::debug('Match excluded from live visibility - future start time', [
                'match_id' => isset($match['id']) ? $match['id'] : (isset($match->eventId) ? $match->eventId : 'unknown'),
                'startTime' => $startTime->toDateTimeString(),
                'current_time' => $now->toDateTimeString()
            ]);
            return false;
        }

        $liveStatusId = isset($match['live_status_id']) ? $match['live_status_id'] : (isset($match->live_status_id) ? $match->live_status_id : 0);

        // Match is cancelled or finished - never show as live
        if ($liveStatusId === -1 || $liveStatusId === 2) {
            Log::debug('Match excluded from live visibility - cancelled or finished', [
                'match_id' => isset($match['id']) ? $match['id'] : (isset($match->eventId) ? $match->eventId : 'unknown'),
                'live_status_id' => $liveStatusId
            ]);
            return false;
        }

        $homeScore = isset($match['home_score']) ? $match['home_score'] : (isset($match->home_score) ? $match->home_score : 0);
        $awayScore = isset($match['away_score']) ? $match['away_score'] : (isset($match->away_score) ? $match->away_score : 0);

        $hasPinnacleLive = $liveStatusId > 0;
        $hasApiFootballLive = ($homeScore > 0 || $awayScore > 0);

        // If Pinnacle confirms it's live or we have scores, definitely show as live
        if ($hasPinnacleLive || $hasApiFootballLive) {
            return true;
        }

        // For matches that have started but Pinnacle hasn't confirmed yet,
        // we still consider them potentially live if they have open markets
        // This handles the delay between actual match start and Pinnacle API update
        $hasOpenMarkets = isset($match['hasOpenMarkets']) ? $match['hasOpenMarkets'] : (isset($match->hasOpenMarkets) ? $match->hasOpenMarkets : false);
        $hasOpenMarketsArray = isset($match['has_open_markets']) ? $match['has_open_markets'] : false;

        return $hasOpenMarkets || $hasOpenMarketsArray;
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
            if (!$this->isLiveVisible($match)) {
                return false;
            }

            $liveStatusId = $match['live_status_id'] ?? 0;

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
        if (empty($leagueIds)) {
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

        if ($matchType === 'all' || $matchType === 'live') {
            \App\Jobs\LiveMatchSyncJob::dispatch($sportId, $leagueIds)->onQueue('live-sync');
        }

        if ($matchType === 'all' || $matchType === 'prematch') {
            \App\Jobs\PrematchSyncJob::dispatch($sportId, $leagueIds)->onQueue('prematch-sync');
        }

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

            // Get the match to determine event type (live or prematch)
            $match = SportsMatch::where('eventId', $matchId)->first();
            $eventType = $match && $match->eventType === 'live' ? 'live' : 'prematch';

            $marketsData = $this->pinnacleApi->getSpecialMarkets($eventType, $sportId);

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
     * Process detailed odds for a match
     */
    private function processMatchOdds($marketsData, $matchId, $period, $marketType)
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

            // Generate sample data for Player Props market
            if ($marketType === 'player_props' || $marketType === 'all') {
                $playerNames = ['Player A', 'Player B', 'Player C', 'Player D'];
                $propTypes = ['Goals', 'Assists', 'Points', 'Rebounds', 'Shots'];
                
                foreach ($playerNames as $playerName) {
                    foreach ($propTypes as $propType) {
                        $line = rand(5, 25);
                        $allOdds[] = [
                            'bet' => $playerName . ' - ' . $propType . ' Over ' . $line,
                            'home_team_id' => $homeTeamId,
                            'away_team_id' => $awayTeamId,
                            'line' => (string)$line,
                            'odds' => number_format(rand(180, 220) / 100, 3),
                            'status' => 'open',
                            'period' => 'Game',
                            'updated_at' => date('Y-m-d H:i:s', strtotime('-' . rand(5, 20) . ' minutes'))
                        ];
                        $allOdds[] = [
                            'bet' => $playerName . ' - ' . $propType . ' Under ' . $line,
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
            $sportId = $request->input('sport_id');
            $leagueIds = $request->input('league_ids', []);
            $matchType = $request->input('match_type', 'all');
            $timezone = $request->input('timezone', 'UTC'); // Default to UTC if not provided
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
                'forceRefresh' => $forceRefresh
            ]);

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