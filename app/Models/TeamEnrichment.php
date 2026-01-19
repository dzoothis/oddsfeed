<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamEnrichment extends Model
{
    protected $fillable = [
        'team_id',
        'logo_url',
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
     * Get the team that owns the enrichment.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get enrichment data for a team, cached.
     */
    public static function getCachedEnrichment(int $teamId): ?array
    {
        $cacheKey = "team_enrichment:{$teamId}";

        return \Cache::remember($cacheKey, 3600, function () use ($teamId) { // 1 hour cache
            $enrichment = self::where('team_id', $teamId)->first();

            return $enrichment ? [
                'logo_url' => $enrichment->logo_url,
                'venue_name' => $enrichment->venue_name,
                'venue_city' => $enrichment->venue_city,
                'country' => $enrichment->country,
                'last_synced_at' => $enrichment->last_synced_at?->toISOString(),
            ] : null;
        });
    }

    /**
     * Clear enrichment cache for a team.
     */
    public static function clearCache(int $teamId): void
    {
        \Cache::forget("team_enrichment:{$teamId}");
    }
}
