<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Provider-Agnostic Odds Aggregation Service
 * 
 * Aggregates odds from multiple providers and deduplicates them.
 * 
 * Key Principles:
 * - Include odds from ALL providers
 * - Deduplicate identical odds
 * - Preserve provider metadata
 * - No provider priority
 */
class OddsAggregationService
{
    /**
     * Aggregate odds from multiple providers
     * 
     * @param array $pinnacleOdds Odds from Pinnacle
     * @param array $oddsFeedOdds Odds from Odds-Feed (if available)
     * @param array $apiFootballOdds Odds from API-Football
     * @return array Unified, deduplicated odds
     */
    public function aggregateOdds(
        array $pinnacleOdds = [],
        array $oddsFeedOdds = [],
        array $apiFootballOdds = []
    ): array {
        Log::info('Starting odds aggregation', [
            'pinnacle_count' => count($pinnacleOdds),
            'odds_feed_count' => count($oddsFeedOdds),
            'api_football_count' => count($apiFootballOdds)
        ]);

        // Step 1: Normalize all odds to common structure
        $normalizedOdds = [];
        
        foreach ($pinnacleOdds as $odd) {
            $normalized = $this->normalizePinnacleOdd($odd);
            if ($normalized) {
                $normalizedOdds[] = $normalized;
            }
        }

        foreach ($oddsFeedOdds as $odd) {
            $normalized = $this->normalizeOddsFeedOdd($odd);
            if ($normalized) {
                $normalizedOdds[] = $normalized;
            }
        }

        foreach ($apiFootballOdds as $odd) {
            $normalized = $this->normalizeApiFootballOdd($odd);
            if ($normalized) {
                $normalizedOdds[] = $normalized;
            }
        }

        Log::info('Normalized odds from all providers', [
            'total_normalized' => count($normalizedOdds)
        ]);

        // Step 2: Deduplicate odds
        $deduplicatedOdds = $this->deduplicateOdds($normalizedOdds);

        Log::info('Odds aggregation completed', [
            'original_count' => count($normalizedOdds),
            'deduplicated_count' => count($deduplicatedOdds),
            'duplicates_removed' => count($normalizedOdds) - count($deduplicatedOdds)
        ]);

        return $deduplicatedOdds;
    }

    /**
     * Deduplicate odds using identity rules
     * 
     * Two odds are considered the same if:
     * - Same market type (e.g. Match Winner, Over/Under, Handicap)
     * - Same selection (e.g. Home, Away, Draw, Over 2.5)
     * - Same line/handicap (if applicable)
     * - Same price/value (within tolerance)
     * 
     * @param array $odds Normalized odds from all providers
     * @return array Deduplicated odds
     */
    protected function deduplicateOdds(array $odds): array
    {
        $deduplicated = [];
        $seenOdds = [];

        foreach ($odds as $odd) {
            // Generate odds identity key
            $identityKey = $this->generateOddsIdentityKey($odd);

            if (!isset($seenOdds[$identityKey])) {
                // First occurrence - add to result
                $seenOdds[$identityKey] = $odd;
                $deduplicated[] = $odd;
            } else {
                // Duplicate found - merge providers
                $existingOdd = $seenOdds[$identityKey];
                $mergedOdd = $this->mergeOddData($existingOdd, $odd);
                
                // Update the existing odd in result array
                $index = array_search($existingOdd, $deduplicated, true);
                if ($index !== false) {
                    $deduplicated[$index] = $mergedOdd;
                    $seenOdds[$identityKey] = $mergedOdd;
                }
            }
        }

        return $deduplicated;
    }

    /**
     * Generate identity key for odds deduplication
     * 
     * @param array $odd Normalized odd
     * @return string Identity key
     */
    protected function generateOddsIdentityKey(array $odd): string
    {
        $marketType = $this->normalizeMarketType($odd['market_type'] ?? '');
        $selection = $this->normalizeSelection($odd['selection'] ?? '');
        $line = $this->normalizeLine($odd['line'] ?? null);
        $price = $this->normalizePrice($odd['price'] ?? null);
        $period = $odd['period'] ?? 'Game';

        return "{$marketType}|{$selection}|{$line}|{$price}|{$period}";
    }

    /**
     * Normalize market type for comparison
     * 
     * @param string $marketType Original market type
     * @return string Normalized market type
     */
    protected function normalizeMarketType(string $marketType): string
    {
        $normalized = strtolower($marketType);
        
        // Map variations to standard types
        $mappings = [
            'money line' => 'money_line',
            '1x2' => 'money_line',
            'match winner' => 'money_line',
            'winner' => 'money_line',
            'matchwinner' => 'money_line',
            'over/under' => 'totals',
            'total' => 'totals',
            'totals' => 'totals',
            'handicap' => 'spreads',
            'spread' => 'spreads',
            'asian handicap' => 'spreads',
            'player props' => 'player_props',
            'player' => 'player_props',
        ];
        
        foreach ($mappings as $key => $value) {
            if (strpos($normalized, $key) !== false) {
                return $value;
            }
        }
        
        return $normalized;
    }

    /**
     * Normalize selection for comparison
     * 
     * @param string $selection Original selection
     * @return string Normalized selection
     */
    protected function normalizeSelection(string $selection): string
    {
        $normalized = strtolower(trim($selection));
        
        // Map variations
        $mappings = [
            'home' => 'home',
            '1' => 'home',
            'home team' => 'home',
            'away' => 'away',
            '2' => 'away',
            'away team' => 'away',
            'draw' => 'draw',
            'x' => 'draw',
            'tie' => 'draw',
            'over' => 'over',
            'under' => 'under',
        ];
        
        return $mappings[$normalized] ?? $normalized;
    }

    /**
     * Normalize line/handicap for comparison
     * 
     * @param mixed $line Original line
     * @return string Normalized line
     */
    protected function normalizeLine($line): string
    {
        if ($line === null || $line === '') {
            return 'none';
        }
        
        // Round to 0.5 increments for tolerance
        $floatLine = (float) $line;
        $rounded = round($floatLine * 2) / 2;
        
        return (string) $rounded;
    }

    /**
     * Normalize price/odds for comparison
     * 
     * @param mixed $price Original price
     * @return string Normalized price (rounded to 2 decimals)
     */
    protected function normalizePrice($price): string
    {
        if ($price === null || $price === '') {
            return '0.00';
        }
        
        $floatPrice = (float) $price;
        return number_format($floatPrice, 2, '.', '');
    }

    /**
     * Merge odd data from multiple providers
     * 
     * @param array $odd1 First odd
     * @param array $odd2 Second odd (duplicate)
     * @return array Merged odd
     */
    protected function mergeOddData(array $odd1, array $odd2): array
    {
        $merged = $odd1;

        // Merge providers list
        $merged['providers'] = array_unique(array_merge(
            $odd1['providers'] ?? [],
            $odd2['providers'] ?? []
        ));

        // Use best (highest) price if different
        $price1 = (float) ($odd1['price'] ?? 0);
        $price2 = (float) ($odd2['price'] ?? 0);
        if ($price2 > $price1) {
            $merged['price'] = $price2;
            $merged['best_provider'] = $odd2['provider'] ?? 'unknown';
        }

        // Merge metadata
        $merged['metadata'] = array_merge(
            $odd1['metadata'] ?? [],
            $odd2['metadata'] ?? []
        );

        return $merged;
    }

    /**
     * Normalize Pinnacle odd to common structure
     * 
     * @param array $odd Raw Pinnacle odd
     * @return array|null Normalized odd or null if invalid
     */
    protected function normalizePinnacleOdd(array $odd): ?array
    {
        try {
            // Use market_type if already normalized, otherwise try to map it
            $marketType = $odd['market_type'] ?? 'unknown';
            
            // If market_type is still a raw name (not normalized), try to map it
            if ($marketType !== 'money_line' && $marketType !== 'spreads' && $marketType !== 'totals' && $marketType !== 'player_props') {
                $marketName = strtolower($marketType);
                $marketTypeMap = [
                    'match winner' => 'money_line',
                    '1x2' => 'money_line',
                    'money line' => 'money_line',
                    'match result' => 'money_line',
                    'over/under' => 'totals',
                    'total' => 'totals',
                    'totals' => 'totals',
                    'handicap' => 'spreads',
                    'spread' => 'spreads',
                    'asian handicap' => 'spreads',
                ];
                
                foreach ($marketTypeMap as $key => $type) {
                    if (stripos($marketName, $key) !== false) {
                        $marketType = $type;
                        break;
                    }
                }
            }
            
            return [
                'provider' => 'pinnacle',
                'providers' => ['pinnacle'],
                'market_type' => $marketType,
                'selection' => $odd['selection'] ?? $odd['name'] ?? '',
                'line' => $odd['line'] ?? null,
                'price' => $odd['price'] ?? $odd['odds'] ?? null,
                'period' => $odd['period'] ?? 'Game',
                'status' => $odd['status'] ?? 'open',
                'metadata' => [
                    'pinnacle_market_id' => $odd['market_id'] ?? null,
                    'pinnacle_outcome_id' => $odd['outcome_id'] ?? null,
                    'original_market_name' => $odd['market_name'] ?? $odd['name'] ?? null,
                ]
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to normalize Pinnacle odd', [
                'error' => $e->getMessage(),
                'odd' => $odd
            ]);
            return null;
        }
    }

    /**
     * Normalize Odds-Feed odd to common structure
     * 
     * Expected Odds-Feed structure (flexible to handle variations):
     * - market_type, market, bet_type: Market type (e.g., 'match_winner', 'over_under')
     * - selection, outcome, pick: Selection (e.g., 'home', 'away', 'over 2.5')
     * - line, handicap, spread: Line/handicap value (if applicable)
     * - price, odds, decimal_odds: Odds value
     * - bookmaker, provider: Bookmaker/provider name
     * 
     * @param array $odd Raw Odds-Feed odd
     * @return array|null Normalized odd or null if invalid
     */
    protected function normalizeOddsFeedOdd(array $odd): ?array
    {
        try {
            // Extract market type
            $marketType = $odd['market_type'] ?? $odd['market'] ?? $odd['bet_type'] ?? '';
            if (empty($marketType)) {
                Log::debug('Odds-Feed odd missing market type', ['odd' => $odd]);
                return null;
            }

            // Map Odds-Feed market types to our standard types
            $marketTypeMap = [
                'match_winner' => 'money_line',
                '1x2' => 'money_line',
                'over_under' => 'totals',
                'total' => 'totals',
                'handicap' => 'spreads',
                'asian_handicap' => 'spreads',
                'player_props' => 'player_props',
                'player_proposition' => 'player_props',
            ];

            $normalizedMarketType = $marketTypeMap[strtolower($marketType)] ?? strtolower($marketType);

            // Extract selection
            $selection = $odd['selection'] ?? $odd['outcome'] ?? $odd['pick'] ?? '';
            if (empty($selection)) {
                Log::debug('Odds-Feed odd missing selection', [
                    'market_type' => $marketType,
                    'odd' => $odd
                ]);
                return null;
            }

            // Extract line/handicap (if applicable)
            $line = null;
            if (isset($odd['line'])) {
                $line = (float)$odd['line'];
            } elseif (isset($odd['handicap'])) {
                $line = (float)$odd['handicap'];
            } elseif (isset($odd['spread'])) {
                $line = (float)$odd['spread'];
            } elseif (isset($odd['total'])) {
                $line = (float)$odd['total'];
            }

            // Extract odds/price
            $price = null;
            if (isset($odd['price'])) {
                $price = (float)$odd['price'];
            } elseif (isset($odd['odds'])) {
                $price = (float)$odd['odds'];
            } elseif (isset($odd['decimal_odds'])) {
                $price = (float)$odd['decimal_odds'];
            }

            if ($price === null || $price <= 0) {
                Log::debug('Odds-Feed odd missing or invalid price', [
                    'market_type' => $marketType,
                    'selection' => $selection,
                    'odd' => $odd
                ]);
                return null;
            }

            // Extract bookmaker/provider
            $bookmaker = $odd['bookmaker'] ?? $odd['provider'] ?? 'odds_feed';

            // Build normalized odd structure
            $normalized = [
                'market_type' => $normalizedMarketType,
                'selection' => $selection,
                'line' => $line,
                'price' => $price,
                'bookmaker' => $bookmaker,
                'provider' => 'odds_feed',
                'metadata' => [
                    'source' => 'odds_feed',
                    'original_market_type' => $marketType,
                    'original_data' => $odd
                ]
            ];

            return $normalized;
        } catch (\Exception $e) {
            Log::error('Error normalizing Odds-Feed odd', [
                'odd' => $odd,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Normalize API-Football odd to common structure
     * 
     * @param array $odd Raw API-Football odd
     * @return array|null Normalized odd or null if invalid
     */
    protected function normalizeApiFootballOdd(array $odd): ?array
    {
        try {
            // API-Football structure: bookmakers[].bets[].values[]
            $bet = $odd['bet'] ?? [];
            $value = $odd['value'] ?? [];
            
            return [
                'provider' => 'api_football',
                'providers' => ['api_football'],
                'market_type' => $bet['name'] ?? 'unknown',
                'selection' => $value['value'] ?? '',
                'line' => $this->extractLineFromValue($value['value'] ?? ''),
                'price' => $value['odd'] ?? null,
                'period' => 'Game',
                'status' => 'open',
                'metadata' => [
                    'api_football_bookmaker' => $odd['bookmaker'] ?? null,
                    'api_football_bet_id' => $bet['id'] ?? null,
                ]
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to normalize API-Football odd', [
                'error' => $e->getMessage(),
                'odd' => $odd
            ]);
            return null;
        }
    }

    /**
     * Extract line from value string (e.g., "Over 2.5" -> "2.5")
     * 
     * @param string $value Value string
     * @return string|null Extracted line or null
     */
    protected function extractLineFromValue(string $value): ?string
    {
        if (preg_match('/([+-]?\d+\.?\d*)/', $value, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

