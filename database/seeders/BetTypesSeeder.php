<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sport;
use App\Models\BetType;
use App\Services\BetTypesDefinition;

class BetTypesSeeder extends Seeder
{
    public function run()
    {
        $betTypesData = BetTypesDefinition::getAllBetTypes();

        foreach ($betTypesData as $pinnacleSportId => $betTypes) {
            $sport = Sport::where('pinnacleId', $pinnacleSportId)->first();
            
            if ($sport) {
                foreach ($betTypes as $betType) {
                    BetType::create([
                        'sportId' => $sport->id,
                        'category' => $betType['category'],
                        'name' => $betType['name'],
                        'description' => $betType['description'],
                        'isActive' => true,
                    ]);
                }
            }
        }
    }
}