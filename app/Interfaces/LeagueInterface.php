<?php

namespace App\Interfaces;

interface LeagueInterface
{
    public function getBySportId(int $sportId);
    public function getBySportIdPaginated(int $sportId, int $page = 1, int $perPage = 10);
}
