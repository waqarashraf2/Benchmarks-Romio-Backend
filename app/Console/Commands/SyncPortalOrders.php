<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportPortalOrdersJob;

class SyncPortalOrders extends Command
{
    protected $signature = 'romio:sync-orders';
    protected $description = 'Sync orders from Captur3D portal';

    public function handle()
    {
        ImportPortalOrdersJob::dispatch();
        $this->info('Romio order sync dispatched');
    }
}
