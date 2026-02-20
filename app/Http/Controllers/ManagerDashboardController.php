<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Broadcast;
use App\Notifications\OrderAssignmentAlert;
use App\Events\OrderAssigned;
use App\Models\User;
use App\Models\Order;
use App\Models\WorkAssignment;
use App\Models\WorkItem;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class ManagerDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $stats = $this->getEssentialStats($request);
        
        return response()->json([
            'data' => $stats
        ]);
    }

private function getEssentialStats(Request $request)
{
    $projectId = $request->project_id;
    
    // Base queries
    $ordersQuery = Order::query();
    
    if ($projectId) {
        $ordersQuery->where('project_id', $projectId);
    }

    $today = now()->startOfDay();

    // Get active workers count - users who have assignments today
    $activeDrawers = User::where('role', 'drawer')
        ->where('is_active', true)
        ->whereHas('workAssignments', function($q) use ($today) {
            $q->whereIn('status', ['assigned', 'in_progress'])
              ->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();
        
    $activeCheckers = User::where('role', 'checker')
        ->where('is_active', true)
        ->whereHas('workAssignments', function($q) use ($today) {
            $q->whereIn('status', ['assigned', 'in_progress'])
              ->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();
        
    $activeQa = User::where('role', 'qa')
        ->where('is_active', true)
        ->whereHas('workAssignments', function($q) use ($today) {
            $q->whereIn('status', ['assigned', 'in_progress'])
              ->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();

    // Count pending orders (status = 'pending')
    $pendingOrders = (clone $ordersQuery)
        ->where('status', 'pending')
        ->count();

    // Count in-progress orders
    $inProgressOrders = (clone $ordersQuery)
        ->where('status', 'in-progress')
        ->count();

    // Count completed today
    $completedToday = (clone $ordersQuery)
        ->where('status', 'completed')
        ->whereDate('completed_at', today())
        ->count();

    // Count rejected today
    $rejectedToday = (clone $ordersQuery)
        ->whereNotNull('rejected_at')
        ->whereDate('rejected_at', today())
        ->count();

    // Count absent workers (active but no assignments today)
    $absentDrawers = User::where('role', 'drawer')
        ->where('is_active', true)
        ->whereDoesntHave('workAssignments', function($q) use ($today) {
            $q->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();
        
    $absentCheckers = User::where('role', 'checker')
        ->where('is_active', true)
        ->whereDoesntHave('workAssignments', function($q) use ($today) {
            $q->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();
        
    $absentQa = User::where('role', 'qa')
        ->where('is_active', true)
        ->whereDoesntHave('workAssignments', function($q) use ($today) {
            $q->whereDate('assigned_at', '>=', $today); // Using assigned_at from work_assignments table
        })
        ->count();

    $absentToday = $absentDrawers + $absentCheckers + $absentQa;

    // Get queue health with proper mapping for frontend
    $queueHealth = [
        'draw' => [ // Frontend expects 'draw', not 'drawer'
            'queued' => (clone $ordersQuery)
                ->where('current_layer', 'drawer')
                ->where('status', 'pending')
                ->count(),
            'in_progress' => (clone $ordersQuery)
                ->where('current_layer', 'drawer')
                ->where('status', 'in-progress')
                ->count(),
            'avg_time_minutes' => $this->getAverageStageTime('draw', $projectId),
            'completed_today' => $this->getStageCompletedToday('draw', $projectId),
            'rejected_today' => $this->getStageRejectedToday('draw', $projectId),
        ],
        'check' => [ // Frontend expects 'check', not 'checker'
            'queued' => (clone $ordersQuery)
                ->where('current_layer', 'checker')
                ->where('status', 'pending')
                ->count(),
            'in_progress' => (clone $ordersQuery)
                ->where('current_layer', 'checker')
                ->where('status', 'in-progress')
                ->count(),
            'avg_time_minutes' => $this->getAverageStageTime('check', $projectId),
            'completed_today' => $this->getStageCompletedToday('check', $projectId),
            'rejected_today' => $this->getStageRejectedToday('check', $projectId),
        ],
        'qa' => [
            'queued' => (clone $ordersQuery)
                ->where('current_layer', 'qa')
                ->where('status', 'pending')
                ->count(),
            'in_progress' => (clone $ordersQuery)
                ->where('current_layer', 'qa')
                ->where('status', 'in-progress')
                ->count(),
            'avg_time_minutes' => $this->getAverageStageTime('qa', $projectId),
            'completed_today' => $this->getStageCompletedToday('qa', $projectId),
            'rejected_today' => $this->getStageRejectedToday('qa', $projectId),
        ],
    ];

    // Get recent activities
    $recentActivities = $this->getRecentActivities($projectId);

    return [
        // Stats that match frontend expectations
        'pending_orders' => $pendingOrders,
        'active_workers' => $activeDrawers + $activeCheckers + $activeQa,
        'rejected_today' => $rejectedToday,
        'completed_today' => $completedToday,
        'absent_today' => $absentToday,
        
        // Additional stats for reference
        'total_workers' => User::whereIn('role', ['drawer', 'checker', 'qa'])
            ->where('is_active', true)
            ->count(),
        'in_progress_orders' => $inProgressOrders,
        
        // Worker breakdown
        'active_drawers' => $activeDrawers,
        'active_checkers' => $activeCheckers,
        'active_qa' => $activeQa,
        
        'total_drawers' => User::where('role', 'drawer')->where('is_active', true)->count(),
        'total_checkers' => User::where('role', 'checker')->where('is_active', true)->count(),
        'total_qa' => User::where('role', 'qa')->where('is_active', true)->count(),
        
        // Queue health with frontend-friendly keys
        'queue_health' => $queueHealth,
        
        // Recent activities
        'recent_activities' => $recentActivities,
        
        // Projects list for filter
        'projects' => \App\Models\Project::select('id', 'name', 'code')->get(),
    ];
}


private function getStageCompletedToday($stage, $projectId = null)
{
    $query = WorkAssignment::where('layer', $stage)
        ->where('status', 'completed')
        ->whereDate('completed_at', today());

    if ($projectId) {
        $query->whereHas('order', function($q) use ($projectId) {
            $q->where('project_id', $projectId);
        });
    }

    return $query->count();
}

private function getStageRejectedToday($stage, $projectId = null)
{
    $layerMap = [
        'draw' => 'drawer',
        'check' => 'checker',
        'qa' => 'qa'
    ];
    
    $dbLayer = $layerMap[$stage] ?? $stage;
    
    $query = Order::where('current_layer', $dbLayer)
        ->whereNotNull('rejected_at')
        ->whereDate('rejected_at', today());

    if ($projectId) {
        $query->where('project_id', $projectId);
    }

    return $query->count();
}


    private function getRecentActivities($projectId = null, $limit = 10)
    {
        // Get recent work assignments
        $assignments = WorkAssignment::with(['user', 'order'])
            ->whereHas('order', function($q) use ($projectId) {
                if ($projectId) {
                    $q->where('project_id', $projectId);
                }
            })
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(function($assignment) {
                $action = $this->getActionText($assignment);
                return [
                    'id' => $assignment->id,
                    'order_number' => $assignment->order->order_number,
                    'action' => $action,
                    'user_name' => $assignment->user->name,
                    'user_role' => $assignment->user->role,
                    'time_ago' => $assignment->updated_at->diffForHumans(),
                ];
            });

        return $assignments;
    }

    private function getActionText($assignment)
    {
        switch ($assignment->status) {
            case 'assigned':
                return "assigned to {$assignment->layer}";
            case 'in_progress':
                return "started {$assignment->layer}";
            case 'completed':
                return "completed {$assignment->layer}";
            case 'rejected':
                return "rejected at {$assignment->layer}";
            default:
                return "updated at {$assignment->layer}";
        }
    }

    public function getOrders(Request $request)
    {
        $query = Order::with([
            'project:id,name,code',
            'workAssignments' => function($q) {
                $q->with('user:id,name')
                  ->latest();
            },
        ]);

        if ($request->project_id) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('order_number', 'like', '%' . $request->search . '%')
                  ->orWhere('address', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->priority && in_array($request->priority, ['urgent', 'high', 'normal', 'low'])) {
            $query->where('priority', $request->priority);
        }

        // Handle layer filter - convert frontend layer to database layer
        if ($request->layer && in_array($request->layer, ['draw', 'check', 'qa'])) {
            $layerMap = [
                'draw' => 'drawer',
                'check' => 'checker',
                'qa' => 'qa'
            ];
            $query->where('current_layer', $layerMap[$request->layer]);
        }

        if ($request->status && in_array($request->status, ['pending', 'in_progress', 'completed', 'on_hold', 'rejected'])) {
            if ($request->status === 'on_hold') {
                $query->where('is_on_hold', true);
            } elseif ($request->status === 'rejected') {
                $query->whereNotNull('rejected_at');
            } else {
                $dbStatus = $request->status === 'in_progress' ? 'in-progress' : $request->status;
                $query->where('status', $dbStatus);
            }
        }

        $orders = $query->latest('received_at')
            ->paginate($request->per_page ?? 10);

        // Transform the data for frontend
        $orders->getCollection()->transform(function($order) {
            // Get current assignments for each layer
            $currentDraw = $order->workAssignments
                ->where('layer', 'draw')
                ->whereIn('status', ['assigned', 'in_progress'])
                ->first();
                
            $currentCheck = $order->workAssignments
                ->where('layer', 'check')
                ->whereIn('status', ['assigned', 'in_progress'])
                ->first();
                
            $currentQa = $order->workAssignments
                ->where('layer', 'qa')
                ->whereIn('status', ['assigned', 'in_progress'])
                ->first();
            
            // Get completed assignments
            $completedDraw = $order->workAssignments
                ->where('layer', 'draw')
                ->where('status', 'completed')
                ->last();
                
            $completedCheck = $order->workAssignments
                ->where('layer', 'check')
                ->where('status', 'completed')
                ->last();
                
            $completedQa = $order->workAssignments
                ->where('layer', 'qa')
                ->where('status', 'completed')
                ->last();

            // Map database current_layer to frontend layer
            $frontendLayerMap = [
                'drawer' => 'draw',
                'checker' => 'check',
                'qa' => 'qa',
                'designer' => 'designer'
            ];

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'address' => $order->address,
                'received_at' => $order->received_at,
                'due_date' => $order->due_date,
                'priority' => $order->priority,
                'current_layer' => $frontendLayerMap[$order->current_layer] ?? $order->current_layer,
                'status' => $order->is_on_hold ? 'on_hold' : ($order->rejected_at ? 'rejected' : $order->status),
                'is_on_hold' => $order->is_on_hold,
                'hold_reason' => $order->hold_reason,
                'recheck_count' => $order->recheck_count,
                'attempt_draw' => $order->attempt_draw,
                'attempt_check' => $order->attempt_check,
                'attempt_qa' => $order->attempt_qa,
                'rejection_reason' => $order->rejection_reason,
                'project_id' => $order->project_id,
                'project_code' => $order->project->code ?? null,
                
                // Current drawer (if any)
                'drawer' => $currentDraw ? [
                    'id' => $currentDraw->user->id,
                    'name' => $currentDraw->user->name,
                    'status' => $currentDraw->status,
                    'started_at' => $currentDraw->started_at,
                ] : ($completedDraw ? [
                    'id' => $completedDraw->user->id,
                    'name' => $completedDraw->user->name,
                    'status' => 'completed',
                    'completed_at' => $completedDraw->completed_at,
                ] : null),
                
                // Current checker (if any)
                'checker' => $currentCheck ? [
                    'id' => $currentCheck->user->id,
                    'name' => $currentCheck->user->name,
                    'status' => $currentCheck->status,
                    'started_at' => $currentCheck->started_at,
                ] : ($completedCheck ? [
                    'id' => $completedCheck->user->id,
                    'name' => $completedCheck->user->name,
                    'status' => 'completed',
                    'completed_at' => $completedCheck->completed_at,
                ] : null),
                
                // Current QA (if any)
                'qa' => $currentQa ? [
                    'id' => $currentQa->user->id,
                    'name' => $currentQa->user->name,
                    'status' => $currentQa->status,
                    'started_at' => $currentQa->started_at,
                ] : ($completedQa ? [
                    'id' => $completedQa->user->id,
                    'name' => $completedQa->user->name,
                    'status' => 'completed',
                    'completed_at' => $completedQa->completed_at,
                ] : null),
                
                // QA info for different stages
                'drawer_qa' => null, // Kept for backward compatibility
                'checker_qa' => null,
                'uploader_qa' => null,
            ];
        });

        return response()->json($orders);
    }

    public function getQaUsers(Request $request)
    {
        $projectId = $request->project_id;
        
        $query = User::where('role', 'qa')
            ->where('is_active', true);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $qaUsers = $query->withCount([
                'workAssignments as today_completed' => function($q) {
                    $q->where('status', 'completed')
                      ->whereDate('completed_at', today());
                },
                'workAssignments as total_completed' => function($q) {
                    $q->where('status', 'completed');
                }
            ])
            ->get()
            ->map(function($qa) {
                // Calculate rejection rate
                $totalQaDone = $qa->workAssignments()
                    ->where('layer', 'qa')
                    ->where('status', 'completed')
                    ->count();
                    
                $rejectedCount = Order::whereNotNull('rejected_at')
                    ->where('current_layer', 'qa')
                    ->whereHas('workAssignments', function($q) use ($qa) {
                        $q->where('user_id', $qa->id)
                          ->where('layer', 'qa');
                    })
                    ->count();
                
                $rejectionRate = $totalQaDone > 0 
                    ? round(($rejectedCount / $totalQaDone) * 100, 1) 
                    : 0;

                return [
                    'id' => $qa->id,
                    'name' => $qa->name,
                    'email' => $qa->email,
                    'role' => $qa->role,
                    'today_completed' => $qa->today_completed ?? 0,
                    'total_completed' => $qa->total_completed ?? 0,
                    'rejection_rate' => $rejectionRate,
                    'is_active' => $qa->is_active,
                    'daily_target' => $qa->daily_target ?? 20,
                ];
            });

        return response()->json([
            'data' => $qaUsers
        ]);
    }

    private function getAverageStageTime($stage, $projectId = null)
    {
        $query = WorkAssignment::where('layer', $stage)
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at');

        if ($projectId) {
            $query->whereHas('order', function($q) use ($projectId) {
                $q->where('project_id', $projectId);
            });
        }

        $avgSeconds = $query->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_time'))
            ->first()
            ->avg_time;

        return $avgSeconds ? round($avgSeconds / 60) : 0;
    }

    public function getAvailableWorkers(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'stage' => 'required|in:draw,check,qa'
        ]);

        // Map stage to actual role in users table
        $roleMap = [
            'draw' => 'drawer',
            'check' => 'checker',
            'qa' => 'qa'
        ];
        
        $role = $roleMap[$request->stage];

        $workers = User::where('role', $role)
            ->where('is_active', true)
            ->withCount([
                'workAssignments as wip_count' => function($q) {
                    $q->whereIn('status', ['assigned', 'in_progress']);
                },
                'workAssignments as today_completed' => function($q) {
                    $q->where('status', 'completed')
                      ->whereDate('completed_at', today());
                }
            ])
            ->get()
            ->map(function($worker) {
                return [
                    'id' => $worker->id,
                    'name' => $worker->name,
                    'email' => $worker->email,
                    'wip_count' => $worker->wip_count,
                    'today_completed' => $worker->today_completed,
                    'daily_target' => $worker->daily_target ?? 20,
                ];
            });

        return response()->json([
            'data' => $workers
        ]);
    }

public function assignOrder(Request $request, Order $order)
{
    $request->validate([
        'user_id' => 'required|exists:users,id',
        // Accept either 'stage' or 'layer', but map it to 'layer' for the model
        'stage' => 'sometimes|required|in:draw,check,qa,design',
        'layer' => 'sometimes|required|in:draw,check,qa,design',
        'notes' => 'nullable|string'
    ]);

    try {
        DB::beginTransaction();

        // Lock the order row to prevent race conditions
        $order = Order::where('id', $order->id)->lockForUpdate()->first();

        // Determine which field was used and set the layer value
        $layerValue = $request->has('stage') ? $request->stage : $request->layer;

        // Layer mappings using only existing fields
        $layerMappings = [
            'draw' => [
                'db_layer' => 'drawer',
                'workflow_state' => 'IN_DRAW'
            ],
            'check' => [
                'db_layer' => 'checker',
                'workflow_state' => 'IN_CHECK'
            ],
            'qa' => [
                'db_layer' => 'qa',
                'workflow_state' => 'IN_QA'
            ],
            'design' => [
                'db_layer' => 'designer',
                'workflow_state' => 'IN_DESIGN'
            ]
        ];

        $layerConfig = $layerMappings[$layerValue];
        $dbLayerValue = $layerConfig['db_layer'];
        $workflowState = $layerConfig['workflow_state'];

        // Check if there's already an active assignment for this layer
        $existingAssignment = WorkAssignment::where('order_id', $order->id)
            ->where('layer', $layerValue)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->lockForUpdate()
            ->first();

        // If there's an existing active assignment, redirect to reassign
        if ($existingAssignment) {
            DB::commit(); // Commit any pending changes (none so far)
            
            // Log that we're redirecting to reassign
            Log::info('Redirecting assignment to reassign due to existing active assignment', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'layer' => $layerValue,
                'existing_assignment_id' => $existingAssignment->id,
                'existing_user_id' => $existingAssignment->user_id,
                'new_user_id' => $request->user_id
            ]);
            
            // Create a new request for reassignment
            $reassignRequest = new Request([
                'user_id' => $request->user_id,
                'layer' => $layerValue,
                'notes' => $request->notes ?: 'Auto-reassigned from assign attempt',
                'current_assignment_id' => $existingAssignment->id
            ]);
            
            // Call the reassignOrder method with the new request
            return $this->reassignOrder($reassignRequest, $order);
        }

        // Check if user exists and is active
        $user = User::where('id', $request->user_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (!$user) {
            throw new \Exception('Selected user is not active or does not exist');
        }

        // Check if user is at max capacity
        $activeWorkCount = WorkAssignment::where('user_id', $user->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->lockForUpdate()
            ->count();
        
        $maxWip = $user->max_wip_limit ?? 5;
        
        if ($activeWorkCount >= $maxWip) {
            throw new \Exception("User has reached maximum work capacity ({$maxWip})");
        }

        // Create work assignment
        $assignment = WorkAssignment::create([
            'order_id' => $order->id,
            'user_id' => $request->user_id,
            'layer' => $layerValue,
            'assigned_at' => now(),
            'status' => 'assigned'
        ]);

        // Update order with only fields that likely exist
        $orderData = [
            'assigned_to' => $request->user_id,
            'current_layer' => $dbLayerValue,
            'workflow_state' => $workflowState,
            'status' => 'in-progress',
            'started_at' => now(),
            'assigned_at' => now(),
        ];

        $order->update($orderData);

        // Log the assignment activity
        if (class_exists('App\Models\ActivityLog')) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'order_assigned',
                'description' => "Assigned order #{$order->order_number} to {$user->name} for {$layerValue} layer",
                'model_type' => 'Order',
                'model_id' => $order->id,
                'properties' => json_encode([
                    'layer' => $layerValue,
                    'assigned_to' => $user->id,
                    'assigned_to_name' => $user->name,
                    'workflow_state' => $workflowState
                ])
            ]);
        }

        DB::commit();

        // Load relationships for response
        $assignment->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Order assigned successfully',
            'data' => [
                'assignment' => [
                    'id' => $assignment->id,
                    'order_id' => $assignment->order_id,
                    'user' => [
                        'id' => $assignment->user->id,
                        'name' => $assignment->user->name,
                        'email' => $assignment->user->email,
                    ],
                    'layer' => $assignment->layer,
                    'status' => $assignment->status,
                    'assigned_at' => $assignment->assigned_at,
                ],
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'workflow_state' => $order->workflow_state,
                    'current_layer' => $order->current_layer,
                    'assigned_to' => $order->assigned_to,
                    'started_at' => $order->started_at,
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Order assignment failed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $request->user_id,
            'layer' => $layerValue ?? 'unknown',
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => 'Failed to assign order',
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Reassign an order to a different user
 */
/**
 * Reassign an order to a different user
 */
public function reassignOrder(Request $request, Order $order)
{
    $request->validate([
        'user_id' => 'required|exists:users,id',
        'layer' => 'required|in:draw,check,qa,design',
        'notes' => 'nullable|string',
        'current_assignment_id' => 'sometimes|exists:work_assignments,id'
    ]);

    try {
        DB::beginTransaction();
        
        // Lock the order row to prevent race conditions
        $order = Order::where('id', $order->id)->lockForUpdate()->first();

        // Find the current active assignment for this layer with a lock
        $currentAssignment = WorkAssignment::where('order_id', $order->id)
            ->where('layer', $request->layer)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->lockForUpdate()
            ->first();

        if (!$currentAssignment) {
            DB::commit();
            // If no active assignment, just use the regular assign method
            return $this->assignOrder($request, $order);
        }

        // Double-check if this assignment is still active (avoid race conditions)
        if (!in_array($currentAssignment->status, ['assigned', 'in_progress'])) {
            DB::commit();
            return $this->assignOrder($request, $order);
        }

        // Check if new user exists and is active
        $newUser = User::where('id', $request->user_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (!$newUser) {
            throw new \Exception('Selected user is not active or does not exist');
        }

        // Check new user's capacity
        $activeWorkCount = WorkAssignment::where('user_id', $newUser->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->lockForUpdate()
            ->count();
        
        $maxWip = $newUser->max_wip_limit ?? 5;
        
        if ($activeWorkCount >= $maxWip) {
            throw new \Exception("User has reached maximum work capacity ({$maxWip})");
        }

        // Layer mappings
        $layerMappings = [
            'draw' => ['db_layer' => 'drawer', 'workflow_state' => 'IN_DRAW'],
            'check' => ['db_layer' => 'checker', 'workflow_state' => 'IN_CHECK'],
            'qa' => ['db_layer' => 'qa', 'workflow_state' => 'IN_QA'],
            'design' => ['db_layer' => 'designer', 'workflow_state' => 'IN_DESIGN']
        ];

        $layerConfig = $layerMappings[$request->layer];

        // Mark current assignment as reassigned - use 'completed' instead of 'reassigned'
        // This assumes 'completed' is in your ENUM
        $currentAssignment->update([
            'status' => 'completed', // Changed from 'reassigned' to 'completed'
            'completed_at' => now(),
            'notes' => $request->notes ?: "Reassigned to user ID: {$request->user_id}"
        ]);

        // Verify the update was successful
        if ($currentAssignment->wasChanged('status') === false) {
            throw new \Exception('Failed to update current assignment status');
        }

        // Create new assignment
        $newAssignment = WorkAssignment::create([
            'order_id' => $order->id,
            'user_id' => $request->user_id,
            'layer' => $request->layer,
            'assigned_at' => now(),
            'status' => 'assigned',
            'notes' => $request->notes,
            'replaces_assignment_id' => $currentAssignment->id
        ]);

        // Update order
        $order->update([
            'assigned_to' => $request->user_id,
            'current_layer' => $layerConfig['db_layer'],
            'workflow_state' => $layerConfig['workflow_state'],
            'status' => 'in-progress',
            'assigned_at' => now(),
        ]);

        // Log activity
        if (class_exists('App\Models\ActivityLog')) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'order_reassigned',
                'description' => "Reassigned order #{$order->order_number} from user {$currentAssignment->user_id} to {$newUser->name} for {$request->layer} layer",
                'model_type' => 'Order',
                'model_id' => $order->id,
                'properties' => json_encode([
                    'layer' => $request->layer,
                    'previous_user_id' => $currentAssignment->user_id,
                    'previous_assignment_id' => $currentAssignment->id,
                    'new_user_id' => $newUser->id,
                    'new_user_name' => $newUser->name,
                    'workflow_state' => $layerConfig['workflow_state'],
                    'notes' => $request->notes
                ])
            ]);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Order reassigned successfully',
            'data' => [
                'previous_assignment' => [
                    'id' => $currentAssignment->id,
                    'user_id' => $currentAssignment->user_id,
                    'status' => 'completed', // Changed from 'reassigned' to 'completed'
                ],
                'new_assignment' => [
                    'id' => $newAssignment->id,
                    'user_id' => $newAssignment->user_id,
                    'user_name' => $newUser->name,
                    'layer' => $newAssignment->layer,
                    'status' => $newAssignment->status
                ],
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'current_layer' => $order->current_layer,
                    'assigned_to' => $order->assigned_to
                ]
            ]
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Order reassignment failed', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'user_id' => $request->user_id,
            'layer' => $request->layer,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => 'Failed to reassign order',
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Calculate due date based on priority and estimated hours
 */
private function calculateDueDate(string $priority, ?float $estimatedHours): ?\Carbon\Carbon
{
    if (!$estimatedHours) {
        return null;
    }

    $now = now();
    
    return match($priority) {
        'urgent' => $now->addHours(min($estimatedHours, 4)),
        'high' => $now->addHours(min($estimatedHours, 8)),
        'normal' => $now->addHours(min($estimatedHours, 24)),
        default => $now->addHours($estimatedHours),
    };
}

/**
 * Send notifications about the assignment
 */
private function sendAssignmentNotifications(Order $order, User $user, string $stage): void
{
    try {
        // Create notification in your custom notifications table
        \App\Models\Notification::create([
            'user_id' => $user->id,
            'type' => 'order_assigned',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'stage' => $stage,
                'message' => "New order #{$order->order_number} assigned to you for {$stage} stage",
                'assigned_by' => auth()->user()->name,
            ],
            'created_at' => now(),
        ]);
        
        // Also notify managers
        $managers = User::whereIn('role', ['operations_manager', 'director', 'ceo'])
            ->where('id', '!=', auth()->id())
            ->get();
        
        foreach ($managers as $manager) {
            \App\Models\Notification::create([
                'user_id' => $manager->id,
                'type' => 'order_assignment_alert',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'assigned_user_id' => $user->id,
                    'assigned_user_name' => $user->name,
                    'stage' => $stage,
                    'message' => "Order #{$order->order_number} assigned to {$user->name} for {$stage} stage",
                    'assigned_by' => auth()->user()->name,
                ],
                'created_at' => now(),
            ]);
        }
        
    } catch (\Exception $e) {
        Log::warning('Failed to create notification records', [
            'error' => $e->getMessage()
        ]);
    }
}



    public function holdOrder(Request $request, Order $order)
    {
        $request->validate([
            'hold_reason' => 'required|string|max:255'
        ]);

        $order->update([
            'is_on_hold' => true,
            'hold_reason' => $request->hold_reason,
            'hold_set_by' => auth()->id(),
            'pre_hold_state' => $order->status
        ]);

        return response()->json(['message' => 'Order put on hold']);
    }

    public function resumeOrder(Order $order)
    {
        $order->update([
            'is_on_hold' => false,
            'hold_reason' => null,
            'hold_set_by' => null,
            'status' => $order->pre_hold_state ?? $order->status,
            'pre_hold_state' => null
        ]);

        return response()->json(['message' => 'Order resumed']);
    }

    public function getOrderDetails(Order $order)
    {
        $order->load([
            'project:id,name,code',
            'workAssignments.user:id,name,email',
            'workItems',
            'rejectedBy:id,name',
        ]);

        return response()->json([
            'order' => $order
        ]);
    }

    public function getOrderTimeline(Order $order)
    {
        $assignments = $order->workAssignments()
            ->with('user:id,name')
            ->orderBy('assigned_at')
            ->get()
            ->map(function($assignment) {
                return [
                    'id' => $assignment->id,
                    'stage' => $assignment->layer,
                    'user_name' => $assignment->user->name,
                    'assigned_at' => $assignment->assigned_at,
                    'started_at' => $assignment->started_at,
                    'completed_at' => $assignment->completed_at,
                    'status' => $assignment->status,
                    'time_spent' => $assignment->started_at && $assignment->completed_at
                        ? $assignment->started_at->diffInMinutes($assignment->completed_at) . ' minutes'
                        : null,
                ];
            });

        return response()->json([
            'data' => $assignments
        ]);
    }

    public function getQueueHealth(Request $request)
    {
        $projectId = $request->project_id;
        
        $stages = ['draw', 'check', 'qa'];
        $health = [];

        foreach ($stages as $stage) {
            $layerMap = [
                'draw' => 'drawer',
                'check' => 'checker',
                'qa' => 'qa'
            ];
            $dbLayer = $layerMap[$stage];

            $health[$stage] = [
                'queued' => Order::where('current_layer', $dbLayer)
                    ->when($projectId, fn($q) => $q->where('project_id', $projectId))
                    ->where('status', 'pending')
                    ->count(),
                'in_progress' => Order::where('current_layer', $dbLayer)
                    ->when($projectId, fn($q) => $q->where('project_id', $projectId))
                    ->where('status', 'in-progress')
                    ->count(),
                'avg_time_minutes' => $this->getAverageStageTime($stage, $projectId),
                'completed_today' => $this->getStageCompletedToday($stage, $projectId),
                'rejected_today' => $this->getStageRejectedToday($stage, $projectId),
            ];
        }

        return response()->json($health);
    }
}