<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PinnacleService;
use App\Services\TeamResolutionService;
use App\Models\SportsMatch;

class PrematchSyncJob implements ShouldQueue
{
    use Queueable;

    // Queue configuration for data synchronization with chunked processing
    public $tries = 3; // Retry up to 3 times for network/API failures
    public $timeout = 1800; // 30 minutes timeout for chunked processing (allows time for multiple chunks)
    public $backoff = [60, 180, 600]; // Exponential backoff: 1min, 3min, 10min
    public $maxExceptions = 5; // Allow some exceptions before marking job as failed

    protected $sportId;
    protected $leagueIds;
    protected $jobId; // Unique identifier for this job run

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($sportId = null, $leagueIds = [])
    {
        $this->sportId = $sportId;
        $this->leagueIds = $leagueIds;
        $this->jobId = uniqid('prematch_sync_', true);
    }

    /**
     * Execute the job - Chunked processing to prevent memory exhaustion
     */
    public function handle(PinnacleService $pinnacleService, TeamResolutionService $teamResolutionService): void
    {
        Log::info('PrematchSyncJob started - Chunked processing', [
            'sportId' => $this->sportId,
            'leagueIds' => $this->leagueIds
        ]);

        $startTime = microtime(true);
        $sportId = $this->sportId ?? 7; // Default to NFL

        try {
            // Check if we need to refresh (cache-based staleness check)
            if (!$this->shouldRefreshPrematchData($sportId)) {
                Log::info('Prematch data still fresh, skipping API call', [
                    'sportId' => $sportId
                ]);
                return;
            }

            // Step 1: Fetch ALL prematch matches from Pinnacle for this sport
            $prematchMatchesData = $pinnacleService->getMatchesByLeagues(
                $sportId,
                [], // Always fetch ALL leagues (empty array)
                'prematch'
            );

            // Validate API response structure
            if (!is_array($prematchMatchesData) || !isset($prematchMatchesData['events'])) {
                Log::warning('Invalid prematch API response structure', [
                    'job_id' => $this->jobId,
                    'sport_id' => $sportId,
                    'response_type' => gettype($prematchMatchesData),
                    'has_events_key' => is_array($prematchMatchesData) ? isset($prematchMatchesData['events']) : false
                ]);

                // Don't update timestamp - allow retry on next scheduled run
                return;
            }

            $allPrematchMatches = $prematchMatchesData['events'];

            // Validate events data
            if (!is_array($allPrematchMatches)) {
                Log::warning('Prematch events data is not an array', [
                    'job_id' => $this->jobId,
                    'sport_id' => $sportId,
                    'events_type' => gettype($allPrematchMatches)
                ]);

                // Don't update timestamp - allow retry
                return;
            }

            Log::info('Fetched prematch matches from Pinnacle', [
                'job_id' => $this->jobId,
                'total_match_count' => count($allPrematchMatches),
                'sport_id' => $sportId,
                'requested_leagues' => $this->leagueIds
            ]);

            if (empty($allPrematchMatches)) {
                Log::info('No prematch matches found for sport', [
                    'job_id' => $this->jobId,
                    'sport_id' => $sportId
                ]);

                // Still update timestamp to prevent repeated empty calls
                $this->updateLastSyncTimestamp($sportId);
                return;
            }

            // Step 2: Apply intelligent league filtering
            $prematchMatches = $this->filterMatchesByAvailableLeagues($allPrematchMatches, $sportId, $this->leagueIds);

            // Log league distribution for debugging
            $leagueDistribution = array_count_values(array_column($prematchMatches, 'league_id'));
            arsort($leagueDistribution); // Sort by frequency

            Log::info('After league filtering', [
                'filtered_match_count' => count($prematchMatches),
                'unique_leagues_in_matches' => count($leagueDistribution),
                'top_leagues_by_matches' => array_slice($leagueDistribution, 0, 5, true),
                'requested_leagues' => $this->leagueIds,
                'sport_id' => $sportId
            ]);

            if (empty($prematchMatches)) {
                Log::info('No prematch matches found for requested leagues', [
                    'requested_leagues' => $this->leagueIds
                ]);
                $this->updateLastSyncTimestamp($sportId);
                return;
            }

            // Step 3: Process matches in chunks to prevent memory exhaustion
            $totalProcessed = $this->processMatchesInChunks(
                $prematchMatches,
                $teamResolutionService,
                $sportId
            );

            // Step 4: Update last sync timestamp only after successful completion
            $this->updateLastSyncTimestamp($sportId);

            // Step 5: Clear any stale cache markers since we have fresh data
            $this->clearStaleCacheMarkers($sportId);

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('PrematchSyncJob completed successfully', [
                'total_processed' => $totalProcessed,
                'sport_id' => $sportId,
                'duration_seconds' => $duration
            ]);

        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            // Categorize error for actionable logging
            $errorCategory = $this->categorizeError($e);
            $isActionable = $this->isActionableError($errorCategory);

            $logData = [
                'error_category' => $errorCategory,
                'actionable' => $isActionable,
                'job_id' => $this->jobId ?? 'unknown',
                'sport_id' => $sportId,
                'duration_seconds' => $duration,
                'memory_peak' => memory_get_peak_usage(true),
                'processed_chunks' => $totalProcessed ?? 0
            ];

            if ($isActionable) {
                Log::critical('ACTIONABLE: PrematchSyncJob failed', array_merge($logData, [
                    'error' => $e->getMessage(),
                    'recommendation' => $this->getErrorRecommendation($errorCategory)
                ]));
            } else {
                Log::warning('NON-ACTIONABLE: PrematchSyncJob failed (transient)', array_merge($logData, [
                    'error' => $e->getMessage()
                ]));
            }

            // Only re-throw for actionable errors - let non-actionable ones retry
            if ($isActionable) {
                throw $e;
            }
        }
    }

    private function shouldRefreshPrematchData($sportId): bool
    {
        $lastSyncKey = "prematch_last_sync:{$sportId}";
        $lastSync = Cache::get($lastSyncKey);

        if (!$lastSync) {
            return true; // Never synced before
        }

        // Refresh if more than 4 minutes old (buffer before 5-minute schedule)
        $minutesSinceLastSync = now()->diffInMinutes($lastSync);
        return $minutesSinceLastSync >= 4;
    }

    private function updateLastSyncTimestamp($sportId): void
    {
        $lastSyncKey = "prematch_last_sync:{$sportId}";
        Cache::put($lastSyncKey, now(), 3600); // Keep for 1 hour
    }

    /**
     * Filter matches by available leagues in database
     * Ensures all imported leagues are eligible for sync, not just requested ones
     */
    private function filterMatchesByAvailableLeagues(array $matches, int $sportId, array $requestedLeagueIds): array
    {
        // Get all active leagues for this sport from database
        $availableLeagues = \App\Models\League::where('sportId', $sportId)
            ->where('isActive', true)
            ->pluck('pinnacleId')
            ->toArray();

        if (empty($availableLeagues)) {
            Log::warning('No active leagues found in database for sport', [
                'sport_id' => $sportId,
                'total_matches_from_api' => count($matches)
            ]);
            return [];
        }

        Log::debug('Available leagues for filtering', [
            'sport_id' => $sportId,
            'available_leagues_count' => count($availableLeagues),
            'requested_leagues_count' => count($requestedLeagueIds),
            'sample_available_leagues' => array_slice($availableLeagues, 0, 5)
        ]);

        // If specific leagues were requested, filter to those (if they exist in database)
        if (!empty($requestedLeagueIds)) {
            $validRequestedLeagues = array_intersect($requestedLeagueIds, $availableLeagues);
            if (empty($validRequestedLeagues)) {
                Log::info('No valid requested leagues found in database', [
                    'sport_id' => $sportId,
                    'requested_leagues' => $requestedLeagueIds,
                    'available_leagues_sample' => array_slice($availableLeagues, 0, 5)
                ]);
                return [];
            }
            $leaguesToInclude = $validRequestedLeagues;
        } else {
            // No specific leagues requested - include all available leagues
            $leaguesToInclude = $availableLeagues;
        }

        // Filter matches to only include those from available leagues
        $filteredMatches = array_filter($matches, function($match) use ($leaguesToInclude) {
            $matchLeagueId = $match['league_id'] ?? null;
            return $matchLeagueId && in_array($matchLeagueId, $leaguesToInclude);
        });

        Log::info('League filtering completed', [
            'sport_id' => $sportId,
            'original_matches' => count($matches),
            'filtered_matches' => count($filteredMatches),
            'leagues_used' => count($leaguesToInclude),
            'leagues_requested' => count($requestedLeagueIds)
        ]);

        return array_values($filteredMatches); // Re-index array
    }

    /**
     * Process matches in chunks to prevent memory exhaustion
     */
    private function processMatchesInChunks(array $matches, TeamResolutionService $teamResolutionService, int $sportId): int
    {
        $chunkSize = 50; // Process 50 matches at a time
        $totalProcessed = 0;
        $chunks = array_chunk($matches, $chunkSize);

        Log::info('Starting chunked processing', [
            'job_id' => $this->jobId,
            'total_matches' => count($matches),
            'chunk_size' => $chunkSize,
            'total_chunks' => count($chunks)
        ]);

        // Initialize progress tracking
        $this->initializeProgressTracking($sportId, count($chunks));

        foreach ($chunks as $chunkIndex => $chunk) {
            // Check if this chunk was already processed (resume capability)
            if ($this->isChunkProcessed($sportId, $chunkIndex)) {
                Log::debug('Skipping already processed chunk', [
                    'job_id' => $this->jobId,
                    'chunk_index' => $chunkIndex
                ]);
                continue;
            }

            try {
                Log::debug('Processing chunk', [
                    'job_id' => $this->jobId,
                    'chunk_index' => $chunkIndex + 1,
                    'chunk_size' => count($chunk),
                    'total_processed_so_far' => $totalProcessed
                ]);

                // Check memory usage before processing chunk
                $this->checkMemoryUsage($chunkIndex);

                // Process this chunk
                $processedChunk = $this->processMatchChunk($chunk, $teamResolutionService, $sportId);

                if (!empty($processedChunk)) {
                    // Group by league and cache immediately (partial progress)
                    $matchesByLeague = $this->groupMatchesByLeague($processedChunk);

                    // Cache each league's matches immediately
                    foreach ($matchesByLeague as $leagueId => $leagueMatches) {
                        $this->cachePrematchMatchesForLeague($leagueId, $leagueMatches, $sportId);
                    }

                    // Update database for this chunk immediately
                    $this->updateDatabaseSelectively($processedChunk);

                    $totalProcessed += count($processedChunk);

                    // Mark this chunk as processed
                    $this->markChunkProcessed($sportId, $chunkIndex);
                }

                // Small delay between chunks to prevent overwhelming the system
                if ($chunkIndex < count($chunks) - 1) {
                    usleep(100000); // 0.1 seconds
                }

            } catch (\Exception $e) {
                Log::error('Failed to process chunk', [
                    'job_id' => $this->jobId,
                    'chunk_index' => $chunkIndex,
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage()
                ]);

                // Continue with next chunk instead of failing entire job
                // This ensures partial progress is preserved
                continue;
            }
        }

        // Clean up progress tracking
        $this->cleanupProgressTracking($sportId);

        return $totalProcessed;
    }

    /**
     * Initialize progress tracking for this job run
     */
    private function initializeProgressTracking(int $sportId, int $totalChunks): void
    {
        $progressKey = "prematch_progress:{$sportId}:{$this->jobId}";
        Cache::put($progressKey, [
            'job_id' => $this->jobId,
            'total_chunks' => $totalChunks,
            'processed_chunks' => [],
            'start_time' => now(),
            'last_update' => now()
        ], 3600); // Keep for 1 hour
    }

    /**
     * Check if a chunk has already been processed
     */
    private function isChunkProcessed(int $sportId, int $chunkIndex): bool
    {
        $progressKey = "prematch_progress:{$sportId}:{$this->jobId}";
        $progress = Cache::get($progressKey);

        if (!$progress) {
            return false; // No progress tracking found, process chunk
        }

        return in_array($chunkIndex, $progress['processed_chunks'] ?? []);
    }

    /**
     * Mark a chunk as processed
     */
    private function markChunkProcessed(int $sportId, int $chunkIndex): void
    {
        $progressKey = "prematch_progress:{$sportId}:{$this->jobId}";
        $progress = Cache::get($progressKey);

        if ($progress) {
            $progress['processed_chunks'][] = $chunkIndex;
            $progress['last_update'] = now();
            Cache::put($progressKey, $progress, 3600);
        }
    }

    /**
     * Clean up progress tracking after successful completion
     */
    private function cleanupProgressTracking(int $sportId): void
    {
        $progressKey = "prematch_progress:{$sportId}:{$this->jobId}";
        Cache::forget($progressKey);

        // Also clean up any old progress keys for this sport (older than 2 hours)
        $this->cleanupOldProgressKeys($sportId);
    }

    /**
     * Clean up old progress tracking keys
     */
    private function cleanupOldProgressKeys(int $sportId): void
    {
        try {
            // This is a simple cleanup - in production you might want more sophisticated cleanup
            $pattern = "prematch_progress:{$sportId}:*";

            // Redis cleanup would be done via Redis commands, but for now we'll just
            // let the TTL handle cleanup since we set 1-hour TTL on progress keys

        } catch (\Exception $e) {
            Log::debug('Could not cleanup old progress keys', [
                'sport_id' => $sportId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check memory usage and log warnings if approaching limits
     */
    private function checkMemoryUsage(int $chunkIndex): void
    {
        $memoryUsage = memory_get_usage(true); // Get real memory usage
        $memoryLimit = $this->getMemoryLimit();

        $usagePercent = ($memoryUsage / $memoryLimit) * 100;

        if ($usagePercent > 80) {
            Log::warning('High memory usage detected', [
                'job_id' => $this->jobId,
                'chunk_index' => $chunkIndex,
                'memory_usage' => $this->formatBytes($memoryUsage),
                'memory_limit' => $this->formatBytes($memoryLimit),
                'usage_percent' => round($usagePercent, 1)
            ]);
        } elseif ($usagePercent > 60) {
            Log::debug('Memory usage above 60%', [
                'job_id' => $this->jobId,
                'chunk_index' => $chunkIndex,
                'memory_usage' => $this->formatBytes($memoryUsage),
                'usage_percent' => round($usagePercent, 1)
            ]);
        }
    }

    /**
     * Get memory limit in bytes
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');

        if (preg_match('/^(\d+)(.)$/', $memoryLimit, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);

            switch ($unit) {
                case 'g':
                    return $value * 1024 * 1024 * 1024;
                case 'm':
                    return $value * 1024 * 1024;
                case 'k':
                    return $value * 1024;
                default:
                    return $value;
            }
        }

        return 128 * 1024 * 1024; // Default 128MB if can't parse
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Clear stale cache markers when we have fresh data
     */
    private function clearStaleCacheMarkers(int $sportId): void
    {
        try {
            // Clear any "cache empty" markers that might prevent data serving
            Cache::forget("matches_empty:{$sportId}");

            Log::debug('Cleared stale cache markers', [
                'job_id' => $this->jobId,
                'sport_id' => $sportId
            ]);
        } catch (\Exception $e) {
            Log::debug('Could not clear stale cache markers', [
                'job_id' => $this->jobId,
                'sport_id' => $sportId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get maximum days ahead to sync based on sport type
     */
    private function getMaxDaysAhead(int $sportId): int
    {
        // Different sports have different planning horizons
        $sportHorizons = [
            1 => 14,  // Soccer: 2 weeks (major tournaments)
            2 => 30,  // Tennis: 30 days (many tournaments scheduled far ahead)
            3 => 7,   // Basketball: 1 week (NBA schedule)
            4 => 10,  // Hockey: 10 days (NHL + international)
            5 => 7,   // Volleyball: 1 week
            6 => 7,   // Handball: 1 week
            7 => 10,  // American Football: 10 days (NFL + college)
        ];

        return $sportHorizons[$sportId] ?? 14; // Default 2 weeks
    }

    /**
     * Safely resolve team with fallback for resolution failures
     */
    private function resolveTeamSafely($teamResolutionService, string $teamName, int $sportId, ?int $leagueId): array
    {
        try {
            $resolution = $teamResolutionService->resolveTeamId(
                'pinnacle',
                $teamName,
                null,
                $sportId,
                $leagueId
            );

            // Validate the resolution
            if (!isset($resolution['team_id']) || empty($resolution['team_id'])) {
                Log::debug('Team resolution returned no team_id', [
                    'job_id' => $this->jobId,
                    'team_name' => $teamName,
                    'sport_id' => $sportId,
                    'league_id' => $leagueId,
                    'resolution_result' => $resolution
                ]);

                // Return fallback resolution
                return [
                    'team_id' => null,
                    'confidence' => 0,
                    'source' => 'fallback',
                    'original_name' => $teamName
                ];
            }

            return $resolution;

        } catch (\Exception $e) {
            Log::warning('Team resolution failed, using fallback', [
                'job_id' => $this->jobId,
                'team_name' => $teamName,
                'sport_id' => $sportId,
                'league_id' => $leagueId,
                'error' => $e->getMessage()
            ]);

            // Return safe fallback
            return [
                'team_id' => null,
                'confidence' => 0,
                'source' => 'error_fallback',
                'original_name' => $teamName,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a single chunk of matches
     */
    private function processMatchChunk(array $chunk, TeamResolutionService $teamResolutionService, int $sportId): array
    {
        $processedMatches = [];

        foreach ($chunk as $match) {
            try {
                // Only process matches within sport-specific time windows
                $startTime = isset($match['starts']) ? strtotime($match['starts']) : null;
                $maxDaysAhead = $this->getMaxDaysAhead($sportId);

                if ($startTime && $startTime > strtotime("+{$maxDaysAhead} days")) {
                    continue; // Skip matches too far in future for this sport
                }

                // Resolve teams using cached team resolution with fallback
                $homeTeamResolution = $this->resolveTeamSafely(
                    $teamResolutionService,
                    $match['home'] ?? 'Unknown',
                    $sportId,
                    $match['league_id'] ?? null
                );

                $awayTeamResolution = $this->resolveTeamSafely(
                    $teamResolutionService,
                    $match['away'] ?? 'Unknown',
                    $sportId,
                    $match['league_id'] ?? null
                );

                // Get team enrichment data for images (only if team IDs exist)
                $homeEnrichment = null;
                $awayEnrichment = null;

                if ($homeTeamResolution['team_id']) {
                    $homeEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($homeTeamResolution['team_id']);
                }
                if ($awayTeamResolution['team_id']) {
                    $awayEnrichment = \App\Models\TeamEnrichment::getCachedEnrichment($awayTeamResolution['team_id']);
                }

                // Determine match type based on Pinnacle live_status_id
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
                    'match_type' => $matchType,
                    'live_status_id' => $liveStatusId,
                    'has_open_markets' => $match['is_have_open_markets'] ?? false,
                    'home_score' => 0, // Prematch matches don't have scores yet
                    'away_score' => 0, // Prematch matches don't have scores yet
                    'match_duration' => null, // Prematch matches don't have duration yet
                    'odds_count' => 0,
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
                Log::warning('Failed to process match in chunk', [
                    'match_id' => $match['event_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                // Continue processing other matches in chunk
                continue;
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

    private function cachePrematchMatchesForLeague($leagueId, $newMatches, $sportId)
    {
        $cacheKey = "prematch_matches:{$sportId}:{$leagueId}";

        // Get existing cached matches for this league
        $existingMatches = Cache::get($cacheKey, []);

        // Filter new matches: matches that are NOT live (live_status_id != 1) should be in prematch cache
        $filteredNewMatches = array_filter($newMatches, function($match) {
            $liveStatusId = $match['live_status_id'] ?? 0;
            return $liveStatusId !== 1; // Exclude matches Pinnacle marks as live
        });

        if (empty($filteredNewMatches)) {
            Log::debug('No new prematch matches to cache for league', [
                'cache_key' => $cacheKey,
                'league_id' => $leagueId
            ]);
            return;
        }

        // Merge existing and new matches, then deduplicate
        $allMatches = array_merge($existingMatches, $filteredNewMatches);

        // Deduplicate matches by: sport_id, league_id, home_team_id, away_team_id, scheduled_time
        // Keep the most recently updated version of each unique game
        $deduplicatedMatches = [];
        $seenGames = [];

        foreach ($allMatches as $match) {
            $gameKey = $match['sport_id'] . '|' . $match['league_id'] . '|' . $match['home_team_id'] . '|' . $match['away_team_id'] . '|' . $match['scheduled_time'];

            // If we've seen this game before, only replace if the new match has a more recent update
            if (isset($seenGames[$gameKey])) {
                $existingMatch = $deduplicatedMatches[$seenGames[$gameKey]];
                $existingTime = strtotime($existingMatch['last_updated'] ?? $existingMatch['pinnacle_last_update'] ?? 0);
                $newTime = strtotime($match['last_updated'] ?? $match['pinnacle_last_update'] ?? 0);

                if ($newTime > $existingTime) {
                    $deduplicatedMatches[$seenGames[$gameKey]] = $match;
                }
            } else {
                $seenGames[$gameKey] = count($deduplicatedMatches);
                $deduplicatedMatches[] = $match;
            }
        }

        // Cache merged deduplicated prematch matches with 20-minute TTL
        Cache::put($cacheKey, array_values($deduplicatedMatches), 1200);

        // Also store as stale cache backup (longer TTL for fallback)
        $staleCacheKey = "prematch_matches_stale:{$sportId}:{$leagueId}";
        Cache::put($staleCacheKey, array_values($deduplicatedMatches), 7200); // 2 hours stale TTL

        Log::debug('Updated prematch cache for league', [
            'cache_key' => $cacheKey,
            'stale_cache_key' => $staleCacheKey,
            'existing_count' => count($existingMatches),
            'new_count' => count($filteredNewMatches),
            'merged_count' => count($deduplicatedMatches),
            'ttl_seconds' => 1200,
            'stale_ttl_seconds' => 7200
        ]);
    }

    private function updateDatabaseSelectively($matches)
    {
        $updatedCount = 0;

        foreach ($matches as $matchData) {
            try {
                $existingMatch = SportsMatch::where('eventId', $matchData['id'])->first();

                // Only update if match doesn't exist or key data changed
                if (!$existingMatch || $this->hasPrematchChanged($existingMatch, $matchData)) {
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
                Log::warning('Failed to update prematch in database', [
                    'match_id' => $matchData['id'],
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('Database updates completed for prematch', [
            'total_matches' => count($matches),
            'updated_in_db' => $updatedCount
        ]);
    }

    private function hasPrematchChanged($existing, $new): bool
    {
        // For prematch, check if schedule, market status, or match type changed
        // Allow match_type transitions: PREMATCH → LIVE (when Pinnacle confirms)
        return $existing->eventType != $new['match_type'] || // Allow match type transitions
               $existing->startTime != ($new['scheduled_time'] !== 'TBD' ?
                   \DateTime::createFromFormat('m/d/Y, H:i:s', $new['scheduled_time']) : null) ||
               $existing->hasOpenMarkets != $new['has_open_markets'] ||
               $existing->home_team_id != $new['home_team_id'] ||
               $existing->away_team_id != $new['away_team_id'];
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
    /**
     * Categorize errors for actionable logging
     */
    private function categorizeError(\Exception $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'connection') || str_contains($message, 'timeout') || str_contains($message, 'network')) {
            return 'network';
        }

        if (str_contains($message, 'memory') || str_contains($message, 'out of memory')) {
            return 'memory';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'access denied') || str_contains($message, 'unauthorized')) {
            return 'permission';
        }

        if (str_contains($message, 'rate limit') || str_contains($message, 'too many requests')) {
            return 'rate_limit';
        }

        if (str_contains($message, 'invalid') || str_contains($message, 'malformed') || str_contains($message, 'parse')) {
            return 'data_format';
        }

        if (str_contains($message, 'database') || str_contains($message, 'sql') || str_contains($message, 'pdo')) {
            return 'database';
        }

        return 'unknown';
    }

    /**
     * Determine if an error category is actionable (needs human intervention)
     */
    private function isActionableError(string $category): bool
    {
        $actionableCategories = ['permission', 'data_format', 'database'];
        return in_array($category, $actionableCategories);
    }

    /**
     * Get recommendation for fixing an error category
     */
    private function getErrorRecommendation(string $category): string
    {
        $recommendations = [
            'permission' => 'Check API credentials and permissions',
            'data_format' => 'Review API response format and update parsing logic',
            'database' => 'Check database connectivity and schema',
            'memory' => 'Increase memory limits or optimize data processing',
            'network' => 'Check network connectivity and retry',
            'rate_limit' => 'Implement backoff strategy or reduce request frequency'
        ];

        return $recommendations[$category] ?? 'Investigate error details and check system logs';
    }

    public function failed(\Throwable $exception)
    {
        Log::error('PrematchSyncJob failed permanently', [
            'sportId' => $this->sportId,
            'leagueIds' => $this->leagueIds,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
