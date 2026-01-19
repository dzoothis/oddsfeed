<?php
namespace App\Services;

use GuzzleHttp\Client;

class TheOddsApiService
{

    protected $baseUrl = 'https://api.the-odds-api.com/v4';
    protected $apiKey;
    protected $client;

    public function __construct()
    {
        $this->apiKey = env('ODDS_API_KEY');
        $this->client = new Client();
    }

    public function getSports()
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/sports', [
                'query' => [
                    'apiKey' => $this->apiKey
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            \Log::info('The-Odds-API Sports Response', [
                'status' => $response->getStatusCode(),
                'sports_count' => is_array($data) ? count($data) : 0
            ]);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            \Log::error('The-Odds-API Error (Sports)', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getOdds($sportKey, $regions = ['us'], $markets = ['h2h', 'spreads', 'totals'])
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . "/sports/{$sportKey}/odds", [
                'query' => [
                    'apiKey' => $this->apiKey,
                    'regions' => implode(',', $regions),
                    'markets' => implode(',', $markets),
                    'oddsFormat' => 'decimal'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            \Log::info('The-Odds-API Odds Response', [
                'sportKey' => $sportKey,
                'status' => $response->getStatusCode(),
                'events_count' => is_array($data) ? count($data) : 0
            ]);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            \Log::error('The-Odds-API Error (Odds)', [
                'sportKey' => $sportKey,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}