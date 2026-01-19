<?php
namespace App\Services;

class BetTypesDefinition
{
    const BET_TYPES_BY_SPORT = [
        // Soccer (Pinnacle ID: 1)
        1 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner (Home/Draw/Away)'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting (includes Asian Handicaps)'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total goals'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team goal totals'],
            ['category' => 'Player Props', 'name' => 'Player Props', 'description' => 'Player-specific betting markets'],
            ['category' => 'Futures', 'name' => 'Futures', 'description' => 'Future/outright betting markets'],
            ['category' => 'Team Props', 'name' => 'Both Teams to Score', 'description' => 'BTTS - Both teams score in match'],
            ['category' => 'Team Props', 'name' => 'Double Chance', 'description' => 'Two possible outcomes'],
            ['category' => 'Team Props', 'name' => 'Draw No Bet', 'description' => 'Match result excluding draw'],
            ['category' => 'Team Props', 'name' => 'Correct Score', 'description' => 'Exact match score'],
            ['category' => 'Team Props', 'name' => 'Exact Total Goals', 'description' => 'Exact number of goals'],
            ['category' => 'Team Props', 'name' => 'Winning Margin', 'description' => 'Margin of victory'],
            ['category' => 'Team Props', 'name' => 'Double Result', 'description' => 'Half-time and full-time result'],
            ['category' => 'Game Props', 'name' => 'Game Props', 'description' => 'Game-specific betting markets'],
            ['category' => 'Corners', 'name' => 'Corners', 'description' => 'Corner kick markets'],
        ],

        // Tennis (Pinnacle ID: 2)
        2 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total games/sets'],
            ['category' => 'Game Props', 'name' => 'Game Props', 'description' => 'Game-specific markets'],
        ],

        // Basketball (Pinnacle ID: 3)
        3 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Point spread betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total points'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team point totals'],
            ['category' => 'Player Props', 'name' => 'Player Props', 'description' => 'Player-specific betting markets'],
            ['category' => 'Player Props', 'name' => 'Player Points', 'description' => 'Over/Under player points scored'],
            ['category' => 'Player Props', 'name' => 'Player Assists', 'description' => 'Over/Under player assists'],
            ['category' => 'Player Props', 'name' => 'Player Rebounds', 'description' => 'Over/Under player rebounds'],
            ['category' => 'Team Props', 'name' => 'Team Props', 'description' => 'Team-specific markets'],
            ['category' => 'Game Props', 'name' => 'Game Props', 'description' => 'Game-specific markets'],
        ],

        // Hockey (Pinnacle ID: 4)
        4 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total goals'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team goal totals'],
            ['category' => 'Player Props', 'name' => 'Player Props', 'description' => 'Player-specific betting markets'],
            ['category' => 'Team Props', 'name' => 'Team Props', 'description' => 'Team-specific markets'],
        ],

        // Volleyball (Pinnacle ID: 5)
        5 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total sets/points'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team totals'],
        ],

        // Handball (Pinnacle ID: 6)
        6 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total goals'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team goal totals'],
        ],

        // American Football (Pinnacle ID: 7)
        7 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Point spread betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total points'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team point totals'],
            ['category' => 'Player Props', 'name' => 'Player Props', 'description' => 'Player-specific betting markets'],
            ['category' => 'Team Props', 'name' => 'Team Props', 'description' => 'Team-specific markets'],
            ['category' => 'Game Props', 'name' => 'Game Props', 'description' => 'Game-specific markets'],
        ],

        // Mixed Martial Arts (Pinnacle ID: 8)
        8 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Fight winner'],
            ['category' => 'Game Props', 'name' => 'Next Round', 'description' => 'Next round betting'],
            ['category' => 'Game Props', 'name' => 'Method of Victory', 'description' => 'How the fight ends'],
        ],

        // Baseball (Pinnacle ID: 9)
        9 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Run line betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total runs'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team run totals'],
            ['category' => 'Player Props', 'name' => 'Player Props', 'description' => 'Player-specific betting markets'],
            ['category' => 'Team Props', 'name' => 'Team Props', 'description' => 'Team-specific markets'],
        ],

        // E Sports (Pinnacle ID: 10)
        10 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total maps/rounds'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team totals'],
        ],

        // Cricket (Pinnacle ID: 11)
        11 => [
            ['category' => 'Money Line', 'name' => 'Money Line', 'description' => 'Match winner'],
            ['category' => 'Spreads', 'name' => 'Spreads', 'description' => 'Handicap betting'],
            ['category' => 'Totals', 'name' => 'Totals', 'description' => 'Over/Under total runs'],
            ['category' => 'Team Totals', 'name' => 'Team Totals', 'description' => 'Individual team run totals'],
        ],
    ];

    public static function getBetTypesForSport($sportId)
    {
        return self::BET_TYPES_BY_SPORT[$sportId] ?? [];
    }

    public static function getAllBetTypes()
    {
        return self::BET_TYPES_BY_SPORT;
    }

    public static function getCategoriesForSport($sportId)
    {
        $betTypes = self::getBetTypesForSport($sportId);
        return array_unique(array_column($betTypes, 'category'));
    }
}