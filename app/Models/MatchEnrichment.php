<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MatchEnrichment extends Model
{
    protected $fillable = [
        'match_id',
        'venue_name',
        'venue_city',
        'country',
        'source',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get enrichment data for a match, cached.
     */
    public static function getCachedEnrichment(string $matchId): ?array
    {
        $cacheKey = "match_enrichment:{$matchId}";

        return Cache::remember($cacheKey, 3600, function () use ($matchId) { // 1 hour cache
            $enrichment = self::where('match_id', $matchId)->first();

            return $enrichment ? [
                'venue_name' => $enrichment->venue_name,
                'venue_city' => $enrichment->venue_city,
                'country' => $enrichment->country,
                'last_synced_at' => $enrichment->last_synced_at?->toISOString(),
            ] : null;
        });
    }

    /**
     * Clear enrichment cache for a match.
     */
    public static function clearCache(string $matchId): void
    {
        Cache::forget("match_enrichment:{$matchId}");
    }
}
