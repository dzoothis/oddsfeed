<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\League;

class SetMajorLeagues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leagues:set-major';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set major leagues for soft_finished implementation testing';

    /**
     * Major league patterns - leagues that get authoritative "finished" status
     *
     * These are leagues with reliable, real-time data sources and high betting volume.
     * When matches in these leagues end without confirmation, they can be safely marked as "finished"
     * and removed from active betting. All other leagues default to "regional" and get "soft_finished" status.
     */
    protected array $majorLeaguePatterns = [
        // Top European Soccer Leagues (5 major)
        'Premier League',     // England - Most watched league globally
        'Serie A',           // Italy - High betting volume
        'La Liga',           // Spain - Major international coverage
        'Bundesliga',        // Germany - Professional data sources
        'Ligue 1',           // France - UEFA coefficient top tier

        // Other European Soccer Leagues
        'Eredivisie',        // Netherlands - Good coverage
        'Primeira Liga',     // Portugal - UEFA coefficient

        // Major International Competitions
        'Champions League',  // UEFA - Highest profile club competition
        'Europa League',     // UEFA - Major European competition
        'UEFA',              // UEFA competitions (Conference League, etc.)
        'FIFA World Cup',    // World Cup - Authoritative FIFA data

        // Major US Sports Leagues
        'NBA',               // Basketball - Real-time score updates
        'NFL',               // American Football - Extensive coverage
        'MLB',               // Baseball - Professional data feeds
        'NHL',               // Hockey - Good real-time data
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Setting major leagues for soft_finished implementation...');

        $updated = 0;

        foreach ($this->majorLeaguePatterns as $pattern) {
            $leagues = League::where('name', 'like', '%' . $pattern . '%')->get();

            foreach ($leagues as $league) {
                if ($league->league_coverage !== 'major') {
                    $league->update(['league_coverage' => 'major']);
                    $this->line("Set {$league->name} (ID: {$league->pinnacleId}) as major league");
                    $updated++;
                }
            }
        }

        // Set remaining leagues to regional (default is already 'unknown', but we'll set to 'regional')
        $regionalLeagues = League::where('league_coverage', '!=', 'major')
                                ->orWhereNull('league_coverage')
                                ->get();

        foreach ($regionalLeagues as $league) {
            $league->update(['league_coverage' => 'regional']);
        }

        $this->info("Updated {$updated} leagues to major status");
        $this->info("Set " . $regionalLeagues->count() . " leagues to regional status");

        return 0;
    }
}
