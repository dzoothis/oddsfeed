<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Market extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:3',
        'lastUpdated' => 'datetime',
    ];

    public function match()
    {
        return $this->belongsTo(SportsMatch::class, 'eventId', 'eventId');
    }
}