<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Interfaces\SportInterface;
use App\Interfaces\LeagueInterface;
use App\Interfaces\MarketInterface;
use Illuminate\Http\Request;

class PinnacleController extends Controller
{
    private SportInterface $sportRepository;
    private LeagueInterface $leagueRepository;
    private MarketInterface $marketRepository;

    public function __construct(
        SportInterface $sportRepository,
        LeagueInterface $leagueRepository,
        MarketInterface $marketRepository
    ) {
        $this->sportRepository = $sportRepository;
        $this->leagueRepository = $leagueRepository;
        $this->marketRepository = $marketRepository;
    }

    public function getSports()
    {
        $sports = $this->sportRepository->all();

        if (!is_array($sports)) {
            return ApiResponse::error('Failed to fetch sports data', 500);
        }

        return ApiResponse::success($sports, 'Sports retrieved successfully');
    }

    public function getLeagues(Request $request)
    {
        $sportId = $request->query('sportId');
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 10);

        if (!$sportId) {
            return ApiResponse::error('Sport ID is required', 400);
        }

        $result = $this->leagueRepository->getBySportIdPaginated((int) $sportId, (int) $page, (int) $perPage);

        if (!is_array($result) || !isset($result['data'])) {
            return ApiResponse::error('Failed to fetch leagues data', 500);
        }

        return ApiResponse::success($result['data'], 'Leagues retrieved successfully', 200, [
            'pagination' => [
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'last_page' => $result['last_page'],
                'from' => $result['from'],
                'to' => $result['to']
            ]
        ]);
    }

    public function getMarkets(Request $request)
    {
        $sportId = $request->query('sportId');
        $type = $request->query('type', 'all');
        $leagueIds = $request->query('leagueIds') ? explode(',', $request->query('leagueIds')) : [];

        if (!$sportId) {
            return ApiResponse::error('Sport ID is required', 400);
        }

        $markets = $this->marketRepository->getBySportId((int) $sportId, $type, $leagueIds);

        if (!is_array($markets)) {
            return ApiResponse::error('Failed to fetch markets data', 500);
        }

        return ApiResponse::success($markets, 'Markets retrieved successfully');
    }
}