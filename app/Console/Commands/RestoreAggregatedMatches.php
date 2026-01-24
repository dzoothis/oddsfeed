<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PinnacleService;
use App\Services\ApiFootballService;
use App\Services\MatchAggregationService;
use App\Models\SportsMatch;
use Illuminate\Support\Facades\Log;

class RestoreAggregatedMatches extends Command
{
    protected $signature = 'matches:restore-aggregated {sport_id=1}';
    protected $description = 'Restore all matches from aggregation system that were incorrectly marked as finished';

    public function handle()
    {
        $sportId = (int) $this->argument('sport_id');
        
        $this->info("🔄 Restoring matches from aggregation system for sport ID: {$sportId}");
        $this->newLine();

        // Fetch from all providers
        $pinnacleService = app(PinnacleService::class);
        $apiFootballService = app(ApiFootballService::class);
        $aggregationService = app(MatchAggregationService::class);

        $this->info("📡 Fetching from providers...");
        $pinnacleData = $pinnacleService->getMatchesByLeagues($sportId, [], 'live');
        $pinnacleMatches = $pinnacleData['events'] ?? [];
        
        $apiFootballData = $apiFootballService->getFixtures(null, null, true);
        $apiFootballMatches = $apiFootballData['response'] ?? [];

        $this->info("   Pinnacle: " . count($pinnacleMatches) . " matches");
        $this->info("   API-Football: " . count($apiFootballMatches) . " matches");

        // Aggregate
        $this->newLine();
        $this->info("🔄 Aggregating matches...");
        $aggregated = $aggregationService->aggregateMatches(
            $pinnacleMatches,
            [],
            $apiFootballMatches
        );
        $this->info("   Aggregated: " . count($aggregated) . " unique matches");

        // Restore matches
        $this->newLine();
        $this->info("🔧 Restoring matches...");
        $restored = 0;
        $created = 0;
        $skipped = 0;

        foreach ($aggregated as $match) {
            $liveStatusId = $match['live_status_id'] ?? 0;
            if ($liveStatusId <= 0) {
                $skipped++;
                continue;
            }

            $eventId = $match['event_id'] ?? null;
            if (!$eventId) {
                $metadata = $match['metadata'] ?? [];
                $eventId = $metadata['pinnacle_event_id'] ?? 
                          $metadata['api_football_fixture_id'] ?? 
                          null;
            }

            if (!$eventId) {
                $skipped++;
                continue;
            }

            $existing = SportsMatch::where('eventId', $eventId)->first();
            
            if ($existing) {
                // ALWAYS update if aggregation says it's live, regardless of current status
                $wasFinished = ($existing->live_status_id == 2 || $existing->live_status_id == -1);
                
                // Update to match aggregation system
                $existing->live_status_id = $liveStatusId;
                $existing->home_score = $match['home_score'] ?? $existing->home_score ?? 0;
                $existing->away_score = $match['away_score'] ?? $existing->away_score ?? 0;
                $existing->homeTeam = $match['home_team'] ?? $existing->homeTeam;
                $existing->awayTeam = $match['away_team'] ?? $existing->awayTeam;
                $existing->leagueName = $match['league_name'] ?? $existing->leagueName ?? 'Unknown';
                $existing->eventType = ($liveStatusId > 0) ? 'live' : 'prematch';
                $existing->betting_availability = $match['betting_availability'] ?? $existing->betting_availability ?? 'prematch';
                $existing->hasOpenMarkets = $match['has_open_markets'] ?? $existing->hasOpenMarkets ?? false;
                $existing->lastUpdated = now();
                
                if ($match['start_time']) {
                    $startTime = $match['start_time'] instanceof \Carbon\Carbon 
                        ? $match['start_time'] 
                        : \Carbon\Carbon::parse($match['start_time']);
                    $existing->startTime = $startTime;
                }
                
                $existing->save();
                
                if ($wasFinished) {
                    $restored++;
                }
            } else {
                // Create new match
                $startTime = $match['start_time'] instanceof \Carbon\Carbon 
                    ? $match['start_time'] 
                    : ($match['start_time'] ? \Carbon\Carbon::parse($match['start_time']) : null);

                SportsMatch::create([
                    'eventId' => $eventId,
                    'sportId' => $sportId,
                    'homeTeam' => $match['home_team'] ?? 'Unknown',
                    'awayTeam' => $match['away_team'] ?? 'Unknown',
                    'leagueId' => $match['league_id'] ?? null,
                    'leagueName' => $match['league_name'] ?? 'Unknown',
                    'startTime' => $startTime,
                    'eventType' => ($liveStatusId > 0) ? 'live' : 'prematch',
                    'live_status_id' => $liveStatusId,
                    'betting_availability' => $match['betting_availability'] ?? 'prematch',
                    'hasOpenMarkets' => $match['has_open_markets'] ?? false,
                    'home_score' => $match['home_score'] ?? 0,
                    'away_score' => $match['away_score'] ?? 0,
                    'lastUpdated' => now(),
                ]);
                $created++;
            }
        }

        $this->newLine();
        $this->info("✅ Restoration complete!");
        $this->table(
            ['Action', 'Count'],
            [
                ['Restored from finished', $restored],
                ['Created new', $created],
                ['Skipped', $skipped],
                ['Total processed', count($aggregated)],
            ]
        );

        // Check final count
        $this->newLine();
        $finalCount = SportsMatch::where('sportId', $sportId)
            ->where('live_status_id', '>', 0)
            ->where('startTime', '<=', now())
            ->where('startTime', '>', now()->subHours(48))
            ->count();
        
        $this->info("📊 Active live matches in database: {$finalCount}");

        return Command::SUCCESS;
    }
}

