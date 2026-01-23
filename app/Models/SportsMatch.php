<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SportsMatch extends Model
{
    use HasFactory;

    protected $table = 'matches'; // Explicitly map to 'matches' table
    protected $primaryKey = 'eventId';
    public $incrementing = false;
    protected $keyType = 'int';

    // Map Laravel's expected timestamp columns to the actual column names
    const CREATED_AT = 'createdAt';
    const UPDATED_AT = 'lastUpdated';

    protected $guarded = [];

    protected $casts = [
        'startTime' => 'datetime',
        'lastUpdated' => 'datetime',
        'cornerCount' => 'array',
        'redCards' => 'array',
        'hasOpenMarkets' => 'boolean',
        'live_status_id' => 'integer',
        'match_type' => 'string',
        'betting_availability' => 'string',
        'home_score' => 'integer',
        'away_score' => 'integer',
    ];

    // Match status constants
    const STATUS_LIVE = 1;
    const STATUS_FINISHED = 2;
    const STATUS_SOFT_FINISHED = -1;

    public function markets()
    {
        return $this->hasMany(Market::class, 'eventId', 'eventId');
    }

    public function apiFootballData()
    {
        return $this->hasOne(ApiFootballData::class, 'eventId', 'eventId');
    }

    /**
     * Get the home team for this match.
     */
    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * Get the away team for this match.
     */
    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * Get the league for this match.
     */
    public function league()
    {
        return $this->belongsTo(League::class, 'leagueId', 'pinnacleId');
    }

    /**
     * Check if match is in live status
     */
    public function isLive(): bool
    {
        return $this->live_status_id === self::STATUS_LIVE;
    }

    /**
     * Check if match is finished
     */
    public function isFinished(): bool
    {
        return $this->live_status_id === self::STATUS_FINISHED;
    }

    /**
     * Check if match is soft finished
     */
    public function isSoftFinished(): bool
    {
        return $this->live_status_id === self::STATUS_SOFT_FINISHED;
    }

    /**
     * Check if match is available for live betting
     */
    public function isAvailableForLiveBetting(): bool
    {
        return $this->isLive() && $this->hasOpenMarkets && $this->betting_availability === 'live';
    }

    /**
     * Mark match as soft finished
     */
    public function markAsSoftFinished(): bool
    {
        $this->live_status_id = self::STATUS_SOFT_FINISHED;
        $this->betting_availability = 'finished'; // Mark as finished (shorter value for DB column)
        $this->lastUpdated = now();
        return $this->save();
    }

    /**
     * Mark match as finished
     */
    public function markAsFinished(): bool
    {
        $this->live_status_id = self::STATUS_FINISHED;
        $this->lastUpdated = now();
        return $this->save();
    }
}