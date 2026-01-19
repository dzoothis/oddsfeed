<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PinnacleService
{
    protected $baseUrl = 'https://pinnacle-odds.p.rapidapi.com/kit/v1';
    protected $apiKey;
    protected $client;
    protected $maxRetries = 3;
    protected $retryDelay = 1; // seconds

    public function __construct()
    {
        $this->apiKey = env('RAPIDAPI_KEY');
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        if (empty($this->apiKey)) {
            Log::warning('PinnacleService: RAPIDAPI_KEY not configured');
        }
    }

    /**
     * Make a resilient API request with retry logic and validation
     */
    protected function makeRequest(string $method, string $endpoint, array $params = [], int $maxRetries = null): array
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxRetries) {
            $attempts++;

            try {
                $response = $this->client->request($method, $this->baseUrl . $endpoint, [
                    'headers' => [
                        'x-rapidapi-host' => 'pinnacle-odds.p.rapidapi.com',
                        'x-rapidapi-key' => $this->apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => $params,
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                // Validate response
                $result = $this->validateResponse($statusCode, $body, $endpoint, $params);

                if ($result['valid']) {
                    return $result['data'];
                } else {
                    // If validation fails but we got a response, don't retry
                    Log::warning('Pinnacle API validation failed', [
                        'endpoint' => $endpoint,
                        'params' => $params,
                        'status_code' => $statusCode,
                        'validation_errors' => $result['errors']
                    ]);
                    return $this->getEmptyResponse($endpoint);
                }

            } catch (TimeoutException $e) {
                $lastException = $e;
                Log::warning('Pinnacle API timeout', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries
                ]);

            } catch (ConnectException $e) {
                $lastException = $e;
                Log::warning('Pinnacle API connection failed', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries
                ]);

            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;

                // Don't retry on client errors (4xx)
                if ($statusCode && $statusCode >= 400 && $statusCode < 500) {
                    Log::error('Pinnacle API client error (no retry)', [
                        'endpoint' => $endpoint,
                        'params' => $params,
                        'status_code' => $statusCode,
                        'error' => $e->getMessage()
                    ]);
                    break;
                }

                Log::warning('Pinnacle API request failed', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'max_retries' => $maxRetries,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage()
                ]);

            } catch (\Exception $e) {
                $lastException = $e;
                Log::error('Pinnacle API unexpected error', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);
            }

            // Wait before retry (exponential backoff)
            if ($attempts < $maxRetries) {
                $delay = $this->retryDelay * pow(2, $attempts - 1);
                Log::info('Retrying Pinnacle API request', [
                    'endpoint' => $endpoint,
                    'delay_seconds' => $delay
                ]);
                sleep($delay);
            }
        }

        // All retries exhausted
        Log::error('Pinnacle API request failed after all retries', [
            'endpoint' => $endpoint,
            'params' => $params,
            'attempts' => $attempts,
            'final_error' => $lastException ? $lastException->getMessage() : 'Unknown error'
        ]);

        return $this->getEmptyResponse($endpoint);
    }

    /**
     * Validate API response
     */
    protected function validateResponse(int $statusCode, string $body, string $endpoint, array $params): array
    {
        $errors = [];

        // Check HTTP status
        if ($statusCode !== 200) {
            $errors[] = "HTTP status {$statusCode} (expected 200)";
        }

        // Check if body is empty
        if (empty(trim($body))) {
            $errors[] = "Empty response body";
            return ['valid' => false, 'data' => [], 'errors' => $errors];
        }

        // Try to decode JSON
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = "Invalid JSON: " . json_last_error_msg();
            return ['valid' => false, 'data' => [], 'errors' => $errors];
        }

        // Validate data structure based on endpoint
        $structureErrors = $this->validateDataStructure($data, $endpoint, $params);
        $errors = array_merge($errors, $structureErrors);

        return [
            'valid' => empty($errors),
            'data' => $data,
            'errors' => $errors
        ];
    }

    /**
     * Validate data structure based on endpoint
     */
    protected function validateDataStructure(array $data, string $endpoint, array $params): array
    {
        $errors = [];

        switch ($endpoint) {
            case '/sports':
                if (!is_array($data)) {
                    $errors[] = "Sports endpoint: expected array, got " . gettype($data);
                }
                break;

            case '/leagues':
                if (!isset($data['leagues']) || !is_array($data['leagues'])) {
                    $errors[] = "Leagues endpoint: missing or invalid 'leagues' array";
                }
                break;

            case '/markets':
            case '/markets/live':
            case '/markets/prematch':
                if (!isset($data['events']) || !is_array($data['events'])) {
                    $errors[] = "Markets endpoint: missing or invalid 'events' array";
                }
                break;

            case '/fixtures':
            case '/fixtures/live':
            case '/fixtures/prematch':
                if (!isset($data['fixtures']) || !is_array($data['fixtures'])) {
                    $errors[] = "Fixtures endpoint: missing or invalid 'fixtures' array";
                }
                break;

            case '/special-markets':
                if (!is_array($data)) {
                    $errors[] = "Special markets endpoint: expected array, got " . gettype($data);
                }
                break;
        }

        return $errors;
    }

    /**
     * Get appropriate empty response for failed requests
     */
    protected function getEmptyResponse(string $endpoint): array
    {
        switch ($endpoint) {
            case '/sports':
                return [];
            case '/leagues':
                return ['leagues' => []];
            case '/markets':
            case '/markets/live':
            case '/markets/prematch':
                return ['events' => []];
            case '/fixtures':
            case '/fixtures/live':
            case '/fixtures/prematch':
                return ['fixtures' => []];
            case '/special-markets':
                return [];
            default:
                return [];
        }
    }

    /**
     * Check if API key is configured
     */
    protected function validateApiKey(): bool
    {
        if (empty($this->apiKey)) {
            Log::error('PinnacleService: API key not configured');
            return false;
        }
        return true;
    }

    /**
     * Get matches/events for specific leagues using the /kit/v1/markets endpoint
     * Enhanced with comprehensive error handling and validation
     */
    public function getMatchesByLeagues($sportId, $leagueIds = [], $eventType = 'prematch')
    {
        if (!$this->validateApiKey()) {
            return ['events' => []];
        }

        $params = [
            'sport_id' => $sportId,
            'event_type' => $eventType,
        ];

        // Only add league_id parameter if leagues are specified
        // Empty league_ids array means "all leagues"
        if (!empty($leagueIds)) {
            $params['league_id'] = implode(',', array_filter($leagueIds));
        }

        Log::info('Fetching matches from Pinnacle API', [
            'sportId' => $sportId,
            'leagueIds' => $leagueIds,
            'eventType' => $eventType,
            'params' => $params
        ]);

        $data = $this->makeRequest('GET', '/markets', $params);

        $eventsCount = (is_array($data) && isset($data['events']) && is_array($data['events'])) ? count($data['events']) : 0;

        Log::info('Matches fetch completed', [
            'sportId' => $sportId,
            'events_count' => $eventsCount,
            'has_data' => $eventsCount > 0
        ]);

        // Ensure we always return the expected structure
        if (!is_array($data)) {
            return ['events' => []];
        }

        if (!isset($data['events']) || !is_array($data['events'])) {
            $data['events'] = [];
        }

        return $data;
    }

    public function getMarkets($sportId, $isLive = false, $leagueIds = [])
    {
        if (!$this->validateApiKey()) {
            return [];
        }

        $endpoint = $isLive ? '/markets/live' : '/markets/prematch';

        $params = [
            'sport_id' => $sportId,
        ];

        if (!empty($leagueIds)) {
            $params['league_ids'] = implode(',', array_filter($leagueIds));
        }

        Log::info('Fetching markets from Pinnacle API', [
            'sportId' => $sportId,
            'isLive' => $isLive,
            'leagueIds' => $leagueIds
        ]);

        $data = $this->makeRequest('GET', $endpoint, $params);

        $count = is_array($data) ? count($data) : 0;
        Log::info('Markets fetch completed', [
            'sportId' => $sportId,
            'isLive' => $isLive,
            'count' => $count,
            'has_data' => $count > 0
        ]);

        return is_array($data) ? $data : [];
    }

    public function getSports()
    {
        if (!$this->validateApiKey()) {
            return [];
        }

        Log::info('Fetching sports from Pinnacle API');

        $data = $this->makeRequest('GET', '/sports');

        $count = is_array($data) ? count($data) : 0;
        Log::info('Sports fetch completed', [
            'count' => $count,
            'has_data' => $count > 0
        ]);

        return is_array($data) ? $data : [];
    }

    public function getLeagues($sportId)
    {
        if (!$this->validateApiKey()) {
            return ['leagues' => []];
        }

        Log::info('Fetching leagues from Pinnacle API', ['sportId' => $sportId]);

        $data = $this->makeRequest('GET', '/leagues', ['sport_id' => $sportId]);

        $count = (is_array($data) && isset($data['leagues']) && is_array($data['leagues'])) ? count($data['leagues']) : 0;

        Log::info('Leagues fetch completed', [
            'sportId' => $sportId,
            'count' => $count,
            'has_data' => $count > 0
        ]);

        return is_array($data) ? $data : ['leagues' => []];
    }

    /**
     * Get fixtures with resilient error handling
     */
    public function getFixtures($sportId, $leagueId = null, $isLive = false)
    {
        if (!$this->validateApiKey()) {
            return ['fixtures' => []];
        }

        $endpoint = $isLive ? '/fixtures/live' : '/fixtures/prematch';

        $params = [
            'sport_id' => $sportId,
        ];

        if ($leagueId) {
            $params['league_id'] = $leagueId;
        }

        Log::info('Fetching fixtures from Pinnacle API', [
            'sportId' => $sportId,
            'leagueId' => $leagueId,
            'isLive' => $isLive
        ]);

        $data = $this->makeRequest('GET', $endpoint, $params);

        $fixturesCount = (is_array($data) && isset($data['fixtures']) && is_array($data['fixtures'])) ? count($data['fixtures']) : 0;

        Log::info('Fixtures fetch completed', [
            'sportId' => $sportId,
            'leagueId' => $leagueId,
            'isLive' => $isLive,
            'fixtures_count' => $fixturesCount,
            'has_data' => $fixturesCount > 0
        ]);

        // Ensure consistent return structure
        if (!is_array($data)) {
            return ['fixtures' => []];
        }

        if (!isset($data['fixtures']) || !is_array($data['fixtures'])) {
            $data['fixtures'] = [];
        }

        return $data;
    }

    /**
     * Get special markets with resilient error handling
     */
    public function getSpecialMarkets($eventType, $sportId, $leagueIds = [])
    {
        if (!$this->validateApiKey()) {
            return [];
        }

        $params = [
            'event_type' => $eventType,
            'sport_id' => $sportId,
        ];

        if (!empty($leagueIds)) {
            $params['league_ids'] = implode(',', array_filter($leagueIds));
        }

        Log::info('Fetching special markets from Pinnacle API', [
            'eventType' => $eventType,
            'sportId' => $sportId,
            'leagueIds' => $leagueIds
        ]);

        $data = $this->makeRequest('GET', '/special-markets', $params);

        $count = is_array($data) ? count($data) : 0;
        Log::info('Special markets fetch completed', [
            'eventType' => $eventType,
            'sportId' => $sportId,
            'count' => $count,
            'has_data' => $count > 0
        ]);

        return is_array($data) ? $data : [];
    }
}
