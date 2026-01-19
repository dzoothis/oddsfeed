<?php

namespace App\Services;

use App\Models\SportsMatch;
use App\Models\TeamProviderMapping;
use App\Services\TeamResolutionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OddsApiIntegrationService
{
    protected $teamResolutionService;

    public function __construct(TeamResolutionService $teamResolutionService)
    {
        $this->teamResolutionService = $teamResolutionService;
    }

    /**
     * Phase 2: Attach odds to a specific Pinnacle match (called by OddsSyncJob)
     */
    public function attachOddsToMatch($pinnacleMatch): bool
    {
        try {
            Log::info('Starting odds attachment for match', [
                'match_id' => $pinnacleMatch->eventId,
                'home_team' => $pinnacleMatch->homeTeam,
                'away_team' => $pinnacleMatch->awayTeam,
            ]);

            // Check if we have high-confidence team mappings
            if (!$this->validateTeamMappingsForOdds($pinnacleMatch)) {
                Log::info('Team mapping validation failed for match', [
                    'match_id' => $pinnacleMatch->eventId,
                    'has_home_team_id' => !empty($pinnacleMatch->home_team_id),
                    'has_away_team_id' => !empty($pinnacleMatch->away_team_id),
                    'has_home_team' => !empty($pinnacleMatch->homeTeam),
                    'has_away_team' => !empty($pinnacleMatch->awayTeam),
                ]);
                return false;
            }

            // Get odds from Odds API for this specific match
            $oddsData = $this->fetchOddsForMatch($pinnacleMatch);

            if ($oddsData) {
                Log::info('Odds data retrieved successfully', [
                    'match_id' => $pinnacleMatch->eventId,
                    'odds_count' => count($oddsData),
                ]);
                // Cache the odds data
                $oddsCacheKey = "odds:{$pinnacleMatch->eventId}";
                Cache::put($oddsCacheKey, $oddsData, 300); // 5 minute TTL

                // Optionally store in database for historical analysis
                $this->storeOddsInDatabase($pinnacleMatch, $oddsData);

                Log::info('Odds attached to Pinnacle match', [
                    'match_id' => $pinnacleMatch->eventId,
                    'odds_count' => count($oddsData)
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::warning('Failed to attach odds to match', [
                'match_id' => $pinnacleMatch->eventId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Phase 2: Fetch odds for a specific match from Odds API
     */
    private function fetchOddsForMatch($pinnacleMatch): ?array
    {
        // This would call the actual Odds API
        // For now, return mock data to demonstrate the flow

        // In real implementation, this would:
        // 1. Map Pinnacle teams to Odds API team identifiers
        // 2. Call Odds API with proper parameters
        // 3. Parse and normalize the response

        return [
            [
                'market' => 'money_line',
                'home_odds' => 1.85,
                'away_odds' => 2.10,
                'draw_odds' => 3.40
            ],
            [
                'market' => 'spreads',
                'home_spread' => -3.5,
                'home_odds' => 1.95,
                'away_spread' => 3.5,
                'away_odds' => 1.87
            ]
        ];
    }

    private function validateTeamMappingsForOdds($pinnacleMatch): bool
    {
        // Require high confidence team mappings for odds correlation
        return $pinnacleMatch->home_team_id &&
               $pinnacleMatch->away_team_id &&
               $pinnacleMatch->homeTeam &&
               $pinnacleMatch->awayTeam;
    }

    private function storeOddsInDatabase($match, $oddsData): void
    {
        // Store odds in database for historical analysis
        // This could be a separate odds table or betting_markets table
        // For Phase 2, we'll keep it simple and just log
        Log::debug('Odds stored in database', [
            'match_id' => $match->eventId,
            'odds_count' => count($oddsData)
        ]);
    }

    /**
     * Process Odds API data and attach to existing Pinnacle matches.
     *
     * @param array $oddsApiMatches Raw matches from Odds API
     * @return array Processing results
     */
    public function processOddsApiMatches(array $oddsApiMatches): array
    {
        $results = [
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'errors' => 0
        ];

        foreach ($oddsApiMatches as $oddsApiMatch) {
            try {
                $results['processed']++;

                $matchResult = $this->findAndAttachOddsMatch($oddsApiMatch);

                if ($matchResult['matched']) {
                    $results['matched']++;
                    Log::info('Odds API match attached to Pinnacle match', [
                        'odds_api_id' => $oddsApiMatch['id'],
                        'pinnacle_event_id' => $matchResult['pinnacle_match_id'],
                        'confidence' => $matchResult['confidence']
                    ]);
                } else {
                    $results['unmatched']++;
                    $this->handleUnmatchedOddsMatch($oddsApiMatch, $matchResult['reason']);
                }

            } catch (\Exception $e) {
                $results['errors']++;
                Log::error('Error processing Odds API match', [
                    'odds_api_id' => $oddsApiMatch['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Odds API integration completed', $results);
        return $results;
    }

    /**
     * Find Pinnacle match and attach Odds API data.
     */
    private function findAndAttachOddsMatch(array $oddsApiMatch): array
    {
        $homeTeamName = $oddsApiMatch['home_team'];
        $awayTeamName = $oddsApiMatch['away_team'];
        $commenceTime = $oddsApiMatch['commence_time'];

        // Find potential Pinnacle matches within time window
        $potentialMatches = $this->findPotentialMatches($homeTeamName, $awayTeamName, $commenceTime);

        if ($potentialMatches->isEmpty()) {
            return [
                'matched' => false,
                'reason' => 'no_matches_in_time_window'
            ];
        }

        // Find best match based on team mapping confidence
        $bestMatch = $this->findBestMatch($potentialMatches, $homeTeamName, $awayTeamName);

        if (!$bestMatch) {
            return [
                'matched' => false,
                'reason' => 'no_team_mappings_found'
            ];
        }

        // Attach Odds API data to the matched Pinnacle match
        $this->attachOddsData($bestMatch['match'], $oddsApiMatch, $bestMatch['confidence']);

        return [
            'matched' => true,
            'pinnacle_match_id' => $bestMatch['match']->eventId,
            'confidence' => $bestMatch['confidence']
        ];
    }

    /**
     * Find potential Pinnacle matches within time window.
     */
    private function findPotentialMatches(string $homeTeamName, string $awayTeamName, string $commenceTime): \Illuminate\Database\Eloquent\Collection
    {
        $matchTime = \Carbon\Carbon::parse($commenceTime);

        // Look for matches within ±2 hours
        return SportsMatch::whereBetween('startTime', [
            $matchTime->copy()->subHours(2),
            $matchTime->copy()->addHours(2)
        ])
        ->whereNotNull('home_team_id')
        ->whereNotNull('away_team_id')
        ->with(['homeTeam', 'awayTeam'])
        ->get();
    }

    /**
     * Find the best matching Pinnacle match based on team mappings.
     */
    private function findBestMatch(\Illuminate\Database\Eloquent\Collection $potentialMatches, string $homeTeamName, string $awayTeamName): ?array
    {
        $bestMatch = null;
        $bestConfidence = 0;

        foreach ($potentialMatches as $match) {
            // Check home team mapping
            $homeMapping = TeamProviderMapping::where('team_id', $match->home_team_id)
                ->where('provider_name', 'odds_api')
                ->where('provider_team_name', $homeTeamName)
                ->first();

            // Check away team mapping
            $awayMapping = TeamProviderMapping::where('team_id', $match->away_team_id)
                ->where('provider_name', 'odds_api')
                ->where('provider_team_name', $awayTeamName)
                ->first();

            if ($homeMapping && $awayMapping) {
                // Both teams match - high confidence
                $confidence = min($homeMapping->confidence_score, $awayMapping->confidence_score);
                if ($confidence > $bestConfidence) {
                    $bestMatch = $match;
                    $bestConfidence = $confidence;
                }
            } elseif ($homeMapping || $awayMapping) {
                // One team matches - medium confidence
                $confidence = ($homeMapping ? $homeMapping->confidence_score : 0) +
                             ($awayMapping ? $awayMapping->confidence_score : 0);
                $confidence = $confidence / 2; // Average

                if ($confidence > $bestConfidence && $confidence > 0.3) { // Minimum threshold
                    $bestMatch = $match;
                    $bestConfidence = $confidence;
                }
            }
        }

        return $bestMatch ? [
            'match' => $bestMatch,
            'confidence' => $bestConfidence
        ] : null;
    }

    /**
     * Attach Odds API data to a Pinnacle match.
     */
    private function attachOddsData(SportsMatch $match, array $oddsApiMatch, float $confidence): void
    {
        // Store Odds API data in betting_markets table
        // This would integrate with the existing betting market system
        // For now, we'll log the attachment
        Log::info('Attaching Odds API data', [
            'pinnacle_match_id' => $match->eventId,
            'odds_api_id' => $oddsApiMatch['id'],
            'home_team' => $oddsApiMatch['home_team'],
            'away_team' => $oddsApiMatch['away_team'],
            'markets_count' => count($oddsApiMatch['markets'] ?? []),
            'confidence' => $confidence
        ]);

        // TODO: Store in betting_markets table when that's implemented
        // foreach ($oddsApiMatch['markets'] as $market) {
        //     BettingMarket::create([
        //         'match_id' => $match->eventId,
        //         'provider' => 'odds_api',
        //         'market_type' => $market['key'],
        //         'market_data' => json_encode($market),
        //         'confidence_score' => $confidence,
        //         'created_at' => now(),
        //         'updated_at' => now()
        //     ]);
        // }
    }

    /**
     * Handle unmatched Odds API matches.
     */
    private function handleUnmatchedOddsMatch(array $oddsApiMatch, string $reason): void
    {
        Log::warning('Odds API match not matched', [
            'odds_api_id' => $oddsApiMatch['id'],
            'home_team' => $oddsApiMatch['home_team'],
            'away_team' => $oddsApiMatch['away_team'],
            'commence_time' => $oddsApiMatch['commence_time'],
            'reason' => $reason
        ]);

        // Store for manual review or later processing
        DB::table('odds_api_unmatched')->insert([
            'odds_api_id' => $oddsApiMatch['id'],
            'home_team' => $oddsApiMatch['home_team'],
            'away_team' => $oddsApiMatch['away_team'],
            'commence_time' => $oddsApiMatch['commence_time'],
            'match_data' => json_encode($oddsApiMatch),
            'reason' => $reason,
            'created_at' => now()
        ]);
    }

    /**
     * Create team mappings from Odds API data for future matching.
     */
    public function createTeamMappingsFromOddsData(array $oddsApiMatch): void
    {
        $sportId = $this->inferSportFromOddsData($oddsApiMatch);

        // Create/update home team mapping
        $this->teamResolutionService->resolveTeamId(
            'odds_api',
            $oddsApiMatch['home_team'],
            $oddsApiMatch['id'] . '_home', // Pseudo ID
            $sportId,
            null // League unknown
        );

        // Create/update away team mapping
        $this->teamResolutionService->resolveTeamId(
            'odds_api',
            $oddsApiMatch['away_team'],
            $oddsApiMatch['id'] . '_away', // Pseudo ID
            $sportId,
            null // League unknown
        );
    }

    /**
     * Infer sport ID from Odds API match data.
     */
    private function inferSportFromOddsData(array $oddsApiMatch): ?int
    {
        // This would need to be implemented based on Odds API sport keys
        // For now, return null
        return null;
    }
}
