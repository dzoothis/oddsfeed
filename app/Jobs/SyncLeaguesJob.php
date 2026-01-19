<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\PinnacleService;
use App\Models\League;
use App\Models\Sport;

class SyncLeaguesJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $timeout = 300; // 5 minutes for full sync

    protected $forceFullSync;

    /**
     * Create a new job instance.
     */
    public function __construct($forceFullSync = false)
    {
        $this->forceFullSync = $forceFullSync;
    }

    /**
     * Execute the job - Periodic league sync from Pinnacle API
     */
    public function handle(PinnacleService $pinnacleService): void
    {
        Log::info('SyncLeaguesJob started', [
            'force_full_sync' => $this->forceFullSync
        ]);

        try {
            $sports = Sport::where('isActive', true)->get();
            $totalUpdated = 0;
            $totalCreated = 0;

            foreach ($sports as $sport) {
                Log::info('Syncing leagues for sport', [
                    'sport_id' => $sport->id,
                    'sport_name' => $sport->name,
                    'pinnacle_id' => $sport->pinnacleId
                ]);

                try {
                    // Get leagues from API
                    $leaguesData = $pinnacleService->getLeagues($sport->pinnacleId);

                    if (!isset($leaguesData['leagues']) || !is_array($leaguesData['leagues'])) {
                        Log::warning('Invalid leagues data from API', [
                            'sport_id' => $sport->pinnacleId,
                            'response' => $leaguesData
                        ]);
                        continue;
                    }

                    $apiLeagues = $leaguesData['leagues'];
                    $processedCount = 0;

                    foreach ($apiLeagues as $leagueData) {
                        if (!isset($leagueData['id']) || !isset($leagueData['name'])) {
                            continue;
                        }

                        // Update or create league
                        $league = League::updateOrCreate(
                            ['pinnacleId' => $leagueData['id']],
                            [
                                'sportId' => $sport->id,
                                'name' => $leagueData['name'],
                                'isActive' => true,
                                'lastPinnacleSync' => now()
                            ]
                        );

                        if ($league->wasRecentlyCreated) {
                            $totalCreated++;
                        } else {
                            $totalUpdated++;
                        }

                        $processedCount++;

                        // Batch processing to avoid memory issues
                        if ($processedCount % 100 === 0) {
                            Log::debug('Processed batch of leagues', [
                                'sport' => $sport->name,
                                'processed' => $processedCount,
                                'total_in_batch' => count($apiLeagues)
                            ]);
                        }
                    }

                    Log::info('Completed league sync for sport', [
                        'sport' => $sport->name,
                        'api_leagues_count' => count($apiLeagues),
                        'processed_count' => $processedCount
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to sync leagues for sport', [
                        'sport_id' => $sport->id,
                        'sport_name' => $sport->name,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other sports
                }
            }

            // Clear league search cache after sync
            Cache::flush(); // Or use more specific cache tags if implemented

            Log::info('SyncLeaguesJob completed successfully', [
                'sports_processed' => $sports->count(),
                'leagues_created' => $totalCreated,
                'leagues_updated' => $totalUpdated,
                'total_leagues_in_db' => League::count(),
                'cache_cleared' => true
            ]);

        } catch (\Exception $e) {
            Log::error('SyncLeaguesJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('SyncLeaguesJob failed permanently', [
            'force_full_sync' => $this->forceFullSync,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
