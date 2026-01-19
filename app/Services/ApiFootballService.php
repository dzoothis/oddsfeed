<?php
namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ApiFootballService
{
    protected $baseUrl = 'https://v3.football.api-sports.io';
    protected $apiKey;
    protected $client;

    public function __construct()
    {
        $this->apiKey = env('FOOTBALL_KEY');
        $this->client = new Client();
    }

    /**
     * Get the appropriate API base URL for a given sport
     */
    private function getBaseUrlForSport($sportId)
    {
        switch ($sportId) {
            case 3: // Basketball
                return 'https://v1.basketball.api-sports.io';
            case 4: // American Football
                return 'https://v1.american-football.api-sports.io';
            case 5: // Hockey
                return 'https://v1.hockey.api-sports.io';
            case 6: // Baseball
                return 'https://v1.baseball.api-sports.io';
            case 1: // Football/Soccer
            default:
                return 'https://v3.football.api-sports.io';
        }
    }

    public function getFixtures($league = null, $season = null, $live = false)
    {
        $params = [];
        if ($league) $params['league'] = $league;
        if ($season) $params['season'] = $season;
        if ($live) $params['live'] = 'all';

        try {
            $response = $this->client->request('GET', $this->baseUrl . '/fixtures', [
                'headers' => [
                    'x-apisports-key' => $this->apiKey
                ],
                'query' => $params
            ]);

            $data = json_decode($response->getBody(), true);

            Log::info('API-Football Fixtures Response', [
                'league' => $league,
                'season' => $season,
                'live' => $live,
                'status' => $response->getStatusCode(),
                'fixtures_count' => isset($data['response']) ? count($data['response']) : 0
            ]);

            // Add images key to each fixture
            if (isset($data['response']) && is_array($data['response'])) {
                foreach ($data['response'] as &$fixture) {
                    $fixture['images'] = [
                        'home_team_logo' => $fixture['teams']['home']['logo'] ?? null,
                        'away_team_logo' => $fixture['teams']['away']['logo'] ?? null,
                        'league_logo' => $fixture['league']['logo'] ?? null,
                        'country_flag' => $fixture['league']['flag'] ?? null
                    ];
                }
            }

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            Log::error('API-Football Error (Fixtures)', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getEvents($fixtureId)
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/fixtures/events', [
                'headers' => [
                    'x-apisports-key' => $this->apiKey
                ],
                'query' => [
                    'fixture' => $fixtureId
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            Log::info('API-Football Events Response', [
                'fixtureId' => $fixtureId,
                'status' => $response->getStatusCode(),
                'events_count' => isset($data['response']) ? count($data['response']) : 0
            ]);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            Log::error('API-Football Error (Events)', [
                'fixtureId' => $fixtureId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get venue information for a fixture.
     */
    public function getFixtureVenue($fixtureId)
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/fixtures', [
                'headers' => [
                    'x-apisports-key' => $this->apiKey
                ],
                'query' => [
                    'id' => $fixtureId
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['response'][0])) {
                $fixture = $data['response'][0];

                return [
                    'venue_name' => $fixture['fixture']['venue']['name'] ?? null,
                    'venue_city' => $fixture['fixture']['venue']['city'] ?? null,
                    'country' => $fixture['league']['country'] ?? null,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::error('API-Football Error (Venue)', [
                'fixtureId' => $fixtureId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get players for a team in a specific season.
     */
    public function getTeamPlayers($teamId, $season = null, $sportId = null)
    {
        // Determine API endpoint based on sport
        $baseUrl = $this->getBaseUrlForSport($sportId ?? 1); // Default to football

        $params = ['team' => $teamId];
        if ($season) {
            $params['season'] = $season;
        }

        try {
            $response = $this->client->request('GET', $baseUrl . '/players', [
                'headers' => [
                    'x-apisports-key' => $this->apiKey
                ],
                'query' => $params
            ]);

            $data = json_decode($response->getBody(), true);

            Log::info('API-Football Players Response', [
                'teamId' => $teamId,
                'season' => $season,
                'status' => $response->getStatusCode(),
                'players_count' => isset($data['response']) ? count($data['response']) : 0
            ]);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            Log::error('API-Football Error (Players)', [
                'teamId' => $teamId,
                'season' => $season,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}