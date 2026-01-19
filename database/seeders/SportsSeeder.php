<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sport;

class SportsSeeder extends Seeder
{
    public function run()
    {
        $sports = [
            ['pinnacleId' => 1, 'name' => 'Soccer', 'isActive' => true],
            ['pinnacleId' => 2, 'name' => 'Tennis', 'isActive' => true],
            ['pinnacleId' => 3, 'name' => 'Basketball', 'isActive' => true],
            ['pinnacleId' => 4, 'name' => 'Hockey', 'isActive' => true],
            ['pinnacleId' => 5, 'name' => 'Volleyball', 'isActive' => true],
            ['pinnacleId' => 6, 'name' => 'Handball', 'isActive' => true],
            ['pinnacleId' => 7, 'name' => 'American Football', 'isActive' => true],
            ['pinnacleId' => 8, 'name' => 'Mixed Martial Arts', 'isActive' => true],
            ['pinnacleId' => 9, 'name' => 'Baseball', 'isActive' => true],
            ['pinnacleId' => 10, 'name' => 'E Sports', 'isActive' => true],
            ['pinnacleId' => 11, 'name' => 'Cricket', 'isActive' => true],
        ];

        foreach ($sports as $sport) {
            Sport::create($sport);
        }
    }
}