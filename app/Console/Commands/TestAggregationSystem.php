<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PinnacleService;
use App\Services\ApiFootballService;
use App\Services\MatchAggregationService;
use App\Services\OddsAggregationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TestAggregationSystem extends Command
{
    protected $signature = 'test:aggregation {sport_id=1} {--detailed}';
    protected $description = 'Test provider-agnostic aggregation system by comparing third-party APIs with our API';

    public function handle()
    {
        $sportId = (int) $this->argument('sport_id');
        $detailed = $this->option('detailed');
        $ourMatchesCount = 0; // Initialize

        $this->info("🧪 Testing Provider-Agnostic Aggregation System");
        $this->info("Sport ID: {$sportId}");
        $this->newLine();

        // Step 1: Fetch from all third-party APIs
        $this->info("📡 Step 1: Fetching from third-party APIs...");
        $this->newLine();

        // 1.1: Pinnacle
        $this->info("  1.1 Fetching from Pinnacle API...");
        $pinnacleService = app(PinnacleService::class);
        $pinnacleMatches = [];
        try {
            $pinnacleData = $pinnacleService->getMatchesByLeagues($sportId, [], 'live');
            $pinnacleMatches = $pinnacleData['events'] ?? [];
            $this->info("     ✅ Pinnacle: " . count($pinnacleMatches) . " live matches");
        } catch (\Exception $e) {
            $this->error("     ❌ Pinnacle failed: " . $e->getMessage());
        }

        // 1.2: API-Football
        $this->info("  1.2 Fetching from API-Football...");
        $apiFootballService = app(ApiFootballService::class);
        $apiFootballMatches = [];
        try {
            $apiFootballData = $apiFootballService->getFixtures(null, null, true); // live=true
            $apiFootballMatches = $apiFootballData['response'] ?? [];
            $this->info("     ✅ API-Football: " . count($apiFootballMatches) . " live matches");
        } catch (\Exception $e) {
            $this->error("     ❌ API-Football failed: " . $e->getMessage());
        }

        // 1.3: Odds-Feed (if available)
        $this->info("  1.3 Checking Odds-Feed...");
        $oddsFeedMatches = [];
        // TODO: Implement when available
        $this->info("     ⚠️  Odds-Feed: Not implemented yet");

        $this->newLine();

        // Step 2: Aggregate matches
        $this->info("🔄 Step 2: Aggregating matches from all providers...");
        $aggregationService = app(MatchAggregationService::class);
        $aggregatedMatches = $aggregationService->aggregateMatches(
            $pinnacleMatches,
            $oddsFeedMatches,
            $apiFootballMatches
        );

        $this->info("     Total aggregated: " . count($aggregatedMatches) . " unique matches");
        $this->newLine();

        // Step 3: Compare with our API
        $this->info("🔍 Step 3: Comparing with our API response...");
        try {
            $ourApiResponse = Http::get(config('app.url') . '/api/matches', [
                'sport_id' => $sportId,
                'match_type' => 'live',
                'timezone' => 'UTC'
            ]);

            if ($ourApiResponse->successful()) {
                $ourMatches = $ourApiResponse->json()['matches'] ?? [];
                $this->info("     Our API: " . count($ourMatches) . " live matches");
            } else {
                $this->error("     ❌ Our API failed: " . $ourApiResponse->status());
                $ourMatches = [];
            }
        } catch (\Exception $e) {
            $this->error("     ❌ Our API error: " . $e->getMessage());
            $ourMatches = [];
        }

        $this->newLine();

        // Step 4: Detailed Analysis
        $this->info("📊 Step 4: Detailed Analysis");
        $this->newLine();

        // 4.1: Provider coverage
        $this->info("  4.1 Provider Coverage:");
        $this->table(
            ['Provider', 'Matches', 'Percentage'],
            [
                ['Pinnacle', count($pinnacleMatches), $this->calculatePercentage(count($pinnacleMatches), count($aggregatedMatches))],
                ['API-Football', count($apiFootballMatches), $this->calculatePercentage(count($apiFootballMatches), count($aggregatedMatches))],
                ['Odds-Feed', count($oddsFeedMatches), $this->calculatePercentage(count($oddsFeedMatches), count($aggregatedMatches))],
                ['Aggregated (Union)', count($aggregatedMatches), '100%'],
                ['Our Database', $ourMatchesCount, $this->calculatePercentage($ourMatchesCount, count($aggregatedMatches))],
            ]
        );

        // 4.2: Deduplication analysis
        $this->newLine();
        $this->info("  4.2 Deduplication Analysis:");
        $totalBeforeDedup = count($pinnacleMatches) + count($apiFootballMatches) + count($oddsFeedMatches);
        $duplicatesRemoved = $totalBeforeDedup - count($aggregatedMatches);
        $this->info("     Total before deduplication: {$totalBeforeDedup}");
        $this->info("     Total after deduplication: " . count($aggregatedMatches));
        $this->info("     Duplicates removed: {$duplicatesRemoved}");
        $this->info("     Deduplication rate: " . round(($duplicatesRemoved / max($totalBeforeDedup, 1)) * 100, 2) . "%");

        // 4.3: Live status analysis
        $this->newLine();
        $this->info("  4.3 Live Status Analysis:");
        $liveFromPinnacle = count(array_filter($pinnacleMatches, fn($m) => ($m['live_status_id'] ?? 0) > 0));
        $liveFromApiFootball = count(array_filter($apiFootballMatches, fn($m) => 
            in_array($m['fixture']['status']['short'] ?? '', ['1H', '2H', 'HT', 'LIVE']) ||
            in_array($m['fixture']['status']['long'] ?? '', ['First Half', 'Second Half', 'Halftime', 'Extra Time', 'Penalty'])
        ));
        $liveFromAggregated = count(array_filter($aggregatedMatches, fn($m) => ($m['is_live'] ?? false) || ($m['live_status_id'] ?? 0) > 0));
        
        $this->info("     Pinnacle live: {$liveFromPinnacle}");
        $this->info("     API-Football live: {$liveFromApiFootball}");
        $this->info("     Aggregated live: {$liveFromAggregated}");

        // 4.4: Match overlap analysis
        $this->newLine();
        $this->info("  4.4 Match Overlap Analysis:");
        
        // Extract team names for comparison
        $pinnacleTeams = $this->extractTeamPairs($pinnacleMatches, 'pinnacle');
        $apiFootballTeams = $this->extractTeamPairs($apiFootballMatches, 'api_football');
        
        $overlap = array_intersect($pinnacleTeams, $apiFootballTeams);
        $pinnacleOnly = array_diff($pinnacleTeams, $apiFootballTeams);
        $apiFootballOnly = array_diff($apiFootballTeams, $pinnacleTeams);
        
        $this->info("     Matches in both Pinnacle and API-Football: " . count($overlap));
        $this->info("     Matches only in Pinnacle: " . count($pinnacleOnly));
        $this->info("     Matches only in API-Football: " . count($apiFootballOnly));

        // 4.5: Sample matches (detailed mode)
        if ($detailed) {
            $this->newLine();
            $this->info("  4.5 Sample Aggregated Matches (first 5):");
            $sampleMatches = array_slice($aggregatedMatches, 0, 5);
            foreach ($sampleMatches as $match) {
                $this->info("     - {$match['home_team']} vs {$match['away_team']}");
                $this->info("       Providers: " . implode(', ', $match['providers'] ?? []));
                $this->info("       Live: " . (($match['is_live'] ?? false) ? 'Yes' : 'No'));
                $this->info("       League: {$match['league_name']}");
            }
        }

        // Step 5: Coverage comparison
        $this->newLine();
        $this->info("📈 Step 5: Coverage Comparison");
        $this->newLine();
        
        $coverageGain = count($aggregatedMatches) - max(count($pinnacleMatches), count($apiFootballMatches));
        $this->info("     Best single provider: " . max(count($pinnacleMatches), count($apiFootballMatches)));
        $this->info("     Aggregated (union): " . count($aggregatedMatches));
        $this->info("     Coverage gain: +{$coverageGain} matches (" . round(($coverageGain / max(max(count($pinnacleMatches), count($apiFootballMatches)), 1)) * 100, 2) . "%)");

        // Step 6: Test odds aggregation
        $this->newLine();
        $this->info("💰 Step 6: Testing Odds Aggregation");
        
        if (!empty($aggregatedMatches)) {
            $sampleMatch = $aggregatedMatches[0];
            $this->info("     Sample match: {$sampleMatch['home_team']} vs {$sampleMatch['away_team']}");
            
            // Try to get odds for this match
            $pinnacleOdds = [];
            $apiFootballOdds = [];
            
            // This is a simplified test - in production, we'd need match IDs
            $this->info("     ⚠️  Odds aggregation test requires match IDs (skipping detailed test)");
        }

        // Final summary
        $this->newLine();
        $this->info("✅ Test Summary");
        $this->newLine();
        
        $summary = [
            ['Metric', 'Value'],
            ['Pinnacle matches', count($pinnacleMatches)],
            ['API-Football matches', count($apiFootballMatches)],
            ['Aggregated (unique)', count($aggregatedMatches)],
            ['Our Database matches', $ourMatchesCount],
            ['Coverage gain', "+{$coverageGain}"],
            ['Deduplication rate', round(($duplicatesRemoved / max($totalBeforeDedup, 1)) * 100, 2) . "%"],
        ];
        
        $this->table(['Metric', 'Value'], array_slice($summary, 1));

        // Check if our database matches aggregated count
        $diff = abs($ourMatchesCount - count($aggregatedMatches));
        if ($diff <= 10) {
            $this->info("✅ Our database count closely matches aggregated count (diff: {$diff})!");
        } else {
            $this->warn("⚠️  Our database count differs from aggregated count by {$diff}");
            $this->warn("     This is expected if LiveMatchSyncJob hasn't run yet with the new aggregation system.");
        }

        return Command::SUCCESS;
    }

    private function calculatePercentage($value, $total): string
    {
        if ($total == 0) return '0%';
        return round(($value / $total) * 100, 2) . '%';
    }

    private function extractTeamPairs(array $matches, string $source): array
    {
        $pairs = [];
        
        foreach ($matches as $match) {
            if ($source === 'pinnacle') {
                $home = strtolower($match['home'] ?? '');
                $away = strtolower($match['away'] ?? '');
            } else { // api_football
                $home = strtolower($match['teams']['home']['name'] ?? '');
                $away = strtolower($match['teams']['away']['name'] ?? '');
            }
            
            if ($home && $away) {
                // Normalize for comparison
                $home = preg_replace('/[^a-z0-9]/', '', $home);
                $away = preg_replace('/[^a-z0-9]/', '', $away);
                $pairs[] = min($home, $away) . '|' . max($home, $away);
            }
        }
        
        return array_unique($pairs);
    }
}

