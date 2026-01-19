<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sport extends Model
{
    use HasFactory;

    protected $fillable = ['pinnacleId', 'name', 'isActive'];

    protected $casts = [
        'isActive' => 'boolean',
    ];

    protected $table = 'sports';

    public function betTypes()
    {
        return $this->hasMany(BetType::class);
    }
}