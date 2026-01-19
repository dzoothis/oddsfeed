<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiFootballData extends Model
{
    use HasFactory;

    protected $primaryKey = 'eventId';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'yellowCards' => 'array',
        'redCards' => 'array',
        'incidents' => 'array',
        'status' => 'array',
        'lastUpdated' => 'datetime',
    ];
    
    public function match()
    {
        return $this->belongsTo(SportsMatch::class, 'eventId', 'eventId');
    }
}