<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BetType extends Model
{
    use HasFactory;

    protected $table = 'bet_types';

    protected $fillable = ['sportId', 'category', 'name', 'description', 'isActive'];

    protected $casts = [
        'isActive' => 'boolean',
    ];

    public function sport()
    {
        return $this->belongsTo(Sport::class);
    }
}