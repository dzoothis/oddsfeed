<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamProviderMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TeamResolutionService
{
    /**
     * Resolve team ID from provider name and team identifier.
     *
     * @param string $providerName ('pinnacle', 'odds_api', 'api_football')
     * @param string $providerTeamName Team name from the provider
     * @param string|null $providerTeamId Team ID from the provider (if available)
     * @param int|null $sportId Sport ID for context
     * @param int|null $leagueId League ID for context
     * @return array ['team_id' => int, 'created' => bool, 'confidence' => float]
     */
    public function resolveTeamId(
        string $providerName,
        string $providerTeamName,
        ?string $providerTeamId = null,
        ?int $sportId = null,
        ?int $leagueId = null
    ): array {
        // Phase 1: Add Redis caching for team resolution performance
        $cacheKey = "team_resolution:{$providerName}:" . md5($providerTeamName . ($providerTeamId ?? ''));

        return Cache::remember($cacheKey, 3600, function () use ($providerName, $providerTeamName, $providerTeamId, $sportId, $leagueId) {
            return $this->resolveTeamIdUncached($providerName, $providerTeamName, $providerTeamId, $sportId, $leagueId);
        });
    }

    private function resolveTeamIdUncached(string $providerName, string $providerTeamName, ?string $providerTeamId = null, ?int $sportId = null, ?int $leagueId = null): array
    {
        // Phase 1: Add data validation
        if (!$this->validateTeamResolutionInput($providerName, $providerTeamName, $sportId)) {
            throw new \InvalidArgumentException('Invalid team resolution input');
        }

        // Normalize the team name for consistent matching
        $normalizedName = $this->normalizeTeamName($providerTeamName);

        // Step 1: Try exact provider ID match (highest confidence)
        if ($providerTeamId) {
            $mapping = TeamProviderMapping::where('provider_name', $providerName)
                ->where('provider_team_id', $providerTeamId)
                ->with('team')
                ->first();

            if ($mapping) {
                // Recalculate confidence with current data quality
                $confidenceData = [
                    'has_provider_id' => true, // We have provider ID
                    'exact_name_match' => $mapping->provider_team_name === $providerTeamName,
                    'normalized_name_match' => $this->normalizeTeamName($mapping->provider_team_name) === $normalizedName,
                    'sport_context_match' => true, // Assume sport context is valid
                    'provider' => $providerName
                ];

                $updatedConfidence = max($mapping->confidence_score, $this->calculateConfidenceScore($confidenceData));

                // Update confidence if improved
                if ($updatedConfidence > $mapping->confidence_score) {
                    $mapping->update(['confidence_score' => $updatedConfidence]);
                }

                Log::debug("Team resolved by provider ID - Phase 1: Updated confidence", [
                    'provider' => $providerName,
                    'provider_id' => $providerTeamId,
                    'team_id' => $mapping->team_id,
                    'team_name' => $mapping->team->name,
                    'old_confidence' => $mapping->confidence_score,
                    'new_confidence' => $updatedConfidence
                ]);

                return [
                    'team_id' => $mapping->team_id,
                    'created' => false,
                    'confidence' => $updatedConfidence
                ];
            }
        }

        // Step 2: Try exact provider name match
        $mapping = TeamProviderMapping::where('provider_name', $providerName)
            ->where('provider_team_name', $providerTeamName)
            ->with('team')
            ->first();

        if ($mapping) {
                // Recalculate confidence for name-based match
                $confidenceData = [
                    'has_provider_id' => !empty($mapping->provider_team_id),
                    'exact_name_match' => $mapping->provider_team_name === $providerTeamName,
                    'normalized_name_match' => true, // We matched on normalized name
                    'sport_context_match' => true, // Assume sport context is valid
                    'provider' => $providerName
                ];

                $updatedConfidence = max($mapping->confidence_score, $this->calculateConfidenceScore($confidenceData));

                // Update confidence if improved
                if ($updatedConfidence > $mapping->confidence_score) {
                    $mapping->update(['confidence_score' => $updatedConfidence]);
                }

                Log::debug("Team resolved by provider name - Phase 1: Updated confidence", [
                    'provider' => $providerName,
                    'provider_name' => $providerTeamName,
                    'team_id' => $mapping->team_id,
                    'team_name' => $mapping->team->name,
                    'old_confidence' => $mapping->confidence_score,
                    'new_confidence' => $updatedConfidence
                ]);

                return [
                    'team_id' => $mapping->team_id,
                    'created' => false,
                    'confidence' => $updatedConfidence
                ];
        }

        // Step 3: For Pinnacle (primary source), create new team
        if ($providerName === 'pinnacle') {
            return $this->createPinnacleTeam($providerTeamName, $providerTeamId, $sportId, $leagueId);
        }

        // Step 4: For secondary providers, try fuzzy matching against existing teams
        $fuzzyMatch = $this->findFuzzyTeamMatch($normalizedName, $sportId, $leagueId);
        if ($fuzzyMatch) {
            // Create mapping with calculated confidence
            $confidenceData = [
                'has_provider_id' => !empty($providerTeamId),
                'exact_name_match' => false,
                'normalized_name_match' => true,
                'sport_context_match' => true,
                'provider' => $providerName,
                'fuzzy_match_score' => $fuzzyMatch['confidence'] // From string similarity
            ];

            $confidenceScore = $this->calculateConfidenceScore($confidenceData) * $fuzzyMatch['confidence'];

            $this->createProviderMapping(
                $fuzzyMatch['team_id'],
                $providerName,
                $providerTeamName,
                $providerTeamId,
                $confidenceScore
            );

            Log::info("Created fuzzy team mapping - Phase 1: Meaningful confidence", [
                'provider' => $providerName,
                'team_name' => $providerTeamName,
                'matched_team_id' => $fuzzyMatch['team_id'],
                'similarity_score' => $fuzzyMatch['confidence'],
                'final_confidence' => $confidenceScore
            ]);

            return [
                'team_id' => $fuzzyMatch['team_id'],
                'created' => false,
                'confidence' => $confidenceScore
            ];
        }

        // Step 5: No match found - create new team (for secondary providers)
        $confidenceData = [
            'has_provider_id' => !empty($providerTeamId),
            'exact_name_match' => false, // No existing match found
            'normalized_name_match' => false,
            'sport_context_match' => true, // We have sport context
            'provider' => $providerName
        ];

        $confidenceScore = $this->calculateConfidenceScore($confidenceData);

        Log::info("Creating new team from secondary provider - Phase 1: Calculated confidence", [
            'provider' => $providerName,
            'provider_team_name' => $providerTeamName,
            'sport_id' => $sportId,
            'league_id' => $leagueId,
            'calculated_confidence' => $confidenceScore
        ]);

        $team = Team::create([
            'sportId' => $sportId,
            'leagueId' => null, // Don't set leagueId until leagues are imported
            'name' => $providerTeamName,
            'isActive' => true,
            'mapping_confidence' => $confidenceScore
        ]);

        // Create provider mapping with calculated confidence
        $this->createProviderMapping(
            $team->id,
            $providerName,
            $providerTeamName,
            $providerTeamId,
            $confidenceScore
        );

        return [
            'team_id' => $team->id,
            'created' => true,
            'confidence' => $confidenceScore
        ];
    }

    /**
     * Create a new team from Pinnacle data (primary source).
     */
    private function createPinnacleTeam(string $teamName, ?string $pinnacleId, ?int $sportId, ?int $leagueId): array
    {
        // Validate team data before creation
        $teamData = [
            'name' => $teamName,
            'sportId' => $sportId,
            'pinnacle_team_id' => $pinnacleId,
            'leagueId' => null
        ];

        if (!$this->validateTeamData($teamData)) {
            Log::error("Failed to create Pinnacle team - validation failed", [
                'team_name' => $teamName,
                'sport_id' => $sportId
            ]);
            return [
                'team_id' => null,
                'created' => false,
                'confidence' => 0.0
            ];
        }

        Log::info("Creating new team from Pinnacle - Phase 1: Data validation + meaningful confidence", [
            'team_name' => $teamName,
            'pinnacle_id' => $pinnacleId,
            'sport_id' => $sportId,
            'pinnacle_league_id' => $leagueId,
            'validation_passed' => true
        ]);

        $team = Team::create([
            'sportId' => $sportId,
            'leagueId' => null, // Don't set leagueId until leagues are imported
            'name' => $teamName,
            'pinnacle_team_id' => $pinnacleId,
            'pinnacleName' => $teamName, // Legacy field
            'isActive' => true,
            'mapping_confidence' => 1.0, // Pinnacle teams get perfect confidence
            'last_pinnacle_sync' => now()
        ]);

        // Team enrichment removed - keeping only flag images

        // Create primary Pinnacle mapping with calculated confidence
        $confidenceData = [
            'has_provider_id' => !empty($pinnacleId),
            'exact_name_match' => true,
            'normalized_name_match' => true,
            'sport_context_match' => true,
            'provider' => 'pinnacle'
        ];

        $confidenceScore = $this->calculateConfidenceScore($confidenceData);

        $this->createProviderMapping(
            $team->id,
            'pinnacle',
            $teamName,
            $pinnacleId,
            $confidenceScore,
            true // is_primary
        );

        return [
            'team_id' => $team->id,
            'created' => true,
            'confidence' => $confidenceScore
        ];
    }

    /**
     * Find a team using fuzzy matching.
     */
    private function findFuzzyTeamMatch(string $normalizedName, ?int $sportId, ?int $leagueId): ?array
    {
        // Enhanced fuzzy matching with safeguards - Phase 1

        // Step 1: Try exact normalized name match first (within sport and league context)
        $query = Team::where('sportId', $sportId);

        if ($leagueId) {
            $query->where('leagueId', $leagueId);
        }

        $team = $query->whereRaw('LOWER(REPLACE(REPLACE(name, " ", ""), ".", "")) = ?', [$normalizedName])
            ->first();

        if ($team) {
            Log::debug("Exact normalized match found - Phase 1: Enhanced safeguards", [
                'normalized_name' => $normalizedName,
                'matched_team' => $team->name,
                'sport_id' => $sportId,
                'league_id' => $leagueId
            ]);

            return [
                'team_id' => $team->id,
                'confidence' => 0.9 // High confidence for exact normalized match
            ];
        }

        // Step 2: Search within same sport and league context (prioritize league matches)
        $query = Team::where('sportId', $sportId);

        if ($leagueId) {
            // First try league-specific matches
            $leagueCandidates = $query->where('leagueId', $leagueId)->get();

            $bestMatch = $this->findBestFuzzyMatch($normalizedName, $leagueCandidates, 0.85);
            if ($bestMatch) {
                return $bestMatch;
            }
        }

        // Step 3: Fallback to sport-wide search (but still within sport)
        $sportCandidates = $query->get();
        $bestMatch = $this->findBestFuzzyMatch($normalizedName, $sportCandidates, 0.90); // Higher threshold for cross-league

        if ($bestMatch) {
            Log::info("Cross-league fuzzy match found - Phase 1: Enhanced safeguards", [
                'normalized_name' => $normalizedName,
                'matched_team' => Team::find($bestMatch['team_id'])->name,
                'sport_id' => $sportId,
                'original_league_id' => $leagueId,
                'matched_league_id' => Team::find($bestMatch['team_id'])->leagueId,
                'similarity_score' => $bestMatch['confidence']
            ]);
        }

        return $bestMatch;
    }

    private function findBestFuzzyMatch(string $normalizedName, $candidates, float $minThreshold): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $candidateNormalized = $this->normalizeTeamName($candidate->name);
            $similarity = $this->calculateSimilarity($normalizedName, $candidateNormalized);

            // Require minimum threshold to prevent false positives
            if ($similarity >= $minThreshold && $similarity > $bestScore) {
                $bestMatch = [
                    'team_id' => $candidate->id,
                    'confidence' => $similarity
                ];
                $bestScore = $similarity;
            }
        }

        return $bestMatch;
    }

    /**
     * Create a provider mapping for a team.
     */
    private function createProviderMapping(
        int $teamId,
        string $providerName,
        string $providerTeamName,
        ?string $providerTeamId = null,
        float $confidence = 1.0,
        bool $isPrimary = false
    ): void {
        // Check if mapping already exists
        $existing = TeamProviderMapping::where('team_id', $teamId)
            ->where('provider_name', $providerName)
            ->where(function($query) use ($providerTeamId, $providerTeamName) {
                if ($providerTeamId) {
                    $query->where('provider_team_id', $providerTeamId);
                } else {
                    $query->where('provider_team_name', $providerTeamName);
                }
            })
            ->first();

        if ($existing) {
            // Update confidence if higher
            if ($confidence > $existing->confidence_score) {
                $existing->update(['confidence_score' => $confidence]);
            }
            return;
        }

        TeamProviderMapping::create([
            'team_id' => $teamId,
            'provider_name' => $providerName,
            'provider_team_id' => $providerTeamId,
            'provider_team_name' => $providerTeamName,
            'confidence_score' => $confidence,
            'is_primary' => $isPrimary
        ]);
    }

    /**
     * Normalize team name for consistent matching.
     */
    private function generateCacheKey(string $providerName, string $providerTeamName, ?string $providerTeamId, ?int $sportId, ?int $leagueId): string
    {
        // Generate deterministic cache key based on all resolution parameters
        $keyData = [
            'provider' => $providerName,
            'team_name' => $providerTeamName,
            'team_id' => $providerTeamId,
            'sport_id' => $sportId,
            'league_id' => $leagueId
        ];

        return 'team_resolution:' . md5(json_encode($keyData));
    }

    private function validateTeamResolutionInput(string $providerName, string $providerTeamName, ?int $sportId): bool
    {
        // Validate provider name
        $validProviders = ['pinnacle', 'odds_api', 'api_football'];
        if (!in_array($providerName, $validProviders)) {
            Log::warning("Invalid provider name for team resolution", [
                'provider_name' => $providerName,
                'valid_providers' => $validProviders
            ]);
            return false;
        }

        // Validate team name
        if (empty($providerTeamName) || strlen($providerTeamName) < 2) {
            Log::warning("Invalid team name for resolution", [
                'team_name' => $providerTeamName,
                'provider' => $providerName
            ]);
            return false;
        }

        // Validate sport ID (required for resolution)
        if (!$sportId || $sportId <= 0) {
            Log::warning("Invalid sport ID for team resolution", [
                'sport_id' => $sportId,
                'provider' => $providerName,
                'team_name' => $providerTeamName
            ]);
            return false;
        }

        return true;
    }

    private function validateTeamData(array $teamData): bool
    {
        // Required fields validation
        if (empty($teamData['name']) || empty($teamData['sportId'])) {
            Log::warning("Team data validation failed - missing required fields", [
                'has_name' => !empty($teamData['name']),
                'has_sport_id' => !empty($teamData['sportId'])
            ]);
            return false;
        }

        // Name format validation
        $nameLength = strlen($teamData['name']);
        if ($nameLength < 2 || $nameLength > 100) {
            Log::warning("Team data validation failed - invalid name length", [
                'name_length' => $nameLength,
                'name' => $teamData['name']
            ]);
            return false;
        }

        // Name contains only valid characters (basic check)
        if (!preg_match('/^[a-zA-Z0-9\s\-\.\'&]+$/', $teamData['name'])) {
            Log::warning("Team data validation failed - invalid characters in name", [
                'name' => $teamData['name']
            ]);
            return false;
        }

        // Sport exists validation
        if (!\App\Models\Sport::find($teamData['sportId'])) {
            Log::warning("Team data validation failed - sport does not exist", [
                'sport_id' => $teamData['sportId'],
                'name' => $teamData['name']
            ]);
            return false;
        }

        return true;
    }

    private function calculateConfidenceScore(array $matchData): float
    {
        $score = 0.0;

        // Base scores for data quality
        $score += ($matchData['has_provider_id'] ?? false) ? 0.3 : 0.0;
        $score += ($matchData['exact_name_match'] ?? false) ? 0.4 : 0.0;
        $score += ($matchData['normalized_name_match'] ?? false) ? 0.2 : 0.0;
        $score += ($matchData['sport_context_match'] ?? false) ? 0.1 : 0.0;

        // Provider-specific bonuses (Pinnacle gets highest priority)
        $score += match($matchData['provider'] ?? 'unknown') {
            'pinnacle' => 0.4,    // Primary source bonus
            'odds_api' => 0.2,    // Secondary bonus
            'api_football' => 0.2,
            default => 0.0
        };

        return min(1.0, $score); // Cap at 1.0
    }

    private function normalizeTeamName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $name));
    }

    /**
     * Calculate string similarity (simple Levenshtein-based).
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 && $len2 === 0) return 1.0;
        if ($len1 === 0 || $len2 === 0) return 0.0;

        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);

        return 1 - ($distance / $maxLen);
    }
}
