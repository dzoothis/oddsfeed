<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class QueueHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:health-check {--restart : Automatically restart failed workers} {--fix : Attempt to fix critical issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Comprehensive system health check - queues, data freshness, and critical data availability';

    /**
     * Worker configurations to monitor
     */
    protected $workers = [
        'import' => [
            'name' => 'Import Worker',
            'queues' => ['import'],
        ],
        'sync' => [
            'name' => 'Sync Worker',
            'queues' => ['prematch-sync', 'live-sync', 'enrichment'],
        ],
        'odds' => [
            'name' => 'Odds Worker',
            'queues' => ['odds-sync'],
        ],
        'default' => [
            'name' => 'Default Worker',
            'queues' => ['default'],
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Performing comprehensive system health check...');

        $issues = [];
        $criticalIssues = [];
        $restart = $this->option('restart');
        $fix = $this->option('fix');
        $restarted = 0;
        $fixed = 0;

        // 1. Check queue workers
        $this->newLine();
        $this->info('🏭 Checking queue workers...');
        foreach ($this->workers as $key => $config) {
            $status = $this->checkWorkerHealth($key, $config);

            if ($status['healthy']) {
                $this->comment("✅ {$config['name']} - Healthy");
            } else {
                $issues[] = $config['name'];
                $severity = $status['critical'] ?? false;
                if ($severity) {
                    $criticalIssues[] = $config['name'];
                }

                $this->error("❌ {$config['name']} - {$status['message']}");

                if ($restart || $fix) {
                    if ($this->restartWorker($key, $config)) {
                        $restarted++;
                        $this->info("🔄 Restarted {$config['name']}");
                    } else {
                        $this->error("❌ Failed to restart {$config['name']}");
                    }
                }
            }
        }

        // 2. Check queue backlog
        $this->checkQueueBacklog();

        // 3. Check data freshness
        $this->newLine();
        $this->info('📅 Checking data freshness...');
        $dataIssues = $this->checkDataFreshness();
        $issues = array_merge($issues, $dataIssues['issues']);
        $criticalIssues = array_merge($criticalIssues, $dataIssues['critical']);

        // 4. Check stalled jobs
        $this->newLine();
        $this->info('⏰ Checking for stalled jobs...');
        $stalledIssues = $this->checkStalledJobs();
        $issues = array_merge($issues, $stalledIssues['issues']);
        $criticalIssues = array_merge($criticalIssues, $stalledIssues['critical']);

        // 5. Check critical data availability
        $this->newLine();
        $this->info('📊 Checking critical data availability...');
        $dataAvailability = $this->checkCriticalDataAvailability();
        $issues = array_merge($issues, $dataAvailability['issues']);
        $criticalIssues = array_merge($criticalIssues, $dataAvailability['critical']);

        // Attempt fixes if requested
        if ($fix && !empty($criticalIssues)) {
            $this->newLine();
            $this->info('🔧 Attempting to fix critical issues...');
            $fixed += $this->attemptCriticalFixes();
        }

        // Summary
        $this->newLine();
        $this->newLine();
        $this->info('📋 Health Check Summary:');

        if (empty($issues) && empty($criticalIssues)) {
            $this->info('🎉 System is healthy!');
            return 0;
        }

        if (!empty($criticalIssues)) {
            $this->error("🚨 CRITICAL ISSUES FOUND: " . count($criticalIssues));
            foreach ($criticalIssues as $issue) {
                $this->error("   • {$issue}");
            }
        }

        if (!empty($issues)) {
            $this->warn("⚠️  NON-CRITICAL ISSUES: " . count($issues));
            foreach ($issues as $issue) {
                $this->warn("   • {$issue}");
            }
        }

        if ($restarted > 0) {
            $this->info("✅ Restarted {$restarted} workers");
        }

        if ($fixed > 0) {
            $this->info("🔧 Fixed {$fixed} critical issues");
        }

        if (!$restart && !$fix) {
            $this->comment('💡 Run with --restart to auto-restart workers');
            $this->comment('💡 Run with --fix to attempt critical fixes');
        }

        return (!empty($criticalIssues)) ? 2 : 1;
    }

    /**
     * Check health of a specific worker
     */
    protected function checkWorkerHealth($key, $config)
    {
        $pidFile = "worker-{$key}.pid";

        // Check if PID file exists
        if (!Storage::exists($pidFile)) {
            return [
                'healthy' => false,
                'message' => 'No PID file found'
            ];
        }

        $pid = (int) Storage::get($pidFile);

        // Check if process is running
        if (!$this->isProcessRunning($pid)) {
            // Clean up stale PID file
            Storage::delete($pidFile);
            return [
                'healthy' => false,
                'message' => 'Process not running (cleaned up stale PID)'
            ];
        }

        // Check if process is actually a queue worker
        if (!$this->isQueueWorker($pid)) {
            return [
                'healthy' => false,
                'message' => 'Process exists but is not a queue worker'
            ];
        }

        // Check memory usage
        $memoryUsage = $this->getProcessMemoryUsage($pid);
        if ($memoryUsage > 512 * 1024 * 1024) { // 512MB
            return [
                'healthy' => false,
                'message' => 'High memory usage (' . round($memoryUsage / 1024 / 1024) . 'MB)'
            ];
        }

        return [
            'healthy' => true,
            'message' => 'Running normally'
        ];
    }

    /**
     * Check queue backlog
     */
    protected function checkQueueBacklog()
    {
        try {
            $totalJobs = \DB::table('jobs')->count();
            $failedJobs = \DB::table('failed_jobs')->count();

            $this->newLine();
            $this->info('📊 Queue Statistics:');
            $this->line("   📋 Pending Jobs: {$totalJobs}");
            $this->line("   ❌ Failed Jobs: {$failedJobs}");

            // Alert on high backlog
            if ($totalJobs > 1000) {
                $this->error("   🚨 High job backlog detected!");
            } elseif ($totalJobs > 500) {
                $this->warn("   ⚠️  Moderate job backlog");
            }

            if ($failedJobs > 10) {
                $this->error("   🚨 High failed job count!");
                $this->comment("   💡 Run 'php artisan queue:failed' to investigate");
            }

        } catch (\Exception $e) {
            $this->warn("Could not check queue statistics: {$e->getMessage()}");
        }
    }

    /**
     * Restart a failed worker
     */
    protected function restartWorker($key, $config)
    {
        try {
            // Use the manage command to restart this specific worker
            $exitCode = $this->call('queue:manage', [
                'action' => 'start'
            ]);

            return $exitCode === 0;
        } catch (\Exception $e) {
            Log::error("Failed to restart worker {$key}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Check if a process is running
     */
    protected function isProcessRunning($pid)
    {
        if (empty($pid)) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * Check if a process is a queue worker
     */
    protected function isQueueWorker($pid)
    {
        $cmdline = "/proc/{$pid}/cmdline";

        if (!file_exists($cmdline)) {
            return false;
        }

        $command = file_get_contents($cmdline);
        return str_contains($command, 'queue:work');
    }

    /**
     * Get process memory usage in bytes
     */
    protected function getProcessMemoryUsage($pid)
    {
        $statusFile = "/proc/{$pid}/status";

        if (!file_exists($statusFile)) {
            return 0;
        }

        $status = file_get_contents($statusFile);
        preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches);

        return isset($matches[1]) ? (int) $matches[1] * 1024 : 0;
    }

    /**
     * Check data freshness across critical data sources
     */
    protected function checkDataFreshness()
    {
        $issues = [];
        $critical = [];

        try {
            // Check sports data freshness
            $oldestSport = \DB::table('sports')->orderBy('updated_at')->first();
            if ($oldestSport && now()->diffInHours($oldestSport->updated_at) > 24) {
                $critical[] = "Sports data is stale (last updated: {$oldestSport->updated_at})";
                Log::critical('CRITICAL: Sports data is stale', [
                    'last_updated' => $oldestSport->updated_at,
                    'hours_old' => now()->diffInHours($oldestSport->updated_at)
                ]);
            }

            // Check match data freshness
            $recentMatches = \DB::table('sports_matches')
                ->where('lastUpdated', '>', now()->subHours(2))
                ->count();

            if ($recentMatches < 10) {
                $issues[] = "Low recent match activity (< 10 matches in last 2 hours)";
                Log::warning('Low match activity detected', ['recent_matches' => $recentMatches]);
            }

            // Check if any sports have no matches at all
            $sportsWithoutMatches = \DB::table('sports')
                ->leftJoin('sports_matches', 'sports.id', '=', 'sports_matches.sportId')
                ->whereNull('sports_matches.id')
                ->pluck('sports.name');

            if ($sportsWithoutMatches->isNotEmpty()) {
                $critical[] = "Sports with no matches: " . $sportsWithoutMatches->join(', ');
                Log::critical('CRITICAL: Sports without any matches', [
                    'sports' => $sportsWithoutMatches->toArray()
                ]);
            }

        } catch (\Exception $e) {
            $issues[] = "Could not check data freshness: {$e->getMessage()}";
            Log::error('Health check failed - data freshness', ['error' => $e->getMessage()]);
        }

        return ['issues' => $issues, 'critical' => $critical];
    }

    /**
     * Check for stalled jobs (jobs that have been processing too long)
     */
    protected function checkStalledJobs()
    {
        $issues = [];
        $critical = [];

        try {
            // Check for jobs that have been reserved for too long
            $stalledJobs = \DB::table('jobs')
                ->where('reserved_at', '<', now()->subMinutes(30))
                ->get();

            if ($stalledJobs->isNotEmpty()) {
                $critical[] = "Found {$stalledJobs->count()} stalled jobs (processing > 30 minutes)";
                Log::critical('CRITICAL: Stalled jobs detected', [
                    'count' => $stalledJobs->count(),
                    'oldest' => $stalledJobs->min('reserved_at')
                ]);

                // Log details of stalled jobs
                foreach ($stalledJobs as $job) {
                    Log::critical('Stalled job details', [
                        'job_id' => $job->id,
                        'queue' => $job->queue,
                        'reserved_at' => $job->reserved_at,
                        'attempts' => $job->attempts
                    ]);
                }
            }

        } catch (\Exception $e) {
            $issues[] = "Could not check stalled jobs: {$e->getMessage()}";
            Log::error('Health check failed - stalled jobs', ['error' => $e->getMessage()]);
        }

        return ['issues' => $issues, 'critical' => $critical];
    }

    /**
     * Check availability of critical data
     */
    protected function checkCriticalDataAvailability()
    {
        $issues = [];
        $critical = [];

        try {
            // Check if we have any sports at all
            $sportCount = \DB::table('sports')->count();
            if ($sportCount === 0) {
                $critical[] = "No sports data available";
                Log::critical('CRITICAL: No sports data in database');
            }

            // Check if we have leagues
            $leagueCount = \DB::table('leagues')->count();
            if ($leagueCount === 0) {
                $critical[] = "No leagues data available";
                Log::critical('CRITICAL: No leagues data in database');
            }

            // Check if we have recent matches
            $recentMatchCount = \DB::table('sports_matches')
                ->where('created_at', '>', now()->subHours(24))
                ->count();

            if ($recentMatchCount === 0) {
                $issues[] = "No matches created in the last 24 hours";
                Log::warning('No recent matches created');
            }

            // Check Redis connectivity
            try {
                $redis = \Illuminate\Support\Facades\Redis::connection();
                $redis->ping();
            } catch (\Exception $e) {
                $critical[] = "Redis connectivity failed: {$e->getMessage()}";
                Log::critical('CRITICAL: Redis connectivity failed', ['error' => $e->getMessage()]);
            }

        } catch (\Exception $e) {
            $issues[] = "Could not check critical data: {$e->getMessage()}";
            Log::error('Health check failed - critical data', ['error' => $e->getMessage()]);
        }

        return ['issues' => $issues, 'critical' => $critical];
    }

    /**
     * Attempt to fix critical issues
     */
    protected function attemptCriticalFixes()
    {
        $fixed = 0;

        try {
            // Fix stalled jobs by clearing them
            $stalledCount = \DB::table('jobs')
                ->where('reserved_at', '<', now()->subMinutes(30))
                ->delete();

            if ($stalledCount > 0) {
                $this->info("🧹 Cleared {$stalledCount} stalled jobs");
                Log::info('Health check: Cleared stalled jobs', ['count' => $stalledCount]);
                $fixed++;
            }

            // Trigger emergency data sync if no recent data
            $recentMatches = \DB::table('sports_matches')
                ->where('lastUpdated', '>', now()->subHours(1))
                ->count();

            if ($recentMatches === 0) {
                $this->info("🚨 Triggering emergency data sync");
                // Dispatch a PrematchSyncJob for the most popular sport
                $popularSport = \DB::table('sports')->orderBy('id')->first();
                if ($popularSport) {
                    \App\Jobs\PrematchSyncJob::dispatch($popularSport->pinnacleId)
                        ->onQueue('prematch-sync');
                    $this->info("📡 Dispatched emergency sync for sport: {$popularSport->name}");
                    Log::info('Health check: Triggered emergency data sync', [
                        'sport_id' => $popularSport->pinnacleId
                    ]);
                    $fixed++;
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Failed to apply critical fixes: {$e->getMessage()}");
            Log::error('Health check: Critical fixes failed', ['error' => $e->getMessage()]);
        }

        return $fixed;
    }
}
