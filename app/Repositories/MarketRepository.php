<?php

namespace App\Repositories;

use App\Interfaces\MarketInterface;
use App\Interfaces\SportInterface;
use App\Services\PinnacleService;

class MarketRepository implements MarketInterface
{
    private PinnacleService $pinnacleService;
    private SportInterface $sportRepository;

    public function __construct(PinnacleService $pinnacleService, SportInterface $sportRepository)
    {
        $this->pinnacleService = $pinnacleService;
        $this->sportRepository = $sportRepository;
    }

    public function getBySportId(int $sportId, string $type = 'all', array $leagueIds = [])
    {
        // Map internal sportId to Pinnacle p_id
        $sport = $this->sportRepository->findById($sportId);
        
        if (!$sport || !isset($sport['p_id'])) {
            \Log::warning('Sport not found or p_id missing for mapping in MarketRepository', ['sportId' => $sportId]);
            return [];
        }

        $pinnacleSportId = $sport['p_id'];
        $data = [];

        if ($type === 'live' || $type === 'all') {
            $live = $this->pinnacleService->getMarkets($pinnacleSportId, true, $leagueIds);
            if (is_array($live)) {
                foreach ($live as &$match) {
                    $match['eventType'] = 'live';
                }
                $data = array_merge($data, $live);
            }
        }

        if ($type === 'prematch' || $type === 'all') {
            $prematch = $this->pinnacleService->getMarkets($pinnacleSportId, false, $leagueIds);
            if (is_array($prematch)) {
                foreach ($prematch as &$match) {
                    $match['eventType'] = 'prematch';
                }
                $data = array_merge($data, $prematch);
            }
        }

        return $data;
    }
}
