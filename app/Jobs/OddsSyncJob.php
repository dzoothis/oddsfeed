<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\OddsApiIntegrationService;
use App\Models\SportsMatch;

class OddsSyncJob implements ShouldQueue
{
    use Queueable;

    // Queue configuration for odds processing - optimized for stability
    public $tries = 2; // Reduced retries to prevent retry loops
    public $timeout = 300; // 5 minutes - odds sync should be faster
    public $backoff = [60, 300]; // Shorter backoff: 1min, 5min
    public $maxExceptions = 3; // Allow some exceptions before marking failed

    protected $matchIds;
    protected $forceRefresh;
    protected $jobId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($matchIds = [], $forceRefresh = false)
    {
        $this->matchIds = $matchIds;
        $this->forceRefresh = $forceRefresh;
        $this->jobId = uniqid('odds_sync_', true);
    }

    /**
     * Execute the job - Stable odds sync with proper error handling
     */
    public function handle(OddsApiIntegrationService $oddsService): void
    {
        $startTime = microtime(true);

        Log::info('OddsSyncJob started - Stable processing', [
            'job_id' => $this->jobId,
            'matchIds' => $this->matchIds,
            'forceRefresh' => $this->forceRefresh
        ]);

        try {
            // Check for circuit breaker (prevent retry loops)
            if ($this->isCircuitBreakerActive()) {
                Log::warning('OddsSyncJob circuit breaker active, skipping', [
                    'job_id' => $this->jobId
                ]);
                return;
            }

            // Get matches that actually need odds sync
            $matchesToProcess = $this->getMatchesNeedingOddsSync();

            if (empty($matchesToProcess)) {
                Log::info('No matches need odds sync', [
                    'job_id' => $this->jobId
                ]);
                return;
            }

            Log::info('Processing odds for matches', [
                'job_id' => $this->jobId,
                'match_count' => count($matchesToProcess),
                'force_refresh' => $this->forceRefresh
            ]);

            // Process matches with proper error handling
            $results = $this->processMatchesWithOdds($oddsService, $matchesToProcess);

            // Update circuit breaker based on success rate
            $this->updateCircuitBreaker($results);

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('OddsSyncJob completed', [
                'job_id' => $this->jobId,
                'matches_processed' => $results['processed'],
                'odds_updated' => $results['updated'],
                'api_errors' => $results['api_errors'],
                'validation_errors' => $results['validation_errors'],
                'duration_seconds' => $duration
            ]);

        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            Log::error('OddsSyncJob failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
                'trace' => $e->getTraceAsString()
            ]);

            // Activate circuit breaker on critical failures
            $this->activateCircuitBreaker($e);

            throw $e;
        }
    }

    /**
     * Get matches that actually need odds synchronization
     */
    private function getMatchesNeedingOddsSync()
    {
        // Start with matches that are likely to have odds
        $query = SportsMatch::where(function($q) {
            // Live matches with active status
            $q->where('eventType', 'live')
              ->where('live_status_id', '>', 0);
        })->orWhere(function($q) {
            // Prematch matches within 12 hours (reduced from 24 to be more targeted)
            $q->where('eventType', 'prematch')
              ->where('startTime', '>', now())
              ->where('startTime', '<=', now()->addHours(12));
        });

        // If specific match IDs provided, filter to those
        if (!empty($this->matchIds)) {
            $query->whereIn('eventId', $this->matchIds);
        }

        // Only include matches with resolved teams AND markets available
        $query->whereNotNull('home_team_id')
              ->whereNotNull('away_team_id')
              ->where('hasOpenMarkets', true); // Only sync odds for matches that have markets

        $matches = $query->get();

        // Further filter: only include matches that haven't been synced recently
        // unless force refresh is requested
        if (!$this->forceRefresh) {
            $matches = $matches->filter(function($match) {
                $lastSyncKey = "odds_last_sync:{$match->eventId}";
                return !$this->areOddsFresh($lastSyncKey);
            });
        }

        return $matches;
    }

    /**
     * Process matches with comprehensive error handling
     */
    private function processMatchesWithOdds(OddsApiIntegrationService $oddsService, $matches)
    {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'api_errors' => 0,
            'validation_errors' => 0,
            'skipped' => 0
        ];

        // Process in smaller batches to prevent overwhelming the API
        $batches = $matches->chunk(5); // Reduced from 10 to 5 for more control

        foreach ($batches as $batchIndex => $batch) {
            Log::debug('Processing odds batch', [
                'job_id' => $this->jobId,
                'batch_index' => $batchIndex + 1,
                'batch_size' => count($batch)
            ]);

            foreach ($batch as $match) {
                $result = $this->processSingleMatchOdds($oddsService, $match);

                switch ($result) {
                    case 'updated':
                        $results['processed']++;
                        $results['updated']++;
                        break;
                    case 'api_error':
                        $results['api_errors']++;
                        break;
                    case 'validation_error':
                        $results['validation_errors']++;
                        break;
                    case 'skipped':
                        $results['skipped']++;
                        break;
                }
            }

            // Check circuit breaker during processing
            if ($this->shouldActivateCircuitBreaker($results)) {
                Log::warning('Activating circuit breaker mid-processing', [
                    'job_id' => $this->jobId,
                    'results' => $results
                ]);
                $this->activateCircuitBreaker(new \Exception('High error rate detected'));
                break;
            }

            // Micro-delay between batches to be API-friendly
            usleep(200000); // 0.2 seconds
        }

        return $results;
    }

    /**
     * Process odds for a single match with detailed error handling
     */
    private function processSingleMatchOdds(OddsApiIntegrationService $oddsService, $match)
    {
        try {
            // Pre-validate match state
            if (!$this->isMatchValidForOdds($match)) {
                Log::debug('Skipping invalid match for odds', [
                    'job_id' => $this->jobId,
                    'match_id' => $match->eventId,
                    'reason' => 'validation_failed'
                ]);
                return 'validation_error';
            }

            Log::debug('Syncing odds for match', [
                'job_id' => $this->jobId,
                'match_id' => $match->eventId,
                'event_type' => $match->eventType
            ]);

            // Attempt to attach odds
            $result = $oddsService->attachOddsToMatch($match);

            if ($result) {
                // Update last sync timestamp only on success
                $lastSyncKey = "odds_last_sync:{$match->eventId}";
                Cache::put($lastSyncKey, now(), 3600); // 1 hour TTL

                Log::debug('Odds updated successfully', [
                    'job_id' => $this->jobId,
                    'match_id' => $match->eventId
                ]);

                return 'updated';
            } else {
                Log::debug('Odds sync returned false (no odds available)', [
                    'job_id' => $this->jobId,
                    'match_id' => $match->eventId
                ]);
                return 'skipped';
            }

        } catch (\Exception $e) {
            $errorType = $this->categorizeError($e);

            Log::warning('Failed to sync odds for match', [
                'job_id' => $this->jobId,
                'match_id' => $match->eventId,
                'error_type' => $errorType,
                'error' => $e->getMessage()
            ]);

            return $errorType;
        }
    }

    /**
     * Validate if a match is in a valid state for odds syncing
     */
    private function isMatchValidForOdds($match)
    {
        // Must have resolved teams
        if (empty($match->home_team_id) || empty($match->away_team_id)) {
            return false;
        }

        // Must have markets available
        if (!$match->hasOpenMarkets) {
            return false;
        }

        // Must be in a valid event type
        if (!in_array($match->eventType, ['live', 'prematch'])) {
            return false;
        }

        // For live matches, must have active live status
        if ($match->eventType === 'live' && $match->live_status_id <= 0) {
            return false;
        }

        // For prematch, must be starting within reasonable time
        if ($match->eventType === 'prematch') {
            if (!$match->startTime || $match->startTime->isPast()) {
                return false; // Match already started or invalid time
            }
            if ($match->startTime->diffInHours(now()) > 12) {
                return false; // Too far in future
            }
        }

        return true;
    }

    /**
     * Categorize errors for better handling
     */
    private function categorizeError(\Exception $e)
    {
        $message = strtolower($e->getMessage());

        // API-related errors
        if (str_contains($message, 'api') ||
            str_contains($message, 'http') ||
            str_contains($message, 'timeout') ||
            str_contains($message, 'connection')) {
            return 'api_error';
        }

        // Validation/data errors
        if (str_contains($message, 'invalid') ||
            str_contains($message, 'missing') ||
            str_contains($message, 'null')) {
            return 'validation_error';
        }

        // Default to API error for unknown issues
        return 'api_error';
    }

    private function areOddsFresh($lastSyncKey): bool
    {
        $lastSync = Cache::get($lastSyncKey);

        if (!$lastSync) {
            return false; // Never synced
        }

        // Consider fresh if synced within last 50 seconds (buffer before 1-min schedule)
        $secondsSinceLastSync = now()->diffInSeconds($lastSync);
        return $secondsSinceLastSync < 50;
    }

    /**
     * Circuit breaker to prevent retry loops on persistent failures
     */
    private function isCircuitBreakerActive(): bool
    {
        $circuitKey = 'odds_sync_circuit_breaker';
        $circuitData = Cache::get($circuitKey);

        if (!$circuitData) {
            return false; // Circuit breaker not active
        }

        // Check if circuit breaker should reset (after 15 minutes)
        if (isset($circuitData['activated_at'])) {
            $activatedAt = \Carbon\Carbon::parse($circuitData['activated_at']);
            if ($activatedAt->addMinutes(15)->isPast()) {
                Cache::forget($circuitKey);
                Log::info('Odds sync circuit breaker reset', [
                    'job_id' => $this->jobId
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Activate circuit breaker on critical failures
     */
    private function activateCircuitBreaker(\Exception $e): void
    {
        $circuitKey = 'odds_sync_circuit_breaker';

        Cache::put($circuitKey, [
            'activated_at' => now(),
            'reason' => $e->getMessage(),
            'job_id' => $this->jobId
        ], 900); // 15 minutes

        Log::warning('Odds sync circuit breaker activated', [
            'job_id' => $this->jobId,
            'reason' => $e->getMessage(),
            'duration_minutes' => 15
        ]);
    }

    /**
     * Update circuit breaker based on processing results
     */
    private function updateCircuitBreaker(array $results): void
    {
        $totalProcessed = $results['processed'] + $results['api_errors'] + $results['validation_errors'];
        $errorRate = $totalProcessed > 0 ? ($results['api_errors'] + $results['validation_errors']) / $totalProcessed : 0;

        // If error rate is very high, activate circuit breaker
        if ($errorRate > 0.8 && $totalProcessed >= 5) {
            $this->activateCircuitBreaker(new \Exception("High error rate: {$errorRate}"));
        }
    }

    /**
     * Check if circuit breaker should be activated mid-processing
     */
    private function shouldActivateCircuitBreaker(array $results): bool
    {
        $totalProcessed = $results['processed'] + $results['api_errors'] + $results['validation_errors'];

        // Activate if we have too many errors in a small sample
        if ($totalProcessed >= 10) {
            $errorRate = ($results['api_errors'] + $results['validation_errors']) / $totalProcessed;
            return $errorRate > 0.7; // 70% error rate triggers circuit breaker
        }

        return false;
    }

    /**
     * Handle job failure with circuit breaker activation
     */
    public function failed(\Throwable $exception)
    {
        // Activate circuit breaker on permanent failure to prevent retry loops
        $this->activateCircuitBreaker($exception);

        Log::error('OddsSyncJob failed permanently - circuit breaker activated', [
            'job_id' => $this->jobId,
            'matchIds' => $this->matchIds,
            'forceRefresh' => $this->forceRefresh,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'circuit_breaker_duration' => '15 minutes'
        ]);
    }
}
