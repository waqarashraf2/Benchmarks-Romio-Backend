<?php

namespace App\Jobs;

use App\Services\PortalScraperService;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ImportPortalOrdersJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 1200;
    public $tries = 3;

    public function handle(PortalScraperService $scraper): void
    {
        Log::info('ROMIO: Starting portal import');

        $orders = $scraper->fetchAllPendingOrders();

        foreach ($orders as $dto) {

            Order::updateOrCreate(
                ['external_order_id' => $dto->externalOrderId],
                [
                    'order_number' => $dto->orderNumber,
                    'address' => $dto->address,
                    'priority' => $this->normalizePriority($dto->priority),
                    'instruction' => $dto->instruction,
                    'due_at' => $dto->dueAt,
                    'source' => $dto->source,
                    'status' => Order::STATUS_PENDING,
                ]
            );
        }

        Log::info('ROMIO: Import completed. Total: '.count($orders));
    }

    private function normalizePriority($priority): string
    {
        return match(strtolower($priority)) {
            'urgent' => Order::PRIORITY_URGENT,
            'high' => Order::PRIORITY_HIGH,
            default => Order::PRIORITY_REGULAR,
        };
    }
}
