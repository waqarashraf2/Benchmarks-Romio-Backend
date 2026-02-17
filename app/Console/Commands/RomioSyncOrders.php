<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\DTOs\PortalOrderDTO;

class RomioSyncOrders extends Command
{
    protected $signature = 'romio:sync-orders';
    protected $description = 'Sync orders from portal and display order data';

    public function handle()
    {
        // Clear screen for better visibility (optional)
        // system('clear'); // for Linux/Mac
        // system('cls'); // for Windows
        
        $this->info('========================================');
        $this->info('📦 PORTAL ORDERS SYNC - ' . now()->format('Y-m-d H:i:s'));
        $this->info('========================================');
        
        try {
            // Display orders based on your DTO structure
            $this->displayPortalOrders();
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->displaySampleOrders();
        }
        
        $this->info('========================================');
        $this->newLine();
        
        return Command::SUCCESS;
    }
    
    private function displayPortalOrders()
    {
        // TODO: Replace with actual API call to your portal
        // For now, using sample data that matches your DTO structure
        
        $sampleRows = $this->getSampleOrdersData();
        
        // Convert to DTOs
        $orders = [];
        foreach ($sampleRows as $row) {
            try {
                $dto = PortalOrderDTO::fromArray($row);
                $orders[] = $dto;
            } catch (\Exception $e) {
                $this->warn('Failed to parse order: ' . $e->getMessage());
            }
        }
        
        if (empty($orders)) {
            $this->warn('No orders found.');
            return;
        }
        
        // Display orders in table
        $this->displayOrdersTable($orders);
        
        // Show summary
        $this->displayOrderSummary($orders);
    }
    
    private function getSampleOrdersData(): array
    {
        return [
            [
                'Order ID' => 'ORD-2026-001',
                'Priority' => 'High',
                'Address' => '123 Main St, Sydney NSW 2000',
                'Due In' => '2 hours',
                'Order Date' => 'Sun 15 Feb 26 (10:30 am)',
                'Elapsed time since order' => '30 mins'
            ],
            [
                'Order ID' => 'ORD-2026-002',
                'Priority' => 'Medium',
                'Address' => '456 George St, Sydney NSW 2000',
                'Due In' => 'tomorrow',
                'Order Date' => 'Sun 15 Feb 26 (11:45 am)',
                'Elapsed time since order' => '15 mins'
            ],
            [
                'Order ID' => 'ORD-2026-003',
                'Priority' => 'Low',
                'Address' => '789 Oxford St, Paddington NSW 2021',
                'Due In' => '3 days',
                'Order Date' => 'Sat 14 Feb 26 (2:15 pm)',
                'Elapsed time since order' => '1 day'
            ],
            [
                'Order ID' => 'ORD-2026-004',
                'Priority' => 'High',
                'Address' => '321 Beach Rd, Bondi Beach NSW 2026',
                'Due In' => '1 hour',
                'Order Date' => 'Sun 15 Feb 26 (1:20 pm)',
                'Elapsed time since order' => '5 mins'
            ],
            [
                'Order ID' => 'ORD-2026-005',
                'Priority' => 'Medium',
                'Address' => '555 King St, Newtown NSW 2042',
                'Due In' => 'today',
                'Order Date' => 'Sun 15 Feb 26 (9:00 am)',
                'Elapsed time since order' => '4 hours'
            ]
        ];
    }
    
    private function displayOrdersTable(array $orders): void
    {
        $headers = [
            'Order #',
            'Address',
            'Priority',
            'Status',
            'Due Date',
            'AUS Date/Time',
            'Source'
        ];
        
        $rows = [];
        
        foreach ($orders as $order) {
            // Color-code priority
            $priority = strtolower($order->priority);
            $coloredPriority = match($priority) {
                'high' => "<fg=red;options=bold>HIGH</>",
                'medium' => "<fg=yellow>MEDIUM</>",
                'low' => "<fg=green>LOW</>",
                default => $order->priority
            };
            
            // Format due date
            $dueDate = $order->dueAt 
                ? date('d M H:i', strtotime($order->dueAt))
                : 'N/A';
            
            // Format AUS date
            $ausDate = $order->ausDatein 
                ? date('d M H:i', strtotime($order->ausDatein))
                : 'N/A';
            
            // Get elapsed time from raw data if available
            $elapsedTime = $order->rawData['elapsed_time'] ?? '';
            
            $rows[] = [
                $order->orderNumber ?? substr($order->externalOrderId, -8),
                substr($order->address ?? 'N/A', 0, 25) . (strlen($order->address ?? '') > 25 ? '…' : ''),
                $coloredPriority,
                "<fg=cyan>{$order->status}</>",
                $dueDate . ($elapsedTime ? " ($elapsedTime)" : ''),
                $ausDate,
                substr($order->source, 0, 10)
            ];
        }
        
        $this->table($headers, $rows);
    }
    
    private function displayOrderSummary(array $orders): void
    {
        $this->newLine();
        $this->info('📊 ORDER SUMMARY:');
        
        // Count by priority
        $priorities = array_count_values(array_map(fn($o) => strtolower($o->priority), $orders));
        $this->line('   Priority Breakdown:');
        foreach ($priorities as $priority => $count) {
            $color = match($priority) {
                'high' => 'red',
                'medium' => 'yellow',
                'low' => 'green',
                default => 'white'
            };
            $this->line("     - <fg={$color}>{$priority}</>: {$count}");
        }
        
        // Count by status
        $statuses = array_count_values(array_map(fn($o) => $o->status, $orders));
        $this->line('   Status Breakdown:');
        foreach ($statuses as $status => $count) {
            $this->line("     - {$status}: {$count}");
        }
        
        // High priority count
        $highPriority = count(array_filter($orders, fn($o) => strtolower($o->priority) === 'high'));
        if ($highPriority > 0) {
            $this->line("   <fg=red;options=bold>⚠️  High Priority Orders: {$highPriority}</>");
        }
        
        // Total orders
        $this->line("   📦 Total Orders: " . count($orders));
    }
    
    private function displaySampleOrders(): void
    {
        $this->warn('📋 Using sample data - Connect to portal for live orders');
        $this->displayPortalOrders();
    }
}