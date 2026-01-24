<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PinnacleService;
use App\Services\ApiFootballService;
use App\Services\TheOddsApiService;
use App\Services\BetTypesDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BetTypesController extends Controller
{
    protected $pinnacleApi;
    protected $apiFootball;
    protected $theOddsApi;

    public function __construct(
        PinnacleService $pinnacleApi,
        ApiFootballService $apiFootball,
        TheOddsApiService $theOddsApi
    ) {
        $this->pinnacleApi = $pinnacleApi;
        $this->apiFootball = $apiFootball;
        $this->theOddsApi = $theOddsApi;
    }

    public function getSports()
    {
        try {
            $sports = Cache::remember('sports', 3600, function () { // Cache for 1 hour
                return $this->pinnacleApi->getSports();
            });

            // Filter and format sports data
            $formattedSports = [];
            if (is_array($sports)) {
                foreach ($sports as $sport) {
                    if (isset($sport['id']) && isset($sport['name'])) {
                        $formattedSports[] = [
                            'id' => $sport['id'],
                            'name' => $sport['name']
                        ];
                    }
                }
            }

            return response()->json($formattedSports);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch sports', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to load sports data'], 500);
        }
    }

    public function getLeagues($sportId)
    {
        try {
            $cacheKey = "leagues_{$sportId}";

            $leagues = Cache::remember($cacheKey, 1800, function () use ($sportId) { // Cache for 30 minutes
                return $this->pinnacleApi->getLeagues($sportId);
            });

            // Format leagues data
            $formattedLeagues = [];
            if (isset($leagues['leagues']) && is_array($leagues['leagues'])) {
                foreach ($leagues['leagues'] as $league) {
                    if (isset($league['id']) && isset($league['name'])) {
                        $formattedLeagues[] = [
                            'id' => $league['id'],
                            'name' => $league['name'],
                            'container' => $league['container'] ?? null
                        ];
                    }
                }
            }

            return response()->json($formattedLeagues);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch leagues', ['sportId' => $sportId, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to load leagues data'], 500);
        }
    }

    public function getMatches(Request $request)
    {
        try {
            $sportId = $request->get('sport_id');
            $leagueId = $request->get('league_id');
            $isLive = $request->boolean('live', false);

            $cacheKey = "matches_{$sportId}_{$leagueId}_" . ($isLive ? 'live' : 'prematch');
            $cacheTime = $isLive ? 300 : 600; // 5 minutes for live, 10 minutes for prematch

            $processedMatches = Cache::remember($cacheKey, $cacheTime, function () use ($sportId, $leagueId, $isLive) {
                $matches = $this->pinnacleApi->getFixtures($sportId, $leagueId, $isLive);

                if (empty($matches['fixtures'] ?? [])) {
                    $matches = $this->apiFootball->getFixtures($leagueId, null, $isLive);
                }

                // If still no data from APIs, try database as fallback
                if (empty($matches['fixtures'] ?? []) && empty($matches['response'] ?? [])) {
                    \Log::info('API fallback: Using database for matches', [
                        'sportId' => $sportId,
                        'leagueId' => $leagueId,
                        'isLive' => $isLive
                    ]);
                    $matches = $this->getMatchesFromDatabase($sportId, $leagueId, $isLive);
                    \Log::info('Database fallback result', [
                        'fixtures_count' => count($matches['fixtures'] ?? []),
                        'has_fixtures' => !empty($matches['fixtures'] ?? [])
                    ]);
                }

                // If still no data, return mock data for testing
                if (empty($matches['fixtures'] ?? []) && empty($matches['response'] ?? []) && empty($matches ?? [])) {
                    $matches = $this->getMockMatches($sportId, $leagueId, $isLive);
                }

                return $this->processMatchesWithBetTypes($matches);
            });

            return response()->json($processedMatches);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch matches', [
                'sportId' => $request->get('sport_id'),
                'leagueId' => $request->get('league_id'),
                'isLive' => $request->boolean('live', false),
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to load matches data'], 500);
        }
    }

    /**
     * Get matches from database as fallback when APIs fail
     */
    private function getMatchesFromDatabase($sportId, $leagueId, $isLive)
    {
        try {
            \Log::info('getMatchesFromDatabase called', [
                'sportId' => $sportId,
                'leagueId' => $leagueId,
                'isLive' => $isLive
            ]);

            // Convert Pinnacle league ID to database ID
            $league = \DB::table('leagues')->where('pinnacleId', $leagueId)->first();
            if (!$league) {
                \Log::info('League not found', ['pinnacleId' => $leagueId]);
                return [];
            }

            \Log::info('League found', [
                'league_name' => $league->name,
                'league_sport_id' => $league->sportId,
                'requested_sport_id' => $sportId
            ]);

            $query = \DB::table('matches')
                ->where('sportId', $sportId)
                ->where('leagueId', $league->id)
                ->where('lastUpdated', '>', now()->subHours(24));

            if ($isLive) {
                // CRITICAL: Exclude finished matches (status 2) - ABSOLUTE, NO EXCEPTIONS
                $threeHoursAgo = now()->subHours(3);
                $query->where('eventType', 'live')
                      ->where('live_status_id', '!=', -1)  // Exclude cancelled
                      ->where('live_status_id', '!=', 2)     // Exclude finished - CRITICAL
                      ->where('live_status_id', '=', 1)      // Only live matches
                      ->where(function($q) use ($threeHoursAgo) {
                          // Only matches that started within last 3 hours
                          $q->where('startTime', '>=', $threeHoursAgo)
                            ->orWhereNull('startTime');
                      });
            } else {
                $query->where('eventType', 'prematch')
                      ->where('live_status_id', '!=', -1)  // Exclude cancelled
                      ->where('live_status_id', '!=', 2);   // Exclude finished
            }

            $matches = $query->orderBy('startTime', 'asc')->get();

            \Log::info('Database query result', [
                'matches_found' => $matches->count(),
                'query_sql' => $query->toSql()
            ]);

            if ($matches->count() == 0) {
                \Log::info('No matches found, returning empty array');
                return [];
            }

            // Convert to Pinnacle API format expected by processMatchesWithBetTypes
            $formattedMatches = [];
            foreach ($matches as $match) {
                $formattedMatches[] = [
                    'id' => $match->eventId,
                    'sport_name' => $sportId == 1 ? 'Soccer' : ($sportId == 2 ? 'Tennis' : 'Unknown'),
                    'league_name' => $match->leagueName,
                    'home_team' => $match->homeTeam,
                    'away_team' => $match->awayTeam,
                    'event_date' => $match->startTime,
                    'status' => $isLive ? ($match->live_status_id > 0 ? 1 : 0) : 0,
                    'scores' => $isLive ? ['home' => 0, 'away' => 0] : null,
                    'has_open_markets' => $match->hasOpenMarkets ?? false,
                    'markets' => []
                ];
            }

            $result = ['fixtures' => $formattedMatches];
            \Log::info('getMatchesFromDatabase returning', [
                'fixtures_count' => count($formattedMatches),
                'result_structure' => array_keys($result)
            ]);

            return $result;
        } catch (\Exception $e) {
            \Log::error('Database fallback failed', [
                'sportId' => $sportId,
                'leagueId' => $leagueId,
                'isLive' => $isLive,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getBetTypes($matchId)
    {
        try {
            $cacheKey = "bet_types_match_{$matchId}";

            $betTypes = Cache::remember($cacheKey, 60, function () {
                $markets = $this->pinnacleApi->getSpecialMarkets('prematch', 1);

                $betTypes = $this->extractBetTypes($markets);

                if (empty($betTypes)) {
                    $betTypes = $this->getMockBetTypes(1);
                }

                return $betTypes;
            });

            return response()->json($betTypes);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch bet types for match', ['matchId' => $matchId, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to load bet types data'], 500);
        }
    }

    public function getBetTypesBySport(Request $request)
    {
        $sportId = $request->get('sportId');

        // Get predefined bet types for this sport from BetTypesDefinition
        $predefinedBetTypes = BetTypesDefinition::getBetTypesForSport($sportId);

        // Transform the predefined data to include IDs and format properly
        $enhancedBetTypes = $this->enhancePredefinedBetTypes($predefinedBetTypes);

        // Structure the response in the requested format
        $response = $this->formatBetTypesResponse($sportId, $enhancedBetTypes);

        return response()->json($response);
    }

    private function processMatchesWithBetTypes($matchesData)
    {
        // Handle Pinnacle API format
        if (isset($matchesData['fixtures'])) {
            return array_map(function($fixture) {
                return [
                    'id' => $fixture['id'],
                    'sport_name' => $fixture['sport_name'] ?? 'Unknown',
                    'league_name' => $fixture['league_name'] ?? 'Unknown',
                    'home_team' => $fixture['home_team'],
                    'away_team' => $fixture['away_team'],
                    'scheduled_at' => $fixture['event_date'],
                    'status' => $this->mapStatus($fixture['status']),
                    'scores' => $fixture['scores'] ?? null,
                    'has_open_markets' => $fixture['has_open_markets'] ?? false,
                    'markets' => $fixture['markets'] ?? []
                ];
            }, $matchesData['fixtures']);
        }

        // Handle API-Football format
        if (isset($matchesData['response'])) {
            return array_map(function($fixture) {
                $match = $fixture['fixture'];
                $teams = $fixture['teams'];
                $goals = $fixture['goals'];

                return [
                    'id' => $match['id'],
                    'sport_name' => 'Soccer', // API-Football is soccer-focused
                    'league_name' => $fixture['league']['name'] ?? 'Unknown',
                    'home_team' => $teams['home']['name'],
                    'away_team' => $teams['away']['name'],
                    'scheduled_at' => $match['date'],
                    'status' => $this->mapApiFootballStatus($match['status']['short']),
                    'scores' => $goals ? ['home' => $goals['home'], 'away' => $goals['away']] : null,
                    'has_open_markets' => true, // Assume markets are available
                    'markets' => []
                ];
            }, $matchesData['response']);
        }

        return [];
    }

    private function extractBetTypes($marketsData)
    {
        $betTypes = [];

        if (isset($marketsData['specials'])) {
            if (is_array($marketsData['specials'])) {
                foreach ($marketsData['specials'] as $market) {
                    // Try to determine the type from available fields
                    $type = $this->determineBetType($market);

                    // Skip markets without meaningful data
                    if (empty($market['name'] ?? '') && empty($market['type'] ?? '')) {
                        continue;
                    }

                    $betTypes[] = [
                        'type' => $type,
                        'name' => $this->formatMarketType($type),
                        'period' => $market['period'] ?? 'FT',
                        'is_open' => $market['is_open'] ?? true,
                        'outcomes' => $market['outcomes'] ?? [],
                        'raw_name' => $market['name'] ?? '' // Keep original name for better type detection
                    ];
                }
            }
        } elseif (isset($marketsData['special_markets'])) {
            foreach ($marketsData['special_markets'] as $market) {
                $betTypes[] = [
                    'type' => $market['type'],
                    'name' => $this->formatMarketType($market['type']),
                    'period' => $market['period'] ?? 'FT',
                    'is_open' => $market['is_open'] ?? true,
                    'outcomes' => $market['outcomes'] ?? []
                ];
            }
        }

        return $betTypes;
    }

    private function mapStatus($status)
    {
        $statusMap = [
            0 => 'scheduled',
            1 => 'live',
            2 => 'finished',
            3 => 'cancelled'
        ];

        return $statusMap[$status] ?? 'scheduled';
    }

    private function mapApiFootballStatus($status)
    {
        $statusMap = [
            'NS' => 'scheduled',     // Not Started
            'LIVE' => 'live',        // Live
            'FT' => 'finished',      // Full Time
            'HT' => 'live',          // Half Time
            'PST' => 'postponed',    // Postponed
            'CANC' => 'cancelled',   // Cancelled
            'ABD' => 'abandoned',    // Abandoned
            'AWD' => 'finished',     // Awarded
            'WO' => 'finished'       // Walkover
        ];

        return $statusMap[$status] ?? 'scheduled';
    }

    private function formatMarketType($type)
    {
        $typeMap = [
            'money_line' => 'Money Line',
            'spreads' => 'Spreads',
            'totals' => 'Totals',
            'team_totals' => 'Team Totals',
            'player_props' => 'Player Props',
            'team_props' => 'Team Props',
            'corners' => 'Corners'
        ];

        return $typeMap[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    private function getMockMatches($sportId, $leagueId, $isLive)
    {
        $mockFixtures = [
            [
                'id' => 1001,
                'sport_name' => 'Soccer',
                'league_name' => 'Premier League',
                'home_team' => 'Manchester City',
                'away_team' => 'Liverpool',
                'event_date' => date('Y-m-d H:i:s', strtotime('+2 hours')),
                'status' => $isLive ? 1 : 0,
                'scores' => $isLive ? ['home' => 1, 'away' => 0] : null,
                'has_open_markets' => true,
                'markets' => []
            ],
            [
                'id' => 1002,
                'sport_name' => 'Soccer',
                'league_name' => 'Premier League',
                'home_team' => 'Arsenal',
                'away_team' => 'Chelsea',
                'event_date' => date('Y-m-d H:i:s', strtotime('+4 hours')),
                'status' => 0,
                'scores' => null,
                'has_open_markets' => true,
                'markets' => []
            ],
            [
                'id' => 1003,
                'sport_name' => 'Soccer',
                'league_name' => 'Premier League',
                'home_team' => 'Tottenham',
                'away_team' => 'Manchester United',
                'event_date' => date('Y-m-d H:i:s', strtotime('+6 hours')),
                'status' => 0,
                'scores' => null,
                'has_open_markets' => false,
                'markets' => []
            ]
        ];

        return ['fixtures' => $mockFixtures];
    }

    private function getMockBetTypes($matchId)
    {
        return [
            [
                'type' => 'money_line',
                'name' => 'Money Line',
                'period' => 'FT',
                'is_open' => true,
                'outcomes' => [
                    ['name' => 'Home', 'odds' => 2.10],
                    ['name' => 'Draw', 'odds' => 3.40],
                    ['name' => 'Away', 'odds' => 3.60]
                ]
            ],
            [
                'type' => 'spreads',
                'name' => 'Spreads',
                'period' => 'FT',
                'is_open' => true,
                'outcomes' => [
                    ['name' => 'Home -0.5', 'odds' => 1.85, 'line' => -0.5],
                    ['name' => 'Away +0.5', 'odds' => 1.95, 'line' => 0.5]
                ]
            ],
            [
                'type' => 'totals',
                'name' => 'Totals',
                'period' => 'FT',
                'is_open' => true,
                'outcomes' => [
                    ['name' => 'Over 2.5', 'odds' => 1.90, 'line' => 2.5],
                    ['name' => 'Under 2.5', 'odds' => 1.90, 'line' => 2.5]
                ]
            ],
            [
                'type' => 'player_props',
                'name' => 'Player Props',
                'period' => 'FT',
                'is_open' => true,
                'outcomes' => [
                    ['name' => 'Player A Over 1.5 Goals', 'odds' => 2.20, 'line' => 1.5],
                    ['name' => 'Player A Under 1.5 Goals', 'odds' => 1.65, 'line' => 1.5]
                ]
            ]
        ];
    }

    private function deduplicateBetTypes($betTypes)
    {
        $uniqueTypes = [];
        $seenTypes = [];
        $specialCount = 0;

        foreach ($betTypes as $betType) {
            $typeKey = $betType['type'] ?? 'unknown';

            // Skip if we've already seen this type (except for special types which can have multiple)
            if (in_array($typeKey, $seenTypes) && $typeKey !== 'special') {
                continue;
            }

            // Limit special types to avoid cluttering
            if ($typeKey === 'special') {
                $specialCount++;
                if ($specialCount > 3) {
                    continue;
                }
            } else {
                $seenTypes[] = $typeKey;
            }

            $uniqueTypes[] = $betType;

            // Limit to a reasonable number of bet types to avoid overwhelming the UI
            if (count($uniqueTypes) >= 15) {
                break;
            }
        }

        return $uniqueTypes;
    }

    private function enhanceBetTypesWithMetadata($basicBetTypes, $sportId)
    {
        // Map bet types to enhanced metadata based on sport
        $betTypeMetadata = [
            'money_line' => [
                'id' => 44,
                'category' => 'Money Line',
                'description' => 'Match winner'
            ],
            'spreads' => [
                'id' => 45,
                'category' => 'Spreads',
                'description' => $this->getSpreadDescription($sportId)
            ],
            'totals' => [
                'id' => 46,
                'category' => 'Totals',
                'description' => $this->getTotalsDescription($sportId)
            ],
            'team_totals' => [
                'id' => 47,
                'category' => 'Team Totals',
                'description' => 'Individual team point totals'
            ],
            'player_props' => [
                'id' => 48,
                'category' => 'Player Props',
                'description' => 'Player-specific betting markets (via The-Odds-API)'
            ],
            'team_props' => [
                'id' => 49,
                'category' => 'Team Props',
                'description' => 'Team-specific markets'
            ],
            'game_props' => [
                'id' => 50,
                'category' => 'Game Props',
                'description' => 'Game-specific markets'
            ],
            'both_teams_to_score' => [
                'id' => 51,
                'category' => 'Game Props',
                'description' => 'Both teams to score'
            ],
            'draw_no_bet' => [
                'id' => 52,
                'category' => 'Game Props',
                'description' => 'Draw no bet market'
            ],
            'corners' => [
                'id' => 53,
                'category' => 'Game Props',
                'description' => 'Corner kicks betting'
            ]
        ];

        $enhanced = [];
        foreach ($basicBetTypes as $betType) {
            $type = $betType['type'];
            $metadata = $betTypeMetadata[$type] ?? [
                'id' => crc32($type) % 1000 + 100,
                'category' => 'General',
                'description' => ucwords(str_replace('_', ' ', $type)) . ' betting'
            ];

            $enhanced[] = array_merge($betType, $metadata);
        }

        return $enhanced;
    }

    private function getSpreadDescription($sportId)
    {
        $descriptions = [
            1 => 'Handicap betting', // Soccer
            2 => 'Game handicap', // Tennis
            3 => 'Point spread betting', // Basketball
            4 => 'Puck line betting', // Hockey
            7 => 'Point spread betting', // American Football
            9 => 'Run line betting', // Baseball
        ];

        return $descriptions[$sportId] ?? 'Spread betting';
    }

    private function getTotalsDescription($sportId)
    {
        $descriptions = [
            1 => 'Over/Under total goals', // Soccer
            2 => 'Over/Under total games', // Tennis
            3 => 'Over/Under total points', // Basketball
            4 => 'Over/Under total goals', // Hockey
            7 => 'Over/Under total points', // American Football
            9 => 'Over/Under total runs', // Baseball
        ];

        return $descriptions[$sportId] ?? 'Over/Under betting';
    }

    private function determineBetType($market)
    {
        // Try to determine bet type from various fields in the market data
        if (isset($market['type']) && !empty($market['type'])) {
            return $market['type'];
        }

        // Check for common identifiers in market data
        $name = strtolower($market['name'] ?? $market['raw_name'] ?? '');

        if (strpos($name, 'money') !== false || strpos($name, 'winner') !== false || strpos($name, 'match winner') !== false) {
            return 'money_line';
        }
        if (strpos($name, 'spread') !== false || strpos($name, 'handicap') !== false || strpos($name, 'run line') !== false || strpos($name, 'puck line') !== false) {
            return 'spreads';
        }
        if (strpos($name, 'total') !== false || strpos($name, 'over') !== false || strpos($name, 'under') !== false) {
            return 'totals';
        }
        if (strpos($name, 'player') !== false || strpos($name, 'player props') !== false ||
            strpos($name, '(goals)') !== false || strpos($name, '(assists)') !== false ||
            strpos($name, '(points)') !== false || strpos($name, '(shots)') !== false) {
            return 'player_props';
        }
        if (strpos($name, 'team total') !== false || strpos($name, 'team totals') !== false) {
            return 'team_totals';
        }
        if (strpos($name, 'team props') !== false) {
            return 'team_props';
        }
        if (strpos($name, 'game props') !== false || strpos($name, 'both teams') !== false || strpos($name, 'draw no bet') !== false) {
            return 'game_props';
        }
        if (strpos($name, 'corner') !== false) {
            return 'corners';
        }

        // Check for other identifying fields
        if (isset($market['special_type'])) {
            return $market['special_type'];
        }

        if (isset($market['market_type'])) {
            return $market['market_type'];
        }

        // If we can't determine the type but the market has a name, use a generic type
        if (!empty($name)) {
            return 'special';
        }

        // Default fallback
        return 'unknown';
    }

    private function formatBetTypesResponse($sportId, $enhancedBetTypes)
    {
        $categories = [];
        $flat = [];

        foreach ($enhancedBetTypes as $betType) {
            $categoryName = $betType['category'] ?? 'General';

            // Initialize category array if it doesn't exist
            if (!isset($categories[$categoryName])) {
                $categories[$categoryName] = [];
            }

            // Add to categories
            $categories[$categoryName][] = [
                'id' => $betType['id'],
                'name' => $betType['name'],
                'description' => $betType['description']
            ];

            // Add to flat array
            $flat[] = [
                'id' => $betType['id'],
                'category' => $categoryName,
                'name' => $betType['name'],
                'description' => $betType['description']
            ];
        }

        return [
            'sportId' => (int) $sportId,
            'categories' => $categories,
            'flat' => $flat
        ];
    }

    private function enhancePredefinedBetTypes($predefinedBetTypes)
    {
        $betTypeIdMap = [
            'Money Line' => 44,
            'Spreads' => 45,
            'Totals' => 46,
            'Team Totals' => 47,
            'Player Props' => 48,
            'Team Props' => 49,
            'Game Props' => 50,
            'Both Teams to Score' => 51,
            'Draw No Bet' => 52,
            'Correct Score' => 53,
            'Double Chance' => 54,
            'Winning Margin' => 55,
            'Double Result' => 56,
            'Exact Total Goals' => 57,
            'Corners' => 58,
            'Futures' => 59,
            'Next Round' => 60,
            'Method of Victory' => 61,
            'Player Points' => 62,
            'Player Assists' => 63,
            'Player Rebounds' => 64,
        ];

        $enhanced = [];
        foreach ($predefinedBetTypes as $betType) {
            $name = $betType['name'];
            $id = $betTypeIdMap[$name] ?? crc32($name) % 1000 + 100;

            $enhanced[] = [
                'id' => $id,
                'category' => $betType['category'],
                'name' => $name,
                'description' => $betType['description'],
                'type' => strtolower(str_replace([' ', '/'], ['_', ''], $name))
            ];
        }

        return $enhanced;
    }
}