<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Odds-Feed Service
 * 
 * Service for interacting with Odds-Feed API
 * This service follows the same pattern as PinnacleService and ApiFootballService
 * 
 * Configuration:
 * - ODDS_FEED_API_URL: Base URL for Odds-Feed API
 * - ODDS_FEED_API_KEY: API key for authentication
 * - ODDS_FEED_ENABLED: Set to 'true' to enable (default: 'false')
 */
class OddsFeedService
{
    protected $baseUrl;
    protected $apiKey;
    protected $client;
    protected $enabled;
    protected $maxRetries = 3;
    protected $retryDelay = 1; // seconds

    public function __construct()
    {
        $this->baseUrl = env('ODDS_FEED_API_URL', 'https://api.oddsfeed.com/v1');
        $this->apiKey = env('ODDS_FEED_API_KEY');
        $this->enabled = env('ODDS_FEED_ENABLED', 'false') === 'true';
        
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        if (!$this->enabled) {
            Log::debug('OddsFeedService: Service is disabled (ODDS_FEED_ENABLED=false)');
        } elseif (empty($this->apiKey)) {
            Log::warning('OddsFeedService: ODDS_FEED_API_KEY not configured');
        }
    }

    /**
     * Check if service is enabled and configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !empty($this->baseUrl);
    }

    /**
     * Make a resilient API request with retry logic
     */
    protected function makeRequest(string $method, string $endpoint, array $params = [], int $maxRetries = null): array
    {
        if (!$this->isEnabled()) {
            Log::debug('OddsFeedService: Service not enabled, returning empty response', [
                'endpoint' => $endpoint
            ]);
            return [];
        }

        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxRetries) {
            $attempts++;

            try {
                $response = $this->client->request($method, $this->baseUrl . $endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => $params,
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);

                if ($statusCode === 200 && is_array($data)) {
                    return $data;
                } else {
                    Log::warning('Odds-Feed API returned non-200 status or invalid JSON', [
                        'endpoint' => $endpoint,
                        'status_code' => $statusCode,
                        'response_preview' => substr($body, 0, 200)
                    ]);
                    return [];
                }

            } catch (TimeoutException $e) {
                $lastException = $e;
                Log::warning('Odds-Feed API timeout', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries
                ]);
                if ($attempts < $maxRetries) {
                    sleep($this->retryDelay * $attempts);
                }
            } catch (ConnectException $e) {
                $lastException = $e;
                Log::warning('Odds-Feed API connection error', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);
                if ($attempts < $maxRetries) {
                    sleep($this->retryDelay * $attempts);
                }
            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                
                // Don't retry on 4xx errors (client errors)
                if ($statusCode && $statusCode >= 400 && $statusCode < 500) {
                    Log::warning('Odds-Feed API client error (no retry)', [
                        'endpoint' => $endpoint,
                        'status_code' => $statusCode,
                        'error' => $e->getMessage()
                    ]);
                    return [];
                }

                $lastException = $e;
                Log::warning('Odds-Feed API request error', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage()
                ]);
                if ($attempts < $maxRetries) {
                    sleep($this->retryDelay * $attempts);
                }
            } catch (\Exception $e) {
                $lastException = $e;
                Log::error('Odds-Feed API unexpected error', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);
                if ($attempts < $maxRetries) {
                    sleep($this->retryDelay * $attempts);
                }
            }
        }

        // All retries exhausted
        Log::error('Odds-Feed API request failed after all retries', [
            'endpoint' => $endpoint,
            'params' => $params,
            'max_retries' => $maxRetries,
            'last_error' => $lastException ? $lastException->getMessage() : 'Unknown error'
        ]);

        return [];
    }

    /**
     * Get live matches from Odds-Feed
     * 
     * @param int|null $sportId Sport ID (optional)
     * @param array $leagueIds League IDs to filter (optional, empty = all)
     * @return array Array of live matches
     */
    public function getLiveMatches(?int $sportId = null, array $leagueIds = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $params = [
            'status' => 'live',
        ];

        if ($sportId) {
            $params['sport_id'] = $sportId;
        }

        if (!empty($leagueIds)) {
            $params['league_ids'] = implode(',', $leagueIds);
        }

        $cacheKey = 'oddsfeed_live_matches_' . md5(json_encode($params));
        
        return Cache::remember($cacheKey, 60, function () use ($params) {
            $response = $this->makeRequest('GET', '/matches', $params);
            
            // Expected structure: { "data": [...] } or { "matches": [...] } or direct array
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            } elseif (isset($response['matches']) && is_array($response['matches'])) {
                return $response['matches'];
            } elseif (is_array($response) && isset($response[0])) {
                return $response;
            }
            
            return [];
        });
    }

    /**
     * Get prematch matches from Odds-Feed
     * 
     * @param int|null $sportId Sport ID (optional)
     * @param array $leagueIds League IDs to filter (optional, empty = all)
     * @return array Array of prematch matches
     */
    public function getPrematchMatches(?int $sportId = null, array $leagueIds = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $params = [
            'status' => 'scheduled',
        ];

        if ($sportId) {
            $params['sport_id'] = $sportId;
        }

        if (!empty($leagueIds)) {
            $params['league_ids'] = implode(',', $leagueIds);
        }

        $cacheKey = 'oddsfeed_prematch_matches_' . md5(json_encode($params));
        
        return Cache::remember($cacheKey, 300, function () use ($params) {
            $response = $this->makeRequest('GET', '/matches', $params);
            
            // Expected structure: { "data": [...] } or { "matches": [...] } or direct array
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            } elseif (isset($response['matches']) && is_array($response['matches'])) {
                return $response['matches'];
            } elseif (is_array($response) && isset($response[0])) {
                return $response;
            }
            
            return [];
        });
    }

    /**
     * Get odds for a specific match
     * 
     * @param string|int $matchId Match ID
     * @param string|null $eventType Event type ('live' or 'prematch')
     * @return array Array of odds
     */
    public function getMatchOdds($matchId, ?string $eventType = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $params = [];
        if ($eventType) {
            $params['event_type'] = $eventType;
        }

        $cacheKey = 'oddsfeed_match_odds_' . $matchId . '_' . ($eventType ?? 'all');
        
        return Cache::remember($cacheKey, 30, function () use ($matchId, $params) {
            $response = $this->makeRequest('GET', '/matches/' . $matchId . '/odds', $params);
            
            // Expected structure: { "data": [...] } or { "odds": [...] } or direct array
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            } elseif (isset($response['odds']) && is_array($response['odds'])) {
                return $response['odds'];
            } elseif (is_array($response) && isset($response[0])) {
                return $response;
            }
            
            return [];
        });
    }
}

