<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = [
        'sportId',
        'leagueId',
        'name',
        'pinnacleName',
        'oddsApiName',
        'apiFootballName',
        'pinnacle_team_id',
        'last_pinnacle_sync',
        'mapping_confidence',
        'isActive',
    ];

    protected $casts = [
        'isActive' => 'boolean',
        'last_pinnacle_sync' => 'datetime',
        'mapping_confidence' => 'decimal:2',
    ];

    /**
     * Get all provider mappings for this team.
     */
    public function providerMappings()
    {
        return $this->hasMany(TeamProviderMapping::class);
    }

    /**
     * Get Pinnacle mapping for this team.
     */
    public function pinnacleMapping()
    {
        return $this->hasOne(TeamProviderMapping::class)
                    ->where('provider_name', 'pinnacle')
                    ->where('is_primary', true);
    }

    /**
     * Get Odds API mapping for this team.
     */
    public function oddsApiMapping()
    {
        return $this->hasOne(TeamProviderMapping::class)
                    ->where('provider_name', 'odds_api')
                    ->where('is_primary', true);
    }

    /**
     * Get API Football mapping for this team.
     */
    public function apiFootballMapping()
    {
        return $this->hasOne(TeamProviderMapping::class)
                    ->where('provider_name', 'api_football')
                    ->where('is_primary', true);
    }

    /**
     * Get matches where this team is the home team.
     */
    public function homeMatches()
    {
        return $this->hasMany(SportsMatch::class, 'home_team_id');
    }

    /**
     * Get matches where this team is the away team.
     */
    public function awayMatches()
    {
        return $this->hasMany(SportsMatch::class, 'away_team_id');
    }

    /**
     * Get all matches for this team.
     */
    public function matches()
    {
        return SportsMatch::where('home_team_id', $this->id)
                          ->orWhere('away_team_id', $this->id);
    }

    /**
     * Get enrichments for this team.
     */
    public function enrichments()
    {
        return $this->hasMany(TeamEnrichment::class);
    }
}
