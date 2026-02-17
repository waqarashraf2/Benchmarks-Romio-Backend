<?php

namespace App\Jobs;

use App\Services\MetroImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MetroImportJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        app(MetroImportService::class)->run();
    }
}