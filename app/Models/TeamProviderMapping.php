<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamProviderMapping extends Model
{
    protected $fillable = [
        'team_id',
        'provider_name',
        'provider_team_id',
        'provider_team_name',
        'confidence_score',
        'is_primary',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'is_primary' => 'boolean',
    ];

    /**
     * Get the team that this mapping belongs to.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Scope for primary mappings only.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope for specific provider.
     */
    public function scopeProvider($query, $providerName)
    {
        return $query->where('provider_name', $providerName);
    }

    /**
     * Scope for high confidence mappings.
     */
    public function scopeHighConfidence($query, $threshold = 0.8)
    {
        return $query->where('confidence_score', '>=', $threshold);
    }
}
