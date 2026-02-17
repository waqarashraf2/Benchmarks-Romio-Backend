<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $orders = [
            // UK Floor Plan Orders
            [
                'order_number' => 'ORD-UK-FP-001',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-001',
                'current_layer' => 'checker',
                'workflow_state' => 'QUEUED_CHECK',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'pending',
                'assigned_to' => null,
                'team_id' => 1,
                'priority' => 'high',
                'received_at' => now()->subHours(2),
                'started_at' => now()->subHour(),
            ],
            [
                'order_number' => 'ORD-UK-FP-002',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-002',
                'current_layer' => 'checker',
                'workflow_state' => 'QUEUED_CHECK',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'pending',
                'assigned_to' => 6, // Lisa Checker
                'team_id' => 1,
                'priority' => 'normal',
                'received_at' => now()->subHours(3),
                'started_at' => now()->subHour(),
            ],
            [
                'order_number' => 'ORD-UK-FP-003',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-003',
                'current_layer' => 'qa',
                'workflow_state' => 'QUEUED_QA',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'pending',
                'assigned_to' => 7, // David QA
                'team_id' => 1,
                'priority' => 'urgent',
                'received_at' => now()->subHours(1),
            ],
            [
                'order_number' => 'ORD-UK-FP-004',
                'project_id' => 1,
                'current_layer' => 'drawer',
                'workflow_state' => 'QUEUED_DRAW',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'pending',
                'team_id' => 1,
                'priority' => 'normal',
                'received_at' => now()->subMinutes(30),
            ],
            // UK Photo Enhancement Orders
            [
                'order_number' => 'ORD-UK-PE-001',
                'project_id' => 2,
                'client_reference' => 'REF-MCH-001',
                'current_layer' => 'designer',
                'workflow_state' => 'IN_DESIGN',
                'workflow_type' => 'PH_2_LAYER',
                'status' => 'in-progress',
                'assigned_to' => 8, // Anna Designer
                'team_id' => 2,
                'priority' => 'high',
                'received_at' => now()->subHours(4),
                'started_at' => now()->subHours(2),
            ],
            [
                'order_number' => 'ORD-UK-PE-002',
                'project_id' => 2,
                'client_reference' => 'REF-MCH-002',
                'current_layer' => 'qa',
                'workflow_state' => 'QUEUED_QA',
                'workflow_type' => 'PH_2_LAYER',
                'status' => 'pending',
                'assigned_to' => 9, // Bob QA
                'team_id' => 2,
                'priority' => 'normal',
                'received_at' => now()->subHours(1),
            ],
            // Australia Orders
            [
                'order_number' => 'ORD-AU-FP-001',
                'project_id' => 3,
                'client_reference' => 'REF-SYD-001',
                'current_layer' => 'drawer',
                'workflow_state' => 'IN_DRAW',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'in-progress',
                'assigned_to' => 10, // Chris Drawer AU
                'team_id' => 3,
                'priority' => 'high',
                'received_at' => now()->subHours(5),
                'started_at' => now()->subHours(3),
            ],
            [
                'order_number' => 'ORD-AU-FP-002',
                'project_id' => 3,
                'current_layer' => 'drawer',
                'workflow_state' => 'QUEUED_DRAW',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'pending',
                'team_id' => 3,
                'priority' => 'normal',
                'received_at' => now()->subMinutes(45),
            ],
            // Completed Orders
            [
                'order_number' => 'ORD-UK-FP-COMPLETED-001',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-DONE-001',
                'current_layer' => 'qa',
                'workflow_state' => 'DELIVERED',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'completed',
                'team_id' => 1,
                'priority' => 'normal',
                'received_at' => now()->subDays(2),
                'started_at' => now()->subDays(2)->addHour(),
                'completed_at' => now()->subDay(),
                'delivered_at' => now()->subDay(),
            ],
            [
                'order_number' => 'ORD-UK-PE-COMPLETED-001',
                'project_id' => 2,
                'client_reference' => 'REF-MCH-DONE-001',
                'current_layer' => 'qa',
                'workflow_state' => 'DELIVERED',
                'workflow_type' => 'PH_2_LAYER',
                'status' => 'completed',
                'team_id' => 2,
                'priority' => 'high',
                'received_at' => now()->subDays(1),
                'started_at' => now()->subDays(1)->addHour(),
                'completed_at' => now()->subHours(6),
                'delivered_at' => now()->subHours(6),
            ],
            // Today's deliveries
            [
                'order_number' => 'ORD-UK-FP-DELIVERED-TODAY-001',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-TODAY-001',
                'current_layer' => 'qa',
                'workflow_state' => 'DELIVERED',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'completed',
                'team_id' => 1,
                'priority' => 'normal',
                'received_at' => now()->subHours(8),
                'started_at' => now()->subHours(7),
                'completed_at' => now()->subHours(1),
                'delivered_at' => now()->subHours(1),
            ],
            [
                'order_number' => 'ORD-UK-FP-DELIVERED-TODAY-002',
                'project_id' => 1,
                'client_reference' => 'REF-LDN-TODAY-002',
                'current_layer' => 'qa',
                'workflow_state' => 'DELIVERED',
                'workflow_type' => 'FP_3_LAYER',
                'status' => 'completed',
                'team_id' => 1,
                'priority' => 'high',
                'received_at' => now()->subHours(6),
                'started_at' => now()->subHours(5),
                'completed_at' => now()->subMinutes(30),
                'delivered_at' => now()->subMinutes(30),
            ],
        ];

        foreach ($orders as $order) {
            Order::create($order);
        }
    }
}
