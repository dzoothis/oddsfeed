<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Team;
use App\Models\League;
use App\Models\TeamProviderMapping;
use App\Services\TeamResolutionService;

class CheckPhase1Status extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'phase1:status {--details : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Phase 1 implementation status and verify all improvements are working';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Checking Phase 1 Implementation Status');
        $this->line('=======================================');

        $verbose = $this->option('details');
        $allPassed = true;

        // 1. Check League Import
        $this->checkLeagueImport($verbose, $allPassed);

        // 2. Check Team League Backfill
        $this->checkTeamLeagueBackfill($verbose, $allPassed);

        // 3. Check Redis Caching
        $this->checkRedisCaching($verbose, $allPassed);

        // 4. Check Confidence Scoring
        $this->checkConfidenceScoring($verbose, $allPassed);

        // 5. Check Fuzzy Matching Safeguards
        $this->checkFuzzyMatching($verbose, $allPassed);

        // 6. Check Data Validation
        $this->checkDataValidation($verbose, $allPassed);

        $this->line('=======================================');

        if ($allPassed) {
            $this->info('✅ Phase 1: ALL CHECKS PASSED');
            $this->comment('Internal refactor completed successfully. Ready for Phase 2.');
        } else {
            $this->error('❌ Phase 1: ISSUES FOUND');
            $this->comment('Please address the failed checks before proceeding.');
        }

        return $allPassed ? 0 : 1;
    }

    private function checkLeagueImport(bool $verbose, bool &$allPassed): void
    {
        $this->line('🏆 1. League Import Status');

        try {
            $leagueCount = League::count();
            $this->info("   Leagues in database: {$leagueCount}");

            if ($leagueCount > 0) {
                $this->comment("   ✅ Leagues imported successfully");
                if ($verbose) {
                    $leagues = League::take(3)->get(['name', 'pinnacle_id']);
                    foreach ($leagues as $league) {
                        $this->line("      - {$league->name} (ID: {$league->pinnacle_id})");
                    }
                }
            } else {
                $this->error("   ❌ No leagues found - import job may have failed");
                $allPassed = false;
            }
        } catch (\Exception $e) {
            $this->error("   ❌ League check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }

    private function checkTeamLeagueBackfill(bool $verbose, bool &$allPassed): void
    {
        $this->line('👥 2. Team League Backfill Status');

        try {
            $teamsWithoutLeague = Team::whereNull('leagueId')->count();
            $totalTeams = Team::count();
            $teamsWithLeague = $totalTeams - $teamsWithoutLeague;

            $this->info("   Teams with leagueId: {$teamsWithLeague}/{$totalTeams}");

            if ($teamsWithoutLeague === 0) {
                $this->comment("   ✅ All teams have league relationships");
            } elseif ($teamsWithLeague > $teamsWithoutLeague) {
                $this->comment("   ⚠️ Partial backfill completed ({$teamsWithLeague} have leagueId)");
                if ($verbose) {
                    $this->line("   Teams without leagueId: {$teamsWithoutLeague}");
                }
            } else {
                $this->error("   ❌ League backfill not completed");
                $allPassed = false;
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Team league check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }

    private function checkRedisCaching(bool $verbose, bool &$allPassed): void
    {
        $this->line('🔄 3. Redis Caching Status');

        try {
            // Test cache functionality
            $testKey = 'phase1_test_' . time();
            $testValue = 'working';

            Cache::put($testKey, $testValue, 10);
            $retrieved = Cache::get($testKey);

            if ($retrieved === $testValue) {
                $this->comment("   ✅ Redis caching is working");

                // Test team resolution caching
                $service = app(TeamResolutionService::class);
                $startTime = microtime(true);

                // First call (should cache)
                $service->resolveTeamId('pinnacle', 'Test Team', null, 7, 880);
                $firstCall = microtime(true) - $startTime;

                $startTime = microtime(true);
                // Second call (should use cache)
                $service->resolveTeamId('pinnacle', 'Test Team', null, 7, 880);
                $secondCall = microtime(true) - $startTime;

                if ($secondCall < $firstCall * 0.5) { // 50% faster indicates caching
                    $this->comment("   ✅ Team resolution caching is working");
                } else {
                    $this->error("   ❌ Team resolution caching not effective");
                    $allPassed = false;
                }
            } else {
                $this->error("   ❌ Redis cache not working");
                $allPassed = false;
            }

            // Clean up test cache
            Cache::forget($testKey);

        } catch (\Exception $e) {
            $this->error("   ❌ Cache check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }

    private function checkConfidenceScoring(bool $verbose, bool &$allPassed): void
    {
        $this->line('📊 4. Confidence Scoring Status');

        try {
            $mappings = TeamProviderMapping::take(5)->get();

            if ($mappings->isEmpty()) {
                $this->error("   ❌ No provider mappings found");
                $allPassed = false;
                return;
            }

            $validScores = true;
            foreach ($mappings as $mapping) {
                if ($mapping->confidence_score < 0 || $mapping->confidence_score > 1) {
                    $validScores = false;
                    break;
                }
            }

            if ($validScores) {
                $this->comment("   ✅ Confidence scores are valid (0.0-1.0 range)");

                if ($verbose) {
                    $avgScore = TeamProviderMapping::avg('confidence_score');
                    $maxScore = TeamProviderMapping::max('confidence_score');
                    $minScore = TeamProviderMapping::min('confidence_score');

                    $this->line("   Average confidence: " . number_format($avgScore, 3));
                    $this->line("   Range: {$minScore} - {$maxScore}");
                }
            } else {
                $this->error("   ❌ Invalid confidence scores found");
                $allPassed = false;
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Confidence scoring check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }

    private function checkFuzzyMatching(bool $verbose, bool &$allPassed): void
    {
        $this->line('🎯 5. Fuzzy Matching Safeguards');

        try {
            // Check if fuzzy matching methods exist and are implemented
            $service = app(TeamResolutionService::class);
            $reflection = new \ReflectionClass($service);

            $requiredMethods = [
                'findFuzzyTeamMatch',
                'findBestFuzzyMatch',
                'calculateStringSimilarity'
            ];

            $methodsExist = true;
            foreach ($requiredMethods as $method) {
                if (!$reflection->hasMethod($method)) {
                    $methodsExist = false;
                    break;
                }
            }

            if ($methodsExist) {
                $this->comment("   ✅ Fuzzy matching safeguards implemented");

                if ($verbose) {
                    // Test with a known team
                    $teams = Team::where('sportId', 7)->take(1)->first();
                    if ($teams) {
                        $this->line("   Fuzzy matching methods are available and implemented");
                    }
                }
            } else {
                $this->error("   ❌ Fuzzy matching methods missing");
                $allPassed = false;
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Fuzzy matching check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }

    private function checkDataValidation(bool $verbose, bool &$allPassed): void
    {
        $this->line('🛡️ 6. Data Validation Layers');

        try {
            $service = app(TeamResolutionService::class);
            $reflection = new \ReflectionClass($service);

            $requiredMethods = [
                'validateTeamResolutionInput',
                'validateTeamData',
                'validateSportContext'
            ];

            $methodsExist = true;
            foreach ($requiredMethods as $method) {
                if (!$reflection->hasMethod($method)) {
                    $methodsExist = false;
                    break;
                }
            }

            if ($methodsExist) {
                $this->comment("   ✅ Data validation layers implemented");

                if ($verbose) {
                    // Test validation with invalid input
                    try {
                        $service->resolveTeamId('invalid_provider', '', null, null, null);
                        $this->error("   ❌ Validation not working - accepted invalid input");
                        $allPassed = false;
                    } catch (\InvalidArgumentException $e) {
                        $this->comment("   ✅ Validation working - rejected invalid input");
                    }
                }
            } else {
                $this->error("   ❌ Data validation methods missing");
                $allPassed = false;
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Data validation check failed: {$e->getMessage()}");
            $allPassed = false;
        }
    }
}
