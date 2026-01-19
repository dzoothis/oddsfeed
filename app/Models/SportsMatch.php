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
    ];

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
}