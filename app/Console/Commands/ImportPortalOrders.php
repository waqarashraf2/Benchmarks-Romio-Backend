<?php
// app/Console/Commands/ImportPortalOrders.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ImportPortalOrdersJob;
use App\Services\PortalScraperService;
use App\Jobs\ProcessPortalOrderJob;

class ImportPortalOrders extends Command
{
    protected $signature = 'portal:import 
                            {--sync : Run without queue}
                            {--test : Test mode - no database changes}';
    
    protected $description = 'Import orders from Captur3D portal';

    public function handle(PortalScraperService $scraper)
    {
        $this->info('🚀 Portal Import Started');

        if ($this->option('test')) {
            $this->info('🧪 TEST MODE - No changes will be saved');
            $orders = $scraper->fetchAllPendingOrders();
            
            $this->table(
                ['External ID', 'Order #', 'Address', 'Priority'],
                collect($orders)->map(fn($dto) => [
                    $dto->externalOrderId,
                    $dto->orderNumber,
                    substr($dto->propertyAddress, 0, 30),
                    $dto->priority
                ])
            );
            
            $this->info("Total orders found: " . count($orders));
            return;
        }

        if ($this->option('sync')) {
            $this->info('⚡ Running synchronously...');
            $orders = $scraper->fetchAllPendingOrders();
            
            $bar = $this->output->createProgressBar(count($orders));
            $bar->start();

            foreach ($orders as $order) {
                dispatch_sync(new ProcessPortalOrderJob($order));
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("✅ Processed " . count($orders) . " orders");
        } else {
            $this->info('📦 Dispatching to queue...');
            ImportPortalOrdersJob::dispatch();
            $this->info('✅ Job dispatched! Run php artisan queue:work');
        }
    }
}