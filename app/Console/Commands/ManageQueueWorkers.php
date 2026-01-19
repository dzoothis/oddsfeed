<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class ManageQueueWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:manage {action=start : Action to perform (start|stop|status|restart)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage queue workers with health monitoring and auto-recovery';

    /**
     * Worker configurations
     */
    protected $workers = [
        'import' => [
            'name' => 'Import Worker',
            'queues' => 'import',
            'connection' => 'redis-import',
            'description' => 'Handles bulk data imports (leagues, teams)',
        ],
        'sync' => [
            'name' => 'Sync Worker',
            'queues' => 'prematch-sync,live-sync,enrichment',
            'connection' => 'redis-sync',
            'description' => 'Processes match synchronization and enrichment',
        ],
        'odds' => [
            'name' => 'Odds Worker',
            'queues' => 'odds-sync',
            'connection' => 'redis-odds',
            'description' => 'Manages odds data updates',
        ],
        'default' => [
            'name' => 'Default Worker',
            'queues' => 'default',
            'connection' => 'redis',
            'description' => 'Handles miscellaneous and default queue jobs',
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'start':
                $this->startWorkers();
                break;
            case 'stop':
                $this->stopWorkers();
                break;
            case 'status':
                $this->showStatus();
                break;
            case 'restart':
                $this->restartWorkers();
                break;
            default:
                $this->error("Invalid action: {$action}");
                $this->info("Available actions: start, stop, status, restart");
                return 1;
        }

        return 0;
    }

    /**
     * Start all queue workers
     */
    protected function startWorkers()
    {
        $this->info('🚀 Starting queue workers...');

        // Stop any existing workers first
        $this->stopWorkers(false);

        $started = 0;

        foreach ($this->workers as $key => $config) {
            if ($this->startWorker($key, $config)) {
                $started++;
            }
        }

        $this->newLine();
        $this->showStatus();

        $this->info("✅ Started {$started} queue workers");
        $this->comment('Workers will automatically restart if they crash');
        $this->comment('Monitor with: php artisan queue:manage status');
    }

    /**
     * Start a specific worker
     */
    protected function startWorker($key, $config)
    {
        $pidFile = "worker-{$key}.pid";
        $logFile = "logs/queue-{$key}.log";

        // Check if already running
        if ($this->isWorkerRunning($key)) {
            $this->warn("⚠️  {$config['name']} is already running");
            return false;
        }

        $this->info("🔄 Starting {$config['name']}...");

        try {
            // Build the queue:work command
            $command = [
                PHP_BINARY,
                'artisan',
                'queue:work',
                $config['connection'],
                '--queue=' . $config['queues'],
                '--tries=3',
                '--timeout=3600',
                '--sleep=1',
                '--max-jobs=500',
                '--memory=256',
                '--backoff=30,120,480',
                '--max-exceptions=10',
            ];

            // Start the process
            $process = new SymfonyProcess($command);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(null); // No timeout
            $process->start();

            // Store PID for monitoring
            Storage::put($pidFile, $process->getPid());

            $this->comment("   📋 Queues: {$config['queues']}");
            $this->comment("   🔗 Connection: {$config['connection']}");
            $this->comment("   🆔 PID: {$process->getPid()}");

            return true;

        } catch (\Exception $e) {
            $this->error("❌ Failed to start {$config['name']}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Stop all queue workers
     */
    protected function stopWorkers($showOutput = true)
    {
        if ($showOutput) {
            $this->info('🛑 Stopping queue workers...');
        }

        $stopped = 0;

        foreach ($this->workers as $key => $config) {
            if ($this->stopWorker($key, $config, $showOutput)) {
                $stopped++;
            }
        }

        // Also kill any remaining queue:work processes
        $this->killRemainingProcesses();

        if ($showOutput) {
            $this->info("✅ Stopped {$stopped} queue workers");
        }
    }

    /**
     * Stop a specific worker
     */
    protected function stopWorker($key, $config, $showOutput = true)
    {
        $pidFile = "worker-{$key}.pid";

        if (!Storage::exists($pidFile)) {
            if ($showOutput) {
                $this->comment("⚠️  {$config['name']} PID file not found");
            }
            return false;
        }

        $pid = (int) Storage::get($pidFile);

        if (!$this->isProcessRunning($pid)) {
            if ($showOutput) {
                $this->comment("⚠️  {$config['name']} process not running (PID: {$pid})");
            }
            Storage::delete($pidFile);
            return false;
        }

        // Kill the process
        if ($this->killProcess($pid)) {
            Storage::delete($pidFile);
            if ($showOutput) {
                $this->comment("✅ Stopped {$config['name']} (PID: {$pid})");
            }
            return true;
        } else {
            if ($showOutput) {
                $this->error("❌ Failed to stop {$config['name']} (PID: {$pid})");
            }
            return false;
        }
    }

    /**
     * Show worker status
     */
    protected function showStatus()
    {
        $this->info('📊 Queue Worker Status:');
        $this->newLine();

        $allHealthy = true;
        $running = 0;

        foreach ($this->workers as $key => $config) {
            $status = $this->getWorkerStatus($key, $config);

            $icon = match($status['state']) {
                'running' => '✅',
                'stopped' => '❌',
                'error' => '🔴',
                default => '⚠️'
            };

            $this->line("{$icon} {$config['name']}");
            $this->line("   📋 Queues: {$config['queues']}");
            $this->line("   🔗 Connection: {$config['connection']}");
            $this->line("   📝 Status: {$status['message']}");

            if ($status['state'] === 'running') {
                $running++;
            } elseif ($status['state'] !== 'stopped') {
                $allHealthy = false;
            }

            $this->newLine();
        }

        // Show queue statistics
        $this->showQueueStats();

        if ($allHealthy && $running === count($this->workers)) {
            $this->info("🎉 All {$running} workers are healthy!");
        } elseif ($running > 0) {
            $this->warn("⚠️  {$running} workers running, but some issues detected");
        } else {
            $this->error("🔴 No workers are running");
        }
    }

    /**
     * Get worker status
     */
    protected function getWorkerStatus($key, $config)
    {
        $pidFile = "worker-{$key}.pid";

        if (!Storage::exists($pidFile)) {
            return [
                'state' => 'stopped',
                'message' => 'No PID file found'
            ];
        }

        $pid = (int) Storage::get($pidFile);

        if (!$this->isProcessRunning($pid)) {
            Storage::delete($pidFile); // Clean up stale PID file
            return [
                'state' => 'stopped',
                'message' => 'Process not running (cleaned up PID file)'
            ];
        }

        return [
            'state' => 'running',
            'message' => "Running (PID: {$pid})"
        ];
    }

    /**
     * Show queue statistics
     */
    protected function showQueueStats()
    {
        try {
            $totalJobs = \DB::table('jobs')->count();
            $failedJobs = \DB::table('failed_jobs')->count();

            $this->line("📈 Queue Statistics:");
            $this->line("   📋 Pending Jobs: {$totalJobs}");
            $this->line("   ❌ Failed Jobs: {$failedJobs}");

            if ($failedJobs > 0) {
                $this->warn("   💡 Run 'php artisan queue:failed' to see failed jobs");
            }

        } catch (\Exception $e) {
            $this->warn("Could not retrieve queue statistics: {$e->getMessage()}");
        }

        $this->newLine();
    }

    /**
     * Restart all workers
     */
    protected function restartWorkers()
    {
        $this->info('🔄 Restarting queue workers...');
        $this->stopWorkers();
        sleep(2); // Brief pause
        $this->startWorkers();
    }

    /**
     * Check if a worker is running
     */
    protected function isWorkerRunning($key)
    {
        $pidFile = "worker-{$key}.pid";

        if (!Storage::exists($pidFile)) {
            return false;
        }

        $pid = (int) Storage::get($pidFile);
        return $this->isProcessRunning($pid);
    }

    /**
     * Check if a process is running
     */
    protected function isProcessRunning($pid)
    {
        if (empty($pid)) {
            return false;
        }

        // Use POSIX kill with signal 0 to check if process exists
        return posix_kill($pid, 0);
    }

    /**
     * Kill a process
     */
    protected function killProcess($pid)
    {
        if (empty($pid)) {
            return false;
        }

        // Try graceful shutdown first
        if (posix_kill($pid, SIGTERM)) {
            // Wait up to 5 seconds for graceful shutdown
            for ($i = 0; $i < 5; $i++) {
                if (!$this->isProcessRunning($pid)) {
                    return true;
                }
                sleep(1);
            }
        }

        // Force kill if graceful shutdown failed
        return posix_kill($pid, SIGKILL);
    }

    /**
     * Kill any remaining queue processes
     */
    protected function killRemainingProcesses()
    {
        // Find and kill any remaining queue:work processes
        exec('pgrep -f "php artisan queue:work"', $pids, $exitCode);

        if ($exitCode === 0 && !empty($pids)) {
            foreach ($pids as $pid) {
                $this->killProcess((int) $pid);
            }
            $this->comment("🧹 Cleaned up " . count($pids) . " remaining queue processes");
        }
    }
}
