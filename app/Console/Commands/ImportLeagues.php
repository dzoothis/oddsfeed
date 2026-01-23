<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportLeaguesJob;

class ImportLeagues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'leagues:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import leagues from Pinnacle API with 2000 league limit per sport';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting league import with 2000 league limit per sport...');
        $this->info('📋 This will import up to 2000 leagues for each sport from Pinnacle');
        
        // Dispatch the job
        ImportLeaguesJob::dispatch()->onQueue('import');
        
        $this->info('✅ League import job dispatched successfully.');
        $this->comment('💡 The import will run in the background. Check logs for progress.');
        $this->comment('📝 After leagues are imported, run match sync jobs to get matches:');
        $this->comment('   php artisan sync:all-data --sport=1');
        
        return Command::SUCCESS;
    }
}

