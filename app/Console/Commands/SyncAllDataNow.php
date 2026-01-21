<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\LiveMatchSyncJob;
use App\Jobs\PrematchSyncJob;
use App\Jobs\OddsSyncJob;
use App\Jobs\MatchStatusManager;

class SyncAllDataNow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:all-data {--sport=1 : Sport ID to sync} {--leagues= : Comma-separated league IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all match data - live, prematch, odds, and manage finished matches';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sportId = (int) $this->option('sport');
        $leagueIds = $this->option('leagues') ? explode(',', $this->option('leagues')) : [];

        $this->info("🚀 Starting comprehensive data sync for sport ID: {$sportId}");

        if (!empty($leagueIds)) {
            $this->info("📋 Target leagues: " . implode(', ', $leagueIds));
        } else {
            $this->info("🌍 Syncing all leagues for sport");
        }

        // 1. Sync Live Matches
        $this->info("⚽ Dispatching LiveMatchSyncJob...");
        LiveMatchSyncJob::dispatch($sportId, $leagueIds)->onQueue('live-sync');
        $this->line("   ✅ LiveMatchSyncJob dispatched");

        // 2. Sync Prematch Data
        $this->info("📅 Dispatching PrematchSyncJob...");
        PrematchSyncJob::dispatch($sportId, $leagueIds)->onQueue('prematch-sync');
        $this->line("   ✅ PrematchSyncJob dispatched");

        // 3. Sync Odds Data
        $this->info("💰 Dispatching OddsSyncJob...");
        OddsSyncJob::dispatch($leagueIds, false)->onQueue('odds-sync');
        $this->line("   ✅ OddsSyncJob dispatched");

        // 4. Run Match Status Manager (includes soft_finished logic)
        $this->info("🔄 Dispatching MatchStatusManager...");
        MatchStatusManager::dispatch('comprehensive_check', $sportId, false)->onQueue('default');
        $this->line("   ✅ MatchStatusManager dispatched");

        $this->newLine();
        $this->info("🎉 All sync jobs dispatched successfully!");
        $this->info("💡 Jobs are running in background queues. Check queue status with:");
        $this->line("   php artisan queue:work --queue=live-sync,prematch-sync,odds-sync,match-management");

        return 0;
    }
}
