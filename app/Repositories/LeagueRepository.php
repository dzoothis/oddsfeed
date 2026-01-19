<?php

namespace App\Repositories;

use App\Interfaces\LeagueInterface;
use App\Interfaces\SportInterface;
use App\Services\PinnacleService;

class LeagueRepository implements LeagueInterface
{
    private PinnacleService $pinnacleService;
    private SportInterface $sportRepository;

    public function __construct(PinnacleService $pinnacleService, SportInterface $sportRepository)
    {
        $this->pinnacleService = $pinnacleService;
        $this->sportRepository = $sportRepository;
    }

    public function getBySportId(int $sportId)
    {
        $leaguesData = $this->pinnacleService->getLeagues($sportId);

        // Extract the 'leagues' array from the response
        if (is_array($leaguesData) && isset($leaguesData['leagues'])) {
            return $leaguesData['leagues'];
        }

        \Log::warning('No leagues found for sport ID', ['sportId' => $sportId, 'response' => $leaguesData]);
        return [];
    }

    public function getBySportIdPaginated(int $sportId, int $page = 1, int $perPage = 10)
    {
        $allLeagues = $this->getBySportId($sportId);

        if (!is_array($allLeagues)) {
            return [
                'data' => [],
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
                'from' => null,
                'to' => null
            ];
        }

        $total = count($allLeagues);
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $paginatedData = array_slice($allLeagues, $offset, $perPage);

        return [
            'data' => $paginatedData,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null
        ];
    }
}
