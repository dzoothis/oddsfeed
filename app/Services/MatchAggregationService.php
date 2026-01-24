<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Provider-Agnostic Match Aggregation Service
 * 
 * Aggregates live matches from multiple providers (Pinnacle, Odds-Feed, API-Football)
 * without treating any provider as primary or authoritative.
 * 
 * Key Principles:
 * - UNION of all providers (not intersection)
 * - Match appears ONCE even if multiple providers have it
 * - Live status if ANY provider says live
 * - No provider priority logic
 */
class MatchAggregationService
{
    protected $normalizationService;

    public function __construct()
    {
        $this->normalizationService = new MatchNormalizationService();
    }

    /**
     * Aggregate matches from multiple providers
     * 
     * @param array $pinnacleMatches Matches from Pinnacle API
     * @param array $oddsFeedMatches Matches from Odds-Feed API (if available)
     * @param array $apiFootballMatches Matches from API-Football API
     * @return array Unified, deduplicated matches
     */
    public function aggregateMatches(
        array $pinnacleMatches = [],
        array $oddsFeedMatches = [],
        array $apiFootballMatches = []
    ): array {
        Log::info('Starting match aggregation', [
            'pinnacle_count' => count($pinnacleMatches),
            'odds_feed_count' => count($oddsFeedMatches),
            'api_football_count' => count($apiFootballMatches)
        ]);

        // Step 1: Normalize all matches into common structure
        $normalizedMatches = [];
        
        foreach ($pinnacleMatches as $match) {
            $normalized = $this->normalizePinnacleMatch($match);
            if ($normalized) {
                $normalizedMatches[] = $normalized;
            }
        }

        foreach ($oddsFeedMatches as $match) {
            $normalized = $this->normalizeOddsFeedMatch($match);
            if ($normalized) {
                $normalizedMatches[] = $normalized;
            }
        }

        foreach ($apiFootballMatches as $match) {
            $normalized = $this->normalizeApiFootballMatch($match);
            if ($normalized) {
                $normalizedMatches[] = $normalized;
            }
        }

        Log::info('Normalized matches from all providers', [
            'total_normalized' => count($normalizedMatches)
        ]);

        // Step 2: Deduplicate matches
        $deduplicatedMatches = $this->deduplicateMatches($normalizedMatches);

        Log::info('Match aggregation completed', [
            'original_count' => count($normalizedMatches),
            'deduplicated_count' => count($deduplicatedMatches),
            'duplicates_removed' => count($normalizedMatches) - count($deduplicatedMatches)
        ]);

        return $deduplicatedMatches;
    }

    /**
     * Deduplicate matches using identity rules
     * 
     * Two matches represent the same real-world event if:
     * - Normalized home team matches
     * - Normalized away team matches
     * - Normalized league matches
     * - Match start time within ±5 minutes tolerance
     * 
     * @param array $matches Normalized matches from all providers
     * @return array Deduplicated matches
     */
    protected function deduplicateMatches(array $matches): array
    {
        $deduplicated = [];
        $seenMatches = [];

        foreach ($matches as $match) {
            // Generate match identity key
            $identityKey = $this->generateMatchIdentityKey($match);

            if (!isset($seenMatches[$identityKey])) {
                // First occurrence - add to result
                $seenMatches[$identityKey] = $match;
                $deduplicated[] = $match;
            } else {
                // Duplicate found - merge data from all providers
                $existingMatch = $seenMatches[$identityKey];
                $mergedMatch = $this->mergeMatchData($existingMatch, $match);
                
                // Update the existing match in result array
                $index = array_search($existingMatch, $deduplicated, true);
                if ($index !== false) {
                    $deduplicated[$index] = $mergedMatch;
                    $seenMatches[$identityKey] = $mergedMatch;
                }
            }
        }

        return $deduplicated;
    }

    /**
     * Generate identity key for match deduplication
     * 
     * @param array $match Normalized match
     * @return string Identity key
     */
    protected function generateMatchIdentityKey(array $match): string
    {
        $homeTeam = $this->normalizationService->normalizeTeamName($match['home_team'] ?? '');
        $awayTeam = $this->normalizationService->normalizeTeamName($match['away_team'] ?? '');
        $league = $this->normalizationService->normalizeLeagueName($match['league_name'] ?? '');
        
        // Normalize start time to 5-minute bucket for tolerance
        $startTime = $match['start_time'] ?? null;
        $timeBucket = $this->normalizeStartTime($startTime);

        // Generate key (handle home/away order variations)
        $key1 = "{$homeTeam}|{$awayTeam}|{$league}|{$timeBucket}";
        $key2 = "{$awayTeam}|{$homeTeam}|{$league}|{$timeBucket}";
        
        // Use lexicographically smaller key for consistency
        return min($key1, $key2);
    }

    /**
     * Normalize start time to 5-minute bucket for tolerance matching
     * 
     * @param mixed $startTime Start time (Carbon, string, or null)
     * @return string Time bucket identifier
     */
    protected function normalizeStartTime($startTime): string
    {
        if (!$startTime) {
            return 'unknown';
        }

        try {
            if ($startTime instanceof Carbon) {
                $timestamp = $startTime->timestamp;
            } elseif (is_string($startTime)) {
                $timestamp = strtotime($startTime);
            } else {
                return 'unknown';
            }

            // Round to 5-minute bucket
            $bucket = floor($timestamp / 300) * 300;
            return date('Y-m-d-H-i', $bucket);
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Merge match data from multiple providers
     * 
     * Combines data from all providers, prioritizing:
     * - Live status if ANY provider says live
     * - Most recent scores
     * - All available odds sources
     * 
     * @param array $match1 First match
     * @param array $match2 Second match (duplicate)
     * @return array Merged match
     */
    protected function mergeMatchData(array $match1, array $match2): array
    {
        $merged = $match1;

        // Merge providers list
        $merged['providers'] = array_unique(array_merge(
            $match1['providers'] ?? [],
            $match2['providers'] ?? []
        ));

        // Live status: If ANY provider says live, match is live
        $isLive1 = $this->isMatchLive($match1);
        $isLive2 = $this->isMatchLive($match2);
        $merged['is_live'] = $isLive1 || $isLive2;

        // Use most recent live status
        if ($isLive2) {
            $merged['live_status_id'] = $match2['live_status_id'] ?? $merged['live_status_id'] ?? 0;
            $merged['betting_availability'] = $match2['betting_availability'] ?? $merged['betting_availability'] ?? 'prematch';
        }

        // Merge scores (prefer non-zero scores, most recent)
        if (($match2['home_score'] ?? 0) > 0 || ($match2['away_score'] ?? 0) > 0) {
            $merged['home_score'] = $match2['home_score'] ?? $merged['home_score'] ?? 0;
            $merged['away_score'] = $match2['away_score'] ?? $merged['away_score'] ?? 0;
        }

        // Merge match duration/period
        if (!empty($match2['match_duration'])) {
            $merged['match_duration'] = $match2['match_duration'];
        }
        if (!empty($match2['period'])) {
            $merged['period'] = $match2['period'];
        }

        // Merge odds sources
        $merged['odds_sources'] = array_unique(array_merge(
            $match1['odds_sources'] ?? [],
            $match2['odds_sources'] ?? []
        ));

        // Use most recent update time
        $update1 = $match1['last_updated'] ?? null;
        $update2 = $match2['last_updated'] ?? null;
        if ($update2 && (!$update1 || strtotime($update2) > strtotime($update1))) {
            $merged['last_updated'] = $update2;
        }

        // Merge metadata
        $merged['metadata'] = array_merge(
            $match1['metadata'] ?? [],
            $match2['metadata'] ?? []
        );

        return $merged;
    }

    /**
     * Check if match is live based on any provider
     * 
     * LIVE STATUS RESOLUTION LOGIC:
     * - Match is LIVE if ANY provider marks it as live
     * - Status codes: LIVE, IN_PLAY, 1H, 2H, HT, or equivalent
     * - Do NOT downgrade if another provider says scheduled/finished
     * - Trust the most recent/live status from any provider
     * 
     * @param array $match Normalized match
     * @return bool True if match is live
     */
    protected function isMatchLive(array $match): bool
    {
        // Check live_status_id (from any provider)
        $liveStatusId = $match['live_status_id'] ?? 0;
        if ($liveStatusId > 0) {
            return true;
        }

        // Check betting_availability
        $bettingAvailability = $match['betting_availability'] ?? 'prematch';
        if ($bettingAvailability === 'live') {
            return true;
        }

        // Check is_live flag (set during aggregation)
        if (isset($match['is_live']) && $match['is_live']) {
            return true;
        }

        // Check if match has started and has scores (indicates live)
        $startTime = $match['start_time'] ?? null;
        $hasScores = ($match['home_score'] ?? 0) > 0 || ($match['away_score'] ?? 0) > 0;
        
        if ($startTime && $hasScores) {
            try {
                $start = $startTime instanceof Carbon ? $startTime : Carbon::parse($startTime);
                if ($start->isPast()) {
                    return true;
                }
            } catch (\Exception $e) {
                // Ignore parse errors
            }
        }

        // Check period/status indicators from API-Football
        $period = $match['period'] ?? '';
        $livePeriods = ['First Half', 'Second Half', 'Halftime', 'Extra Time', 'Penalty', '1H', '2H', 'HT', 'LIVE'];
        if (in_array($period, $livePeriods)) {
            return true;
        }

        // Check metadata for status codes
        $metadata = $match['metadata'] ?? [];
        $statusShort = $metadata['status_short'] ?? '';
        $statusLong = $metadata['status_long'] ?? '';
        if (in_array($statusShort, ['1H', '2H', 'HT', 'LIVE', 'IN_PLAY']) || 
            in_array($statusLong, ['First Half', 'Second Half', 'Halftime', 'Extra Time', 'Penalty', 'Live'])) {
            return true;
        }

        return false;
    }

    /**
     * Normalize Pinnacle match to common structure
     * 
     * @param array $match Raw Pinnacle match
     * @return array|null Normalized match or null if invalid
     */
    protected function normalizePinnacleMatch(array $match): ?array
    {
        try {
            $startTime = isset($match['starts']) ? Carbon::parse($match['starts']) : null;
            
            return [
                'provider' => 'pinnacle',
                'providers' => ['pinnacle'],
                'event_id' => $match['event_id'] ?? null,
                'sport_id' => $match['sport_id'] ?? 1,
                'home_team' => $match['home'] ?? 'Unknown',
                'away_team' => $match['away'] ?? 'Unknown',
                'league_id' => $match['league_id'] ?? null,
                'league_name' => $match['league_name'] ?? 'Unknown',
                'start_time' => $startTime,
                'live_status_id' => $match['live_status_id'] ?? 0,
                'betting_availability' => $match['betting_availability'] ?? 'prematch',
                'home_score' => $match['home_score'] ?? 0,
                'away_score' => $match['away_score'] ?? 0,
                'match_duration' => $match['clock'] ?? $match['period'] ?? null,
                'period' => $match['period'] ?? null,
                'has_open_markets' => $match['is_have_open_markets'] ?? false,
                'is_live' => ($match['live_status_id'] ?? 0) > 0,
                'odds_sources' => ['pinnacle'],
                'last_updated' => isset($match['last']) ? date('Y-m-d H:i:s', $match['last']) : now()->toDateTimeString(),
                'metadata' => [
                    'pinnacle_event_id' => $match['event_id'] ?? null,
                    'pinnacle_league_id' => $match['league_id'] ?? null,
                ]
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to normalize Pinnacle match', [
                'error' => $e->getMessage(),
                'match' => $match
            ]);
            return null;
        }
    }

    /**
     * Normalize Odds-Feed match to common structure
     * 
     * Expected Odds-Feed structure (flexible to handle variations):
     * - id, match_id, event_id: Match identifier
     * - home_team, home, homeTeam: Home team name
     * - away_team, away, awayTeam: Away team name
     * - league, league_name, competition: League name
     * - start_time, starts, scheduled_time: Match start time
     * - status, live_status, is_live: Live status
     * - home_score, score_home: Home team score
     * - away_score, score_away: Away team score
     * 
     * @param array $match Raw Odds-Feed match
     * @return array|null Normalized match or null if invalid
     */
    protected function normalizeOddsFeedMatch(array $match): ?array
    {
        try {
            // Extract match identifier
            $eventId = $match['id'] ?? $match['match_id'] ?? $match['event_id'] ?? null;
            if (!$eventId) {
                Log::debug('Odds-Feed match missing identifier', ['match' => $match]);
                return null;
            }

            // Extract team names (flexible field names)
            $homeTeam = $match['home_team'] ?? $match['home'] ?? $match['homeTeam'] ?? '';
            $awayTeam = $match['away_team'] ?? $match['away'] ?? $match['awayTeam'] ?? '';
            
            if (empty($homeTeam) || empty($awayTeam)) {
                Log::debug('Odds-Feed match missing team names', [
                    'event_id' => $eventId,
                    'match' => $match
                ]);
                return null;
            }

            // Extract league name
            $leagueName = $match['league'] ?? $match['league_name'] ?? $match['competition'] ?? 'Unknown';

            // Extract start time (handle multiple formats)
            $startTime = null;
            $startTimeRaw = $match['start_time'] ?? $match['starts'] ?? $match['scheduled_time'] ?? null;
            if ($startTimeRaw) {
                try {
                    $startTime = Carbon::parse($startTimeRaw);
                } catch (\Exception $e) {
                    Log::debug('Odds-Feed match invalid start time', [
                        'event_id' => $eventId,
                        'start_time_raw' => $startTimeRaw,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Determine live status
            $isLive = false;
            $liveStatusId = 0;
            
            $status = $match['status'] ?? $match['live_status'] ?? null;
            $isLiveFlag = $match['is_live'] ?? false;
            
            if ($isLiveFlag === true || $status === 'live' || $status === 'in_play' || $status === 'in-progress') {
                $isLive = true;
                $liveStatusId = 1;
            } elseif ($status === 'finished' || $status === 'completed' || $status === 'ft') {
                $liveStatusId = 2;
            } elseif ($status === 'scheduled' || $status === 'upcoming') {
                $liveStatusId = 0;
            }

            // Extract scores
            $homeScore = (int)($match['home_score'] ?? $match['score_home'] ?? $match['goals']['home'] ?? 0);
            $awayScore = (int)($match['away_score'] ?? $match['score_away'] ?? $match['goals']['away'] ?? 0);

            // Extract sport ID (default to Soccer if not provided)
            $sportId = (int)($match['sport_id'] ?? $match['sportId'] ?? 1);

            // Extract league ID
            $leagueId = $match['league_id'] ?? $match['leagueId'] ?? null;

            // Build normalized match structure
            $normalized = [
                'event_id' => (string)$eventId,
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'league_name' => $leagueName,
                'league_id' => $leagueId,
                'sport_id' => $sportId,
                'start_time' => $startTime ? $startTime->toIso8601String() : null,
                'live_status_id' => $liveStatusId,
                'is_live' => $isLive,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'betting_availability' => $isLive ? 'live' : 'prematch',
                'providers' => ['odds_feed'],
                'odds_sources' => ['odds_feed'],
                'metadata' => [
                    'source' => 'odds_feed',
                    'original_data' => $match
                ]
            ];

            return $normalized;
        } catch (\Exception $e) {
            Log::error('Error normalizing Odds-Feed match', [
                'match' => $match,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Normalize API-Football match to common structure
     * 
     * @param array $match Raw API-Football fixture
     * @return array|null Normalized match or null if invalid
     */
    protected function normalizeApiFootballMatch(array $match): ?array
    {
        try {
            $fixture = $match['fixture'] ?? $match;
            $teams = $match['teams'] ?? [];
            $league = $match['league'] ?? [];
            $goals = $match['goals'] ?? [];
            $status = $fixture['status'] ?? [];

            $startTime = isset($fixture['date']) ? Carbon::parse($fixture['date']) : null;
            
            // Determine live status from API-Football status codes
            $statusShort = $status['short'] ?? '';
            $statusLong = $status['long'] ?? '';
            $isLive = in_array($statusShort, ['1H', '2H', 'HT', 'LIVE']) || 
                      in_array($statusLong, ['First Half', 'Second Half', 'Halftime', 'Extra Time', 'Penalty']);
            
            $liveStatusId = $isLive ? 1 : 0;
            $bettingAvailability = $isLive ? 'live' : 'prematch';

            return [
                'provider' => 'api_football',
                'providers' => ['api_football'],
                'event_id' => $fixture['id'] ?? null,
                'sport_id' => $league['id'] ?? 1, // API-Football league ID, may need mapping
                'home_team' => $teams['home']['name'] ?? 'Unknown',
                'away_team' => $teams['away']['name'] ?? 'Unknown',
                'league_id' => $league['id'] ?? null,
                'league_name' => $league['name'] ?? 'Unknown',
                'start_time' => $startTime,
                'live_status_id' => $liveStatusId,
                'betting_availability' => $bettingAvailability,
                'home_score' => $goals['home'] ?? 0,
                'away_score' => $goals['away'] ?? 0,
                'match_duration' => $status['elapsed'] ?? null,
                'period' => $statusLong,
                'has_open_markets' => true, // Assume true for API-Football
                'is_live' => $isLive,
                'odds_sources' => ['api_football'],
                'last_updated' => now()->toDateTimeString(),
                'metadata' => [
                    'api_football_fixture_id' => $fixture['id'] ?? null,
                    'api_football_league_id' => $league['id'] ?? null,
                    'status_short' => $statusShort,
                    'status_long' => $statusLong,
                ]
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to normalize API-Football match', [
                'error' => $e->getMessage(),
                'match' => $match
            ]);
            return null;
        }
    }
}

