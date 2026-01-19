<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Models\League;
use App\Models\BetType;
use App\Services\PinnacleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ReferenceController extends Controller
{
    protected $pinnacleService;

    public function __construct(PinnacleService $pinnacleService)
    {
        $this->pinnacleService = $pinnacleService;
    }

    /**
     * Get bet types for a specific sport
     * Mirrors the Pinnacle project's /api/reference/bet-types endpoint
     */
    public function getBetTypes(Request $request)
    {
        try {
            $sportId = $request->query('sportId');

            if (!$sportId) {
                return response()->json([
                    'error' => 'sportId query parameter is required'
                ], 400);
            }

            $sportIdInt = (int) $sportId;

            // Find sport by pinnacleId (matches Pinnacle project logic)
            $sport = Sport::where('pinnacleId', $sportIdInt)
                ->where('isActive', true)
                ->first();

            if (!$sport) {
                return response()->json([
                    'error' => 'Sport not found'
                ], 404);
            }

            // Get bet types for this sport
            $betTypes = BetType::where('sportId', $sport->id)
                ->where('isActive', true)
                ->orderBy('category')
                ->orderBy('name')
                ->select('id', 'category', 'name', 'description')
                ->get();

            // Group by category (matches Pinnacle project response format)
            $grouped = $betTypes->groupBy('category')->map(function ($categoryBetTypes) {
                return $categoryBetTypes->map(function ($betType) {
                    return [
                        'id' => $betType->id,
                        'name' => $betType->name,
                        'description' => $betType->description,
                    ];
                });
            });

            return response()->json([
                'sportId' => $sportIdInt,
                'categories' => $grouped->toArray(),
                'flat' => $betTypes->toArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching bet types', [
                'error' => $e->getMessage(),
                'sportId' => $request->query('sportId'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all sports (optional helper endpoint)
     */
    public function getSports()
    {
        try {
            $sports = Sport::where('isActive', true)
                ->select('id', 'pinnacleId', 'name')
                ->get();

            return response()->json([
                'sports' => $sports
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sports', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Failed to fetch sports'
            ], 500);
        }
    }

    /**
     * Search leagues for a sport (optimized with database + caching)
     */
    public function searchLeagues(Request $request)
    {
        try {
            $sportId = $request->query('sportId');
            $search = $request->query('search', '');
            $limit = min($request->query('limit', 50), 100);

            if (!$sportId) {
                return response()->json(['error' => 'sportId is required'], 400);
            }

            $sportIdInt = (int) $sportId;

            // Find sport (now indexed)
            $sport = Sport::where('pinnacleId', $sportIdInt)->first();
            if (!$sport) {
                return response()->json(['error' => 'Sport not found'], 404);
            }

            // Create cache key
            $cacheKey = "league_search:{$sportIdInt}:" . md5($search) . ":{$limit}";
            $cacheTtl = empty($search) ? 3600 : 1800; // 1 hour for popular, 30 min for search

            // Try cache first, but always validate against database
            $cachedResult = Cache::get($cacheKey);
            $cacheAge = null;

            if ($cachedResult) {
                // Check if cache is recent enough (within last 30 minutes)
                if (isset($cachedResult['cached_at'])) {
                    $cacheAge = now()->diffInMinutes($cachedResult['cached_at']);
                    if ($cacheAge < 30) {
                        \Log::debug('League search cache hit (fresh)', [
                            'key' => $cacheKey,
                            'age_minutes' => $cacheAge
                        ]);
                        return response()->json($cachedResult);
                    }
                }

                \Log::debug('League search cache hit (stale)', [
                    'key' => $cacheKey,
                    'age_minutes' => $cacheAge
                ]);
            }

            // Database search with FULLTEXT optimization (always authoritative)
            $query = League::where('sportId', $sport->id)
                          ->where('isActive', true);

            if (!empty($search)) {
                // Use FULLTEXT search for better performance
                $query->whereRaw("MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE)", [$search])
                      ->orderByRaw("MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE) DESC", [$search]);
            } else {
                // For popular leagues, order by name
                $query->orderBy('name');
            }

            $leagues = $query->limit($limit)
                            ->select('id', 'name', 'pinnacleId')
                            ->get()
                            ->map(function ($league) use ($search) {
                                return [
                                    'id' => $league->pinnacleId, // Return pinnacleId as id for API compatibility
                                    'pinnacleId' => $league->pinnacleId,
                                    'name' => $league->name,
                                    'container' => null, // Not stored in DB
                                    'searchScore' => $this->calculateSearchScore($league->name, $search)
                                ];
                            });

            // Fallback to API if no results in database (for newly added leagues)
            if ($leagues->isEmpty() && !empty($search)) {
                \Log::info('No leagues found in DB, falling back to API', [
                    'sportId' => $sportIdInt,
                    'search' => $search
                ]);

                $leaguesData = $this->pinnacleService->getLeagues($sportIdInt);

                if (isset($leaguesData['leagues'])) {
                    $leagues = collect($leaguesData['leagues'])
                        ->filter(function ($league) use ($search) {
                            return stripos($league['name'], $search) !== false;
                        })
                        ->take($limit)
                        ->map(function ($league) use ($search) {
                            return [
                                'id' => $league['id'],
                                'pinnacleId' => $league['id'],
                                'name' => $league['name'],
                                'container' => $league['container'] ?? null,
                                'searchScore' => $this->calculateSearchScore($league['name'], $search)
                            ];
                        })
                        ->sortByDesc('searchScore')
                        ->values();
                }
            }

            $result = [
                'leagues' => $leagues,
                'total' => $leagues->count(),
                'search' => $search,
                'source' => $leagues->isNotEmpty() && $leagues->first()['container'] === null ? 'database' : 'api',
                'cached_at' => now(),
                'cache_ttl' => $cacheTtl
            ];

            // Cache the result
            Cache::put($cacheKey, $result, $cacheTtl);

            return response()->json($result);

        } catch (\Exception $e) {
            \Log::error('Error searching leagues', [
                'error' => $e->getMessage(),
                'sportId' => $request->query('sportId'),
                'search' => $request->query('search'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to search leagues',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate search relevance score
     */
    private function calculateSearchScore($leagueName, $search)
    {
        if (empty($search)) return 0;

        $name = strtolower($leagueName);
        $query = strtolower($search);

        // Exact match gets highest score
        if ($name === $query) return 100;

        // Starts with query gets high score
        if (str_starts_with($name, $query)) return 80;

        // Contains query as whole word gets medium score
        if (preg_match('/\b' . preg_quote($query, '/') . '\b/', $name)) return 60;

        // Contains query anywhere gets lower score
        if (str_contains($name, $query)) return 40;

        // Word starts with query gets low score
        $nameWords = explode(' ', $name);
        foreach ($nameWords as $word) {
            if (str_starts_with($word, $query)) return 20;
        }

        return 0;
    }
}
