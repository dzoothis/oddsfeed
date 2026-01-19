<?php

namespace App\Interfaces;

interface MarketInterface
{
    public function getBySportId(int $sportId, string $type = 'all', array $leagueIds = []);
}
