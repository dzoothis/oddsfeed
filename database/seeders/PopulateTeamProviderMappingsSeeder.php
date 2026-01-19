<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PopulateTeamProviderMappingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info('Starting team provider mappings population');

        // Get all teams with provider names
        $teams = DB::table('teams')->where(function($query) {
            $query->whereNotNull('pinnacleName')
                  ->orWhereNotNull('oddsApiName')
                  ->orWhereNotNull('apiFootballName');
        })->get();

        $mappingsCreated = 0;

        foreach ($teams as $team) {
            // Pinnacle mapping (primary)
            if (!empty($team->pinnacleName)) {
                DB::table('team_provider_mappings')->insert([
                    'team_id' => $team->id,
                    'provider_name' => 'pinnacle',
                    'provider_team_name' => $team->pinnacleName,
                    'confidence_score' => 1.00, // Pinnacle is authoritative
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $mappingsCreated++;

                // Update the team with pinnacle_team_id (assuming pinnacleName is the ID for now)
                DB::table('teams')
                    ->where('id', $team->id)
                    ->update(['pinnacle_team_id' => $team->pinnacleName]);
            }

            // Odds API mapping
            if (!empty($team->oddsApiName)) {
                DB::table('team_provider_mappings')->insert([
                    'team_id' => $team->id,
                    'provider_name' => 'odds_api',
                    'provider_team_name' => $team->oddsApiName,
                    'confidence_score' => 0.90, // High confidence for existing mappings
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $mappingsCreated++;
            }

            // API Football mapping
            if (!empty($team->apiFootballName)) {
                DB::table('team_provider_mappings')->insert([
                    'team_id' => $team->id,
                    'provider_name' => 'api_football',
                    'provider_team_name' => $team->apiFootballName,
                    'confidence_score' => 0.90, // High confidence for existing mappings
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $mappingsCreated++;
            }
        }

        Log::info("Completed team provider mappings population. Created {$mappingsCreated} mappings for {$teams->count()} teams");
    }
}
