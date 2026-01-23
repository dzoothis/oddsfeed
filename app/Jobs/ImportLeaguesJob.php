<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Services\PinnacleService;
use App\Models\League;
use App\Models\Team;
use App\Models\SportsMatch;

class ImportLeaguesJob implements ShouldQueue
{
    use Queueable;

    // Queue configuration for long-running import operations
    public $tries = 3; // Retry up to 3 times
    public $timeout = 1800; // 30 minutes timeout for bulk operations
    public $backoff = [300, 600, 1200]; // Exponential backoff: 5min, 10min, 20min

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job - Phase 1: League import and team relationship fix
     */
    public function handle(PinnacleService $pinnacleService): void
    {
        Log::info('Starting league import job - Phase 1');

        try {
            // Step 1: Import leagues from Pinnacle API
            $leaguesImported = $this->importLeaguesFromPinnacle($pinnacleService);

            if ($leaguesImported > 0) {
                // Step 2: Backfill team leagueIds based on existing match data (only if leagues were imported)
                $this->backfillTeamLeagueIds();
                Log::info('League import job completed successfully - Phase 1');
            } else {
                Log::warning('League import job completed but no leagues were imported - skipping backfill');
            }
        } catch (\Exception $e) {
            Log::error('League import job failed - Phase 1', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function importLeaguesFromPinnacle(PinnacleService $pinnacleService): int
    {
        Log::info('Importing leagues from Pinnacle API');

        try {
            // Get all sports first, then fetch leagues for each sport
            $sports = $pinnacleService->getSports();

            Log::info('Fetched sports data', [
                'sports_count' => count($sports)
            ]);

            $totalImportedCount = 0;
            $totalSkippedCount = 0;
            $sportsProcessed = 0;

            foreach ($sports as $sport) {
                if (!isset($sport['id'])) {
                    Log::warning('Skipping sport with missing ID', ['sport' => $sport]);
                    continue;
                }

                try {
                    Log::info('Fetching leagues for sport', [
                        'sport_id' => $sport['id'],
                        'sport_name' => $sport['name'] ?? 'Unknown'
                    ]);

                    $apiResponse = $pinnacleService->getLeagues($sport['id']);

                    // Validate API response structure
                    if (!is_array($apiResponse) || !isset($apiResponse['leagues'])) {
                        Log::warning('Invalid leagues API response structure', [
                            'sport_id' => $sport['id'],
                            'response_type' => gettype($apiResponse),
                            'has_leagues_key' => is_array($apiResponse) ? isset($apiResponse['leagues']) : false
                        ]);
                        continue;
                    }

                    $leaguesData = $apiResponse['leagues'];

                    // Validate leagues data
                    if (!is_array($leaguesData)) {
                        Log::warning('Leagues data is not an array', [
                            'sport_id' => $sport['id'],
                            'leagues_type' => gettype($leaguesData)
                        ]);
                        continue;
                    }

                    if (empty($leaguesData)) {
                        Log::info('No leagues returned for sport', [
                            'sport_id' => $sport['id'],
                            'sport_name' => $sport['name'] ?? 'Unknown'
                        ]);
                        continue;
                    }

                    // Validate API response structure (PinnacleService now handles this, but double-check)
                    if (!is_array($apiResponse) || !isset($apiResponse['leagues'])) {
                        Log::warning('Invalid leagues API response structure', [
                            'sport_id' => $sport['id'],
                            'response_type' => gettype($apiResponse),
                            'has_leagues_key' => is_array($apiResponse) ? isset($apiResponse['leagues']) : false
                        ]);
                        continue;
                    }

                    $leaguesData = $apiResponse['leagues'];

                    // Validate leagues data
                    if (!is_array($leaguesData)) {
                        Log::warning('Leagues data is not an array', [
                            'sport_id' => $sport['id'],
                            'leagues_type' => gettype($leaguesData)
                        ]);
                        continue;
                    }

                    if (empty($leaguesData)) {
                        Log::info('No leagues returned for sport', [
                            'sport_id' => $sport['id'],
                            'sport_name' => $sport['name'] ?? 'Unknown'
                        ]);
                        continue;
                    }

                    // Implement intelligent league limiting based on sport type and activity
                    $maxLeaguesPerSport = $this->getOptimalLeagueLimit($sport['id'], count($leaguesData));

                    if (count($leaguesData) > $maxLeaguesPerSport) {
                        // Sort leagues by priority (active/popular leagues first)
                        $leaguesData = $this->prioritizeLeagues($leaguesData, $sport['id']);
                        $leaguesData = array_slice($leaguesData, 0, $maxLeaguesPerSport);

                        Log::info('Limited leagues for sport based on priority', [
                            'sport_id' => $sport['id'],
                            'original_count' => count($apiResponse['leagues']),
                            'limited_to' => $maxLeaguesPerSport,
                            'strategy' => $this->getLeagueStrategy($sport['id'])
                        ]);
                    }

                    Log::info('Fetched leagues for sport', [
                        'sport_id' => $sport['id'],
                        'leagues_count' => count($leaguesData),
                        'sample_league_data' => $leaguesData[0] ?? null
                    ]);

                    $importedCount = 0;
                    $skippedCount = 0;

                    foreach ($leaguesData as $leagueData) {
                        // Validate required data with null coalescing
                        $leagueId = $leagueData['id'] ?? null;
                        $leagueName = $leagueData['name'] ?? null;

                        if (!$leagueId || !$leagueName) {
                            Log::warning('Skipping league with missing required data', [
                                'league_id' => $leagueId,
                                'league_name' => $leagueName,
                                'league_data' => $leagueData
                            ]);
                            continue;
                        }

                        try {
                            Log::debug('Attempting to save league', [
                                'league_id' => $leagueId,
                                'league_name' => $leagueName,
                                'sport_id' => $sport['id']
                            ]);

                            // Use camelCase column names as per League model
                            $league = League::updateOrCreate(
                                ['pinnacleId' => $leagueId],
                                [
                                    'name' => $leagueName,
                                    'sportId' => $sport['id'], // Use the sport ID we're currently processing
                                    'isActive' => true,
                                    'lastPinnacleSync' => now()
                                ]
                            );

                            if ($league->wasRecentlyCreated) {
                                $importedCount++;
                                Log::debug('Created new league', [
                                    'league_id' => $league->id,
                                    'pinnacle_id' => $league->pinnacleId,
                                    'name' => $league->name
                                ]);
                            } else {
                                $skippedCount++;
                                Log::debug('Updated existing league', [
                                    'league_id' => $league->id,
                                    'pinnacle_id' => $league->pinnacleId,
                                    'name' => $league->name
                                ]);
                            }

                            if ($league->wasRecentlyCreated) {
                                $importedCount++;
                                Log::debug('Created new league', [
                                    'pinnacle_id' => $leagueData['id'],
                                    'name' => $leagueData['name'],
                                    'sport_id' => $sport['id']
                                ]);
                            } else {
                                $skippedCount++;
                                Log::debug('Updated existing league', [
                                    'pinnacle_id' => $leagueData['id'],
                                    'name' => $leagueData['name']
                                ]);
                            }

                        } catch (\Exception $e) {
                            Log::error('Failed to save individual league', [
                                'league_data' => $leagueData,
                                'error' => $e->getMessage()
                            ]);
                            // Continue with other leagues instead of failing the whole job
                        }
                    }

                    $totalImportedCount += $importedCount;
                    $totalSkippedCount += $skippedCount;
                    $sportsProcessed++;

                    Log::info('Processed leagues for sport', [
                        'sport_id' => $sport['id'],
                        'new_leagues' => $importedCount,
                        'updated_leagues' => $skippedCount
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to process leagues for sport', [
                        'sport_id' => $sport['id'],
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other sports
                }
            }

            Log::info('Leagues import completed', [
                'sports_processed' => $sportsProcessed,
                'newly_imported' => $totalImportedCount,
                'updated_existing' => $totalSkippedCount,
                'total_leagues_in_db' => League::count()
            ]);

            return $totalImportedCount;

        } catch (\Exception $e) {
            Log::error('Failed to import leagues from Pinnacle', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function backfillTeamLeagueIds(): void
    {
        Log::info('Starting team leagueId backfill');

        $teamsWithoutLeague = Team::whereNull('leagueId')->get();
        $backfilledCount = 0;

        Log::info('Found teams without leagueId', [
            'count' => $teamsWithoutLeague->count()
        ]);

        foreach ($teamsWithoutLeague as $team) {
            // Find leagueId from existing matches
            $leagueId = SportsMatch::where(function($query) use ($team) {
                $query->where('home_team_id', $team->id)
                      ->orWhere('away_team_id', $team->id);
            })
            ->whereNotNull('leagueId')
            ->value('leagueId');

            if ($leagueId) {
                // Only update if the league actually exists in the leagues table
                if (League::where('id', $leagueId)->exists()) {
                    $team->update(['leagueId' => $leagueId]);
                    $backfilledCount++;

                    Log::debug('Backfilled team leagueId', [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'league_id' => $leagueId
                    ]);
                } else {
                    Log::warning('Skipping backfill - league does not exist in leagues table', [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'league_id' => $leagueId
                    ]);
                }
            } else {
                Log::warning('Could not backfill leagueId for team - no matches found', [
                    'team_id' => $team->id,
                    'team_name' => $team->name
                ]);
            }
        }

        Log::info('Team leagueId backfill completed', [
            'total_backfilled' => $backfilledCount,
            'total_without_league' => $teamsWithoutLeague->count()
        ]);
    }

    /**
     * Get optimal league limit based on sport type and data characteristics
     */
    private function getOptimalLeagueLimit(int $sportId, int $availableLeagues): int
    {
        // Sport-specific limits based on typical league counts and activity
        // Updated to 2000 leagues limit for all sports to match Pinnacle's extensive coverage
        $sportLimits = [
            1 => 2000,   // Soccer - major leagues are active
            2 => 2000,   // Tennis - many tournaments but need coverage
            3 => 2000,   // Basketball - NBA + major international
            4 => 2000,   // Hockey - NHL + international
            5 => 2000,   // Volleyball - major leagues
            6 => 2000,   // Handball - major leagues
            7 => 2000,   // American Football - NFL + major leagues
        ];

        $defaultLimit = $sportLimits[$sportId] ?? 2000;

        // If API returns fewer leagues than our limit, use all available
        return min($availableLeagues, $defaultLimit);
    }

    /**
     * Get league import strategy for logging
     */
    private function getLeagueStrategy(int $sportId): string
    {
        $strategies = [
            1 => 'Soccer: Major leagues prioritized',
            2 => 'Tennis: Active tournaments prioritized',
            3 => 'Basketball: NBA + international leagues',
            4 => 'Hockey: NHL + major international',
            5 => 'Volleyball: Professional leagues',
            6 => 'Handball: International competitions',
            7 => 'Football: NFL + college football',
        ];

        return $strategies[$sportId] ?? 'Standard league prioritization';
    }

    /**
     * Prioritize leagues based on activity indicators and sport-specific logic
     */
    private function prioritizeLeagues(array $leagues, int $sportId): array
    {
        // Simple prioritization based on league name patterns
        // More active/popular leagues get higher priority

        usort($leagues, function($a, $b) use ($sportId) {
            $scoreA = $this->getLeaguePriorityScore($a, $sportId);
            $scoreB = $this->getLeaguePriorityScore($b, $sportId);

            return $scoreB <=> $scoreA; // Higher score first
        });

        return $leagues;
    }

    /**
     * Calculate priority score for league based on name and sport
     */
    private function getLeaguePriorityScore(array $league, int $sportId): int
    {
        $name = strtolower($league['name'] ?? '');
        $score = 0;

        // Sport-specific high-priority keywords
        $priorityKeywords = [
            1 => ['premier', 'champions', 'europa', 'bundesliga', 'laliga', 'serie a', 'ligue 1'], // Soccer
            2 => ['atp', 'wta', 'grand slam', 'masters', 'open'], // Tennis
            3 => ['nba', 'euroleague', 'acbl'], // Basketball
            4 => ['nhl', 'khl', 'world championship'], // Hockey
            7 => ['nfl', 'super bowl', 'ncaa'], // American Football
        ];

        $keywords = $priorityKeywords[$sportId] ?? [];

        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                $score += 10;
            }
        }

        // General priority indicators
        if (str_contains($name, 'cup') || str_contains($name, 'trophy')) {
            $score += 5;
        }

        if (str_contains($name, 'championship') || str_contains($name, 'league')) {
            $score += 3;
        }

        // Length bonus (shorter names often indicate major leagues)
        if (strlen($name) < 20) {
            $score += 2;
        }

        return $score;
    }
}
