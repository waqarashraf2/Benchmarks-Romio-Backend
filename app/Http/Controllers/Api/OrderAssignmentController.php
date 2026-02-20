<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderAssignmentController extends Controller
{
    /**
     * Get orders for manager view with the exact columns you need
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'project:id,name',
            'assignedUser:id,name,role',
            'workAssignments' => function($q) {
                $q->with('user:id,name,role')
                  ->orderBy('assigned_at', 'desc');
            }
        ]);

        // Filter by project if specified
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by layer
        if ($request->has('layer')) {
            $query->where('current_layer', $request->layer);
        }

        $orders = $query->orderBy('received_at', 'desc')
                       ->paginate($request->get('per_page', 20));

        // Transform data for the table
        $transformed = $orders->map(function($order) {
            return $this->transformOrderForTable($order);
        });

        return response()->json([
            'data' => $transformed,
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
            ]
        ]);
    }

    /**
     * Get available workers for assignment (Drawers and QA)
     */
    public function getAvailableWorkers(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'layer' => 'required|in:drawer,qa'
        ]);

        $workers = User::where('project_id', $request->project_id)
            ->whereIn('role', [$request->layer])
            ->where('is_active', true)
            ->where('is_absent', false)
            ->withCount(['workAssignments' => function($q) {
                $q->where('status', 'assigned')
                  ->orWhere('status', 'in-progress');
            }])
            ->get()
            ->map(function($worker) {
                return [
                    'id' => $worker->id,
                    'name' => $worker->name,
                    'email' => $worker->email,
                    'role' => $worker->role,
                    'current_load' => $worker->work_assignments_count,
                    'max_load' => 5, // You can make this configurable
                    'available' => $worker->work_assignments_count < 5
                ];
            });

        return response()->json([
            'workers' => $workers
        ]);
    }

    /**
     * Assign order to worker
     */
    public function assign(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'layer' => 'required|in:drawer,qa,checker,designer',
            'note' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $order = Order::findOrFail($orderId);
            $user = User::findOrFail($request->user_id);

            // Check if user can work on this layer
            if (!$this->userCanWorkOnLayer($user, $request->layer)) {
                return response()->json([
                    'error' => 'User cannot work on this layer'
                ], 422);
            }

            // Check worker load
            $currentLoad = WorkAssignment::where('user_id', $user->id)
                ->whereIn('status', ['assigned', 'in-progress'])
                ->count();

            if ($currentLoad >= 5) {
                return response()->json([
                    'error' => 'Worker has reached maximum capacity'
                ], 422);
            }

            // Create assignment record
            $assignment = WorkAssignment::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'layer' => $request->layer,
                'assigned_at' => now(),
                'status' => 'assigned',
                'assigned_by' => auth()->id()
            ]);

            // Update order
            $order->update([
                'assigned_to' => $user->id,
                'current_layer' => $request->layer,
                'status' => 'assigned',
                'supervisor_notes' => $request->note ?? $order->supervisor_notes
            ]);

            // Log activity
            activity()
                ->performedOn($order)
                ->causedBy(auth()->user())
                ->withProperties([
                    'assigned_to' => $user->name,
                    'layer' => $request->layer
                ])
                ->log('order_assigned');

            DB::commit();

            return response()->json([
                'message' => 'Order assigned successfully',
                'order' => $this->transformOrderForTable($order->fresh())
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk assign multiple orders
     */
    public function bulkAssign(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'user_id' => 'required|exists:users,id',
            'layer' => 'required|in:drawer,qa,checker,designer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $user = User::findOrFail($request->user_id);
            $orders = Order::whereIn('id', $request->order_ids)->get();
            
            $assigned = [];
            $failed = [];

            foreach ($orders as $order) {
                // Check current load
                $currentLoad = WorkAssignment::where('user_id', $user->id)
                    ->whereIn('status', ['assigned', 'in-progress'])
                    ->count();

                if ($currentLoad >= 5) {
                    $failed[] = [
                        'order_id' => $order->id,
                        'reason' => 'Worker at max capacity'
                    ];
                    continue;
                }

                // Create assignment
                $assignment = WorkAssignment::create([
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'layer' => $request->layer,
                    'assigned_at' => now(),
                    'status' => 'assigned',
                    'assigned_by' => auth()->id()
                ]);

                $order->update([
                    'assigned_to' => $user->id,
                    'current_layer' => $request->layer,
                    'status' => 'assigned'
                ]);

                $assigned[] = $order->id;
            }

            DB::commit();

            return response()->json([
                'message' => count($assigned) . ' orders assigned successfully',
                'assigned' => $assigned,
                'failed' => $failed
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reassign order to different worker
     */
    public function reassign(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $order = Order::findOrFail($orderId);
            $oldUserId = $order->assigned_to;

            // Mark old assignment as completed
            WorkAssignment::where('order_id', $order->id)
                ->where('status', 'assigned')
                ->update(['status' => 'reassigned']);

            // Create new assignment
            WorkAssignment::create([
                'order_id' => $order->id,
                'user_id' => $request->user_id,
                'layer' => $order->current_layer,
                'assigned_at' => now(),
                'status' => 'assigned',
                'assigned_by' => auth()->id()
            ]);

            // Update order
            $order->update([
                'assigned_to' => $request->user_id,
                'supervisor_notes' => $request->reason
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Order reassigned successfully',
                'order' => $this->transformOrderForTable($order->fresh())
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get assignment statistics for dashboard
     */
    public function getStats(Request $request)
    {
        $projectId = $request->get('project_id');

        $stats = [
            'pending_drawer' => Order::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->where('current_layer', 'drawer')
                ->whereNull('assigned_to')
                ->where('status', 'pending')
                ->count(),
            
            'pending_qa' => Order::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->where('current_layer', 'qa')
                ->whereNull('assigned_to')
                ->where('status', 'pending')
                ->count(),
            
            'in_progress' => Order::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->whereIn('status', ['assigned', 'in-progress'])
                ->count(),
            
            'completed_today' => Order::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->whereDate('completed_at', today())
                ->count(),
            
            'active_workers' => User::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->whereIn('role', ['drawer', 'qa', 'checker', 'designer'])
                ->where('is_active', true)
                ->where('is_absent', false)
                ->count(),
            
            'overdue' => Order::when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'delivered'])
                ->count()
        ];

        return response()->json($stats);
    }

    /**
     * Transform order for the table with all required columns
     */
    private function transformOrderForTable($order)
    {
        // Calculate elapsed time
        $receivedAt = $order->received_at ? $order->received_at->toISOString() : null;
        $elapsedTime = $receivedAt ? now()->diffInMinutes($order->received_at) : 0;
        $elapsedFormatted = $this->formatElapsedTime($elapsedTime);

        // Get current assignment
        $currentAssignment = $order->workAssignments->first();
        
        // Get team members (you'll need to adjust this based on your data structure)
        $drawer = $this->getWorkerByLayer($order, 'drawer');
        $checker = $this->getWorkerByLayer($order, 'checker');
        $qa = $this->getWorkerByLayer($order, 'qa');
        $uploader = $this->getWorkerByLayer($order, 'uploader');

        return [
            'id' => $order->id,
            'date' => $order->received_at?->format('Y-m-d H:i'),
            'order_id' => $order->order_number,
            'order_id_full' => $order->order_number . ' / ' . $elapsedFormatted,
            'elapsed_time' => $elapsedTime,
            'elapsed_formatted' => $elapsedFormatted,
            'address' => $order->address ?? 'N/A',
            'due_in' => $order->due_date?->format('Y-m-d'),
            'address_due' => ($order->address ?? 'N/A') . ' / ' . ($order->due_date?->format('Y-m-d') ?? 'No due date'),
            'drawer' => $drawer ? $drawer['name'] : '—',
            'drawer_live_qa' => $drawer ? ($drawer['completed'] ? '✅' : '⏳') : '—',
            'qa' => $qa ? $qa['name'] : '—',
            'qa_status' => $qa ? ($qa['completed'] ? '✅' : '⏳') : '—',
            'checker' => $checker ? $checker['name'] : '—',
            'checker_live_qa' => $checker ? ($checker['completed'] ? '✅' : ($checker['rejected'] ? '❌' : '⏳')) : '—',
            'uploader_live_qa' => $uploader ? ($uploader['completed'] ? '✅' : '⏳') : '—',
            'status' => $this->getStatusDisplay($order),
            'status_full' => $this->getStatusDisplay($order) . ' / ' . $order->current_layer,
            'raw_status' => $order->status,
            'current_layer' => $order->current_layer,
            'can_assign' => in_array($order->status, ['pending', 'rejected']) || !$order->assigned_to,
            'assigned_to' => $order->assigned_to,
            'assigned_user' => $order->assignedUser?->name,
            'project_id' => $order->project_id,
            'project_name' => $order->project?->name,
        ];
    }

    private function getWorkerByLayer($order, $layer)
    {
        $assignment = $order->workAssignments
            ->where('layer', $layer)
            ->first();

        if (!$assignment) {
            return null;
        }

        return [
            'name' => $assignment->user?->name ?? 'Unknown',
            'completed' => $assignment->status === 'completed',
            'rejected' => $assignment->status === 'rejected',
            'in_progress' => $assignment->status === 'in-progress'
        ];
    }

    private function formatElapsedTime($minutes)
    {
        if ($minutes < 60) {
            return $minutes . 'm';
        } elseif ($minutes < 1440) {
            $hours = floor($minutes / 60);
            $mins = $minutes % 60;
            return $hours . 'h ' . $mins . 'm';
        } else {
            $days = floor($minutes / 1440);
            $hours = floor(($minutes % 1440) / 60);
            return $days . 'd ' . $hours . 'h';
        }
    }

    private function getStatusDisplay($order)
    {
        if ($order->status === 'completed') {
            return 'Completed';
        } elseif ($order->status === 'rejected') {
            return 'Rejected';
        } elseif ($order->status === 'in-progress') {
            return 'In Progress';
        } elseif ($order->status === 'assigned') {
            return 'Assigned';
        } elseif ($order->is_on_hold) {
            return 'On Hold';
        } else {
            return 'Pending';
        }
    }

    private function userCanWorkOnLayer($user, $layer)
    {
        $roleLayerMap = [
            'drawer' => ['drawer'],
            'qa' => ['qa'],
            'checker' => ['checker'],
            'designer' => ['designer']
        ];

        return in_array($user->role, $roleLayerMap[$layer] ?? []);
    }
}