<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SystemAuditConsole extends Command
{
    protected $signature = 'system:audit';
    protected $description = 'Interactive console for sports-feed system health & fixes';

    public function handle()
    {
        $this->info("🚀 Welcome to the Sports-Feed Interactive Audit Console");
        while (true) {
            $choice = $this->choice(
                "Select action",
                [
                    '1️⃣ View Queue Status',
                    '2️⃣ View Matches / Leagues / Odds / Players Count',
                    '3️⃣ Dispatch League Import Job',
                    '4️⃣ Dispatch Match Import Job',
                    '5️⃣ Dispatch Odds Sync Job',
                    '6️⃣ Dispatch Player Cache Job',
                    '7️⃣ Clear Failed / Stale Jobs',
                    '8️⃣ Exit Console'
                ],
                0
            );

            switch ($choice) {
                case '1️⃣ View Queue Status':
                    $pending = DB::table('jobs')->count();
                    $failed = DB::table('failed_jobs')->count();
                    $this->line("Pending Jobs: $pending");
                    $this->line("Failed Jobs: $failed");
                    break;

                case '2️⃣ View Matches / Leagues / Odds / Players Count':
                    $this->line("Leagues: " . DB::table('leagues')->count());
                    $this->line("Matches: " . DB::table('matches')->count());
                    $this->line("Odds: " . DB::table('odds')->count());
                    $this->line("Players Cached: " . DB::table('team_players_cache')->count());
                    break;

                case '3️⃣ Dispatch League Import Job':
                    \App\Jobs\ImportLeaguesJob::dispatch()->onQueue('import');
                    $this->info("✅ League import job dispatched to import queue");
                    break;

                case '4️⃣ Dispatch Match Import Job':
                    $sports = [2,3,4,5]; // Tennis, Basketball, Hockey, Volleyball
                    foreach($sports as $sportId){
                        \App\Jobs\PrematchSyncJob::dispatch($sportId);
                    }
                    $this->info("✅ Match import jobs dispatched for all sports");
                    break;

                case '5️⃣ Dispatch Odds Sync Job':
                    $match = DB::table('matches')->first();
                    if($match){
                        \App\Jobs\OddsSyncJob::dispatch([$match->eventId]);
                        $this->info("✅ Odds sync dispatched for match ID: {$match->eventId}");
                    } else {
                        $this->warn("⚠️ No matches found to sync odds");
                    }
                    break;

                case '6️⃣ Dispatch Player Cache Job':
                    $teamIds = DB::table('teams')->pluck('id')->toArray();
                    \App\Jobs\CacheTeamPlayersJob::dispatch($teamIds);
                    $this->info("✅ Player cache job dispatched for " . count($teamIds) . " teams");
                    break;

                case '7️⃣ Clear Failed / Stale Jobs':
                    \Artisan::call('queue:clear');
                    \Artisan::call('queue:flush');
                    $this->info("✅ Cleared all pending and failed jobs");
                    break;

                case '8️⃣ Exit Console':
                    $this->info("👋 Exiting console");
                    exit;

                    case '9️⃣ Verify All Matches & Leagues':
                        $leagues = DB::table('leagues')->get();
                        $totalLeagues = $leagues->count();
                        $this->line("Total Leagues in DB: $totalLeagues");
                    
                        $missingMatches = 0;
                        foreach($leagues as $league){
                            $matchCount = DB::table('matches')->where('league_id', $league->id)->count();
                            if($matchCount === 0){
                                $missingMatches++;
                                $this->warn("⚠️ League ID {$league->id} ({$league->name}) has 0 matches");
                            }
                        }
                    
                        $this->info("✅ Verification complete. Leagues missing matches: $missingMatches / $totalLeagues");
                        break;
                        case '🔁 Sync Missing Matches':
                            $leagues = DB::table('leagues')->get();
                            foreach($leagues as $league){
                                $matchCount = DB::table('matches')->where('league_id', $league->id)->count();
                                if($matchCount === 0){
                                    \App\Jobs\PrematchSyncJob::dispatch($league->sport_id, [$league->id]);
                                    $this->line("🔄 Dispatching match import for League ID {$league->id} ({$league->name})");
                                }
                            }
                            $this->info("✅ All missing match import jobs dispatched");
                            break;
                                            
            }
            $this->newLine(2);
        }
    }
}
