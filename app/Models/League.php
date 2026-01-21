<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    protected $table = 'leagues';

    protected $fillable = ['sportId', 'name', 'pinnacleId', 'oddsApiKey', 'apiFootballId', 'isActive', 'lastPinnacleSync', 'league_coverage'];

    protected $casts = [
        'isActive' => 'boolean',
        'lastPinnacleSync' => 'datetime',
        'league_coverage' => 'string',
    ];

    public function sport()
    {
        return $this->belongsTo(Sport::class, 'sportId');
    }

    /**
     * Check if this league is considered a major league
     */
    public function isMajorLeague(): bool
    {
        return $this->league_coverage === 'major';
    }

    /**
     * Check if this league is considered a regional league
     */
    public function isRegionalLeague(): bool
    {
        return $this->league_coverage === 'regional';
    }

    /**
     * Get league coverage type, defaulting to regional if unknown
     */
    public function getCoverageType(): string
    {
        return $this->league_coverage ?: 'regional';
    }
}
