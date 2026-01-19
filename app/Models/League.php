<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    protected $table = 'leagues';

    protected $fillable = ['sportId', 'name', 'pinnacleId', 'oddsApiKey', 'apiFootballId', 'isActive', 'lastPinnacleSync'];

    protected $casts = [
        'isActive' => 'boolean',
        'lastPinnacleSync' => 'datetime',
    ];

    public function sport()
    {
        return $this->belongsTo(Sport::class, 'sportId');
    }
}
