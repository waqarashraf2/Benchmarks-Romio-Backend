<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\MetroImportJob;

class MetroImport extends Command
{


protected $signature = 'app:metro-import';

/**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

public function handle()
{
    app(\App\Services\MetroImportService::class)->run();
    MetroImportJob::dispatch();
    $this->info('Metro import job dispatched successfully.');
}
}
