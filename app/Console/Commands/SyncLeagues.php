<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncLeaguesJob;

class SyncLeagues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leagues:sync {--force : Force full sync of all leagues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync leagues from Pinnacle API to local database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');

        $this->info($force ? 'Starting forced full league sync...' : 'Starting league sync...');

        // Dispatch the job
        SyncLeaguesJob::dispatch($force);

        $this->info('League sync job dispatched successfully.');
        $this->comment('The sync will run in the background. Check logs for progress.');

        return Command::SUCCESS;
    }
}
