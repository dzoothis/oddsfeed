<?php

namespace App\Services;

/**
 * Match Normalization Service
 * 
 * Provides consistent normalization for team names, league names, and other match data
 * to enable accurate deduplication across providers.
 */
class MatchNormalizationService
{
    /**
     * Normalize team name for matching
     * 
     * Handles:
     * - Case insensitivity
     * - Common abbreviations (Man Utd, Man United, Manchester United)
     * - Common suffixes (FC, AC, CF, SC, Club, United, City, etc.)
     * - Punctuation and special characters
     * - Parentheses content (for betting markets)
     * 
     * @param string $teamName Original team name
     * @return string Normalized team name
     */
    public function normalizeTeamName(string $teamName): string
    {
        if (empty($teamName)) {
            return '';
        }

        // Remove parentheses and their contents (betting markets)
        $normalized = preg_replace('/\s*\([^)]*\)/', '', $teamName);
        
        // Convert to lowercase
        $normalized = strtolower($normalized);
        
        // Remove common suffixes (order matters - remove longer first)
        $suffixes = [
            ' football club', ' fc', ' athletic club', ' ac', ' club de futbol', ' cf',
            ' sporting club', ' sc', ' club', ' united', ' city', ' town',
            ' athletic', ' wanderers', ' rovers', ' hotspur', ' albion', ' villans?',
            ' real', ' cf ', ' ac ', ' fc ', ' sc '
        ];
        
        foreach ($suffixes as $suffix) {
            $normalized = preg_replace('/\b' . preg_quote($suffix, '/') . '\b/i', '', $normalized);
            $normalized = preg_replace('/' . preg_quote($suffix, '/') . '\b/i', '', $normalized);
        }
        
        // Handle common abbreviations
        $abbreviations = [
            'man utd' => 'manchester united',
            'man united' => 'manchester united',
            'man city' => 'manchester city',
            'spurs' => 'tottenham hotspur',
            'tottenham' => 'tottenham hotspur',
            'arsenal' => 'arsenal',
            'chelsea' => 'chelsea',
            'liverpool' => 'liverpool',
        ];
        
        foreach ($abbreviations as $abbr => $full) {
            if (strpos($normalized, $abbr) !== false) {
                $normalized = str_replace($abbr, $full, $normalized);
            }
        }
        
        // Remove all non-alphanumeric characters (but keep spaces for now)
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        
        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        // Final step: remove spaces for strict matching
        // But keep a version with spaces for fuzzy matching
        return $normalized;
    }

    /**
     * Normalize league name for matching
     * 
     * @param string $leagueName Original league name
     * @return string Normalized league name
     */
    public function normalizeLeagueName(string $leagueName): string
    {
        if (empty($leagueName)) {
            return '';
        }

        // Convert to lowercase
        $normalized = strtolower($leagueName);
        
        // Remove common prefixes/suffixes
        $normalized = preg_replace('/\b(league|liga|serie|premier|championship|division|bundesliga|la liga)\b/i', '', $normalized);
        
        // Remove non-alphanumeric characters
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        
        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        return $normalized;
    }

    /**
     * Check if two team names match (with fuzzy matching)
     * 
     * @param string $team1 First team name
     * @param string $team2 Second team name
     * @return bool True if teams match
     */
    public function teamsMatch(string $team1, string $team2): bool
    {
        $normalized1 = $this->normalizeTeamName($team1);
        $normalized2 = $this->normalizeTeamName($team2);
        
        // Exact match after normalization
        if ($normalized1 === $normalized2) {
            return true;
        }
        
        // Fuzzy match using similarity
        $similarity = $this->calculateSimilarity($normalized1, $normalized2);
        return $similarity >= 0.85; // 85% similarity threshold
    }

    /**
     * Calculate string similarity (Levenshtein-based)
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0.0 to 1.0)
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        $len1 = strlen($str1);
        $len2 = strlen($str2);

        if ($len1 === 0 && $len2 === 0) {
            return 1.0;
        }
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }

        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);

        return 1 - ($distance / $maxLen);
    }
}

