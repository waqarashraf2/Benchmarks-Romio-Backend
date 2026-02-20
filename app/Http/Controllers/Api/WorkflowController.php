<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WorkItem;
use App\Models\Project;
use App\Models\User;
use App\Services\StateMachine;
use App\Services\AssignmentEngine;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowController extends Controller
{


    public function bulkAssign(Request $request)
{
    $request->validate([
        'drawer_id' => 'required|exists:users,id',
        'order_ids' => 'required|array|min:1',
        'order_ids.*' => 'exists:orders,id',
        'project_id' => 'nullable|exists:projects,id'
    ]);

    $drawer = User::findOrFail($request->drawer_id);
    
    // Verify drawer is active and not absent
    if (!$drawer->is_active || $drawer->is_absent) {
        return response()->json(['error' => 'Drawer is not available for assignment'], 422);
    }

    $assigned = [];
    $errors = [];

    DB::transaction(function () use ($request, $drawer, &$assigned, &$errors) {
        foreach ($request->order_ids as $orderId) {
            $order = Order::where('id', $orderId)
                ->whereNull('assigned_to')
                ->lockForUpdate()
                ->first();

            if (!$order) {
                $errors[] = "Order {$orderId} is no longer available";
                continue;
            }

            // Verify order is in correct state
            if (!in_array($order->workflow_state, ['QUEUED_DRAW', 'REJECTED_BY_CHECK', 'REJECTED_BY_QA'])) {
                $errors[] = "Order {$order->order_number} is not available for assignment";
                continue;
            }

            // Check drawer's WIP cap
            $project = $order->project;
            $wipCap = $project->wip_cap ?? 1;
            $currentWip = Order::where('assigned_to', $drawer->id)
                ->whereIn('workflow_state', ['IN_DRAW', 'IN_DESIGN'])
                ->count();

            if ($currentWip >= $wipCap) {
                $errors[] = "Drawer has reached WIP capacity";
                break;
            }

            // Assign the order
            $order->update([
                'assigned_to' => $drawer->id,
                'team_id' => $drawer->team_id,
                'workflow_state' => 'IN_DRAW'
            ]);

            // Create work item
            WorkItem::create([
                'order_id' => $order->id,
                'project_id' => $order->project_id,
                'stage' => 'DRAW',
                'assigned_user_id' => $drawer->id,
                'team_id' => $drawer->team_id,
                'status' => 'in_progress',
                'assigned_at' => now(),
                'started_at' => now(),
                'attempt_number' => $order->attempt_draw + 1,
            ]);

            // Update drawer's WIP count
            $drawer->increment('wip_count');
            
            $assigned[] = $order->order_number;

            // Log the assignment
            ActivityLog::log(
                'bulk_assign',
                Order::class,
                $order->id,
                ['assigned_to' => null],
                ['assigned_to' => $drawer->id, 'assigned_by' => auth()->id()]
            );
        }
    });

    return response()->json([
        'success' => true,
        'assigned' => $assigned,
        'errors' => $errors,
        'message' => count($assigned) . ' orders assigned successfully'
    ]);
}
    // ═══════════════════════════════════════════
    // WORKER ENDPOINTS (Production roles)
    // ═══════════════════════════════════════════

    /**
     * GET /workflow/start-next
     * Auto-assign the next order from the user's queue.
     * No manual picking — this is the ONLY way workers get work.
     */
    public function startNext(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            return response()->json(['message' => 'Only production roles can start work.'], 403);
        }

        if (!$user->project_id) {
            return response()->json(['message' => 'You are not assigned to a project.'], 422);
        }

        // Defense-in-depth: reject if requested project doesn't match user's project
        if ($request->has('project_id') && (int)$request->input('project_id') !== $user->project_id) {
            return response()->json(['message' => 'You can only work on your assigned project.'], 403);
        }

        $order = AssignmentEngine::startNext($user);

        if (!$order) {
            return response()->json([
                'message' => 'No orders available in your queue, or you are at max WIP capacity.',
                'queue_empty' => true,
            ]);
        }

        NotificationService::orderAssigned($order, $user);

        return response()->json([
            'order' => $order->load(['project', 'team', 'workItems']),
            'message' => 'Order assigned successfully.',
        ]);
    }

    /**
     * GET /workflow/my-current
     * Get the user's currently assigned in-progress order.
     */
    public function myCurrent(Request $request)
    {
        $user = $request->user();

        $order = Order::where('assigned_to', $user->id)
            ->whereIn('workflow_state', ['IN_DRAW', 'IN_CHECK', 'IN_QA', 'IN_DESIGN'])
            ->with(['project', 'team'])
            ->first();

        return response()->json(['order' => $order]);
    }

    /**
     * POST /workflow/orders/{id}/submit
     * Submit completed work to the next stage.
     */
    public function submitWork(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        // Verify the user is assigned to this order
        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        // Verify order is in an IN_ state
        if (!str_starts_with($order->workflow_state, 'IN_')) {
            return response()->json(['message' => 'Order is not in a workable state.'], 422);
        }

        // Check project isolation
        if ($order->project_id !== $user->project_id) {
            return response()->json(['message' => 'Project isolation violation.'], 403);
        }

        $comments = $request->input('comments');
        $order = AssignmentEngine::submitWork($order, $user, $comments);

        NotificationService::workSubmitted($order, $user);

        return response()->json([
            'order' => $order,
            'message' => 'Work submitted successfully.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/reject
     * Reject an order (checker/QA only) with mandatory reason.
     */
    public function rejectOrder(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'required|string|min:5',
            'rejection_code' => 'required|string|in:quality,incomplete,wrong_specs,rework,formatting,missing_info',
            'route_to' => 'nullable|string|in:draw,check,design',
        ]);

        $user = $request->user();
        $order = Order::findOrFail($id);

        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        if (!in_array($user->role, ['checker', 'qa'])) {
            return response()->json(['message' => 'Only checkers and QA can reject orders.'], 403);
        }

        if (!in_array($order->workflow_state, ['IN_CHECK', 'IN_QA'])) {
            return response()->json(['message' => 'Order is not in a rejectable state.'], 422);
        }

        $order = AssignmentEngine::rejectOrder(
            $order,
            $user,
            $request->input('reason'),
            $request->input('rejection_code'),
            $request->input('route_to')
        );

        NotificationService::orderRejected($order, $user, $request->input('reason'));

        return response()->json([
            'order' => $order,
            'message' => 'Order rejected and returned to queue.',
        ]);
    }
    public function worker(Request $request)
{
    $user = $request->user();

    // Temporarily include all assigned orders, not just IN_* states
    $currentOrder = Order::where('assigned_to', $user->id)
        // ->whereIn('workflow_state', ['IN_DRAW', 'IN_CHECK', 'IN_QA', 'IN_DESIGN'])
        ->with('project:id,name,code')
        ->first();

    // ... rest of the code
}

    /**
     * POST /workflow/orders/{id}/hold
     * Place an order on hold (checker/QA/ops only).
     */
    public function holdOrder(Request $request, int $id)
    {
        $request->validate([
            'hold_reason' => 'required|string|min:3',
        ]);

        $user = $request->user();
        $order = Order::findOrFail($id);

        if (!in_array($user->role, StateMachine::HOLD_ALLOWED_ROLES)) {
            return response()->json(['message' => 'You are not allowed to place orders on hold.'], 403);
        }

        if (!StateMachine::canTransition($order, 'ON_HOLD')) {
            return response()->json(['message' => 'Cannot put this order on hold from its current state.'], 422);
        }

        DB::transaction(function () use ($order, $user, $request) {
            // Save the current state so we can resume to it later
            $order->update(['pre_hold_state' => $order->workflow_state]);

            // If user had this assigned, release it
            if ($order->assigned_to === $user->id) {
                $user->decrement('wip_count');
            }

            StateMachine::transition($order, 'ON_HOLD', $user->id, [
                'hold_reason' => $request->input('hold_reason'),
            ]);
        });

        NotificationService::orderOnHold($order, $user, $request->input('hold_reason'));

        return response()->json([
            'order' => $order->fresh(),
            'message' => 'Order placed on hold.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/resume
     * Resume an order from ON_HOLD.
     */
    public function resumeOrder(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        if ($order->workflow_state !== 'ON_HOLD') {
            return response()->json(['message' => 'Order is not on hold.'], 422);
        }

        if (!in_array($user->role, ['operations_manager', 'director', 'ceo'])) {
            return response()->json(['message' => 'Only managers can resume held orders.'], 403);
        }

        // Determine which queue to return to based on what state it was in before hold
        $preHoldState = $order->pre_hold_state;
        if ($preHoldState && str_starts_with($preHoldState, 'IN_')) {
            // Was actively being worked on — return to queue for that stage
            $queueState = str_replace('IN_', 'QUEUED_', $preHoldState);
        } elseif ($preHoldState && str_starts_with($preHoldState, 'QUEUED_')) {
            // Was already in queue — return there
            $queueState = $preHoldState;
        } else {
            // Fallback: determine from workflow type
            $queueState = $order->workflow_type === 'PH_2_LAYER' ? 'QUEUED_DESIGN' : 'QUEUED_DRAW';
        }

        DB::transaction(function () use ($order, $queueState, $user) {
            StateMachine::transition($order, $queueState, $user->id, ['resumed_from_hold' => true]);
            $order->update(['pre_hold_state' => null]);
        });

        NotificationService::orderResumed($order, $user);

        return response()->json([
            'order' => $order->fresh(),
            'message' => 'Order resumed.',
        ]);
    }

    /**
     * GET /workflow/my-stats
     * Worker's today stats: completed, target, time.
     */
    public function myStats(Request $request)
    {
        $user = $request->user();

        $todayCompleted = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $queueCount = 0;
        if ($user->project_id && in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            $project = $user->project;
            $queueStates = StateMachine::getQueuedStates($project->workflow_type ?? 'FP_3_LAYER');
            $roleQueueState = collect($queueStates)->first(function ($state) use ($user) {
                $role = StateMachine::getRoleForState($state);
                return $role === $user->role;
            });
            if ($roleQueueState) {
                $queueCount = Order::where('project_id', $user->project_id)
                    ->where('workflow_state', $roleQueueState)
                    ->count();
            }
        }

        return response()->json([
            'today_completed' => $todayCompleted,
            'daily_target' => $user->daily_target ?? 0,
            'wip_count' => $user->wip_count,
            'queue_count' => $queueCount,
            'is_absent' => $user->is_absent,
        ]);
    }

    /**
     * GET /workflow/my-queue
     * Worker's orders in queue (assigned or waiting for their role).
     */
    public function myQueue(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            return response()->json(['message' => 'Only production roles have a queue.'], 403);
        }

        if (!$user->project_id) {
            return response()->json(['orders' => []]);
        }

        $project = $user->project;
        $queueStates = StateMachine::getQueuedStates($project->workflow_type ?? 'FP_3_LAYER');
        
        // Find the queue state for this user's role
        $roleQueueState = collect($queueStates)->first(function ($state) use ($user) {
            $role = StateMachine::getRoleForState($state);
            return $role === $user->role;
        });
        
        // Also include orders currently assigned to this user
        $inProgressStates = ['IN_DRAW', 'IN_CHECK', 'IN_QA', 'IN_DESIGN'];
        $roleInProgressState = collect($inProgressStates)->first(function ($state) use ($user) {
            $role = StateMachine::getRoleForState($state);
            return $role === $user->role;
        });

        $orders = Order::where('project_id', $user->project_id)
            ->where(function ($query) use ($roleQueueState, $roleInProgressState, $user) {
                $query->where('workflow_state', $roleQueueState)
                    ->orWhere(function ($q) use ($roleInProgressState, $user) {
                        $q->where('workflow_state', $roleInProgressState)
                          ->where('assigned_to', $user->id);
                    });
            })
            ->with(['project', 'team'])
            ->orderBy('priority', 'asc')
            ->orderBy('due_date', 'asc')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /workflow/my-completed
     * Worker's completed orders today.
     */
    public function myCompleted(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            return response()->json(['message' => 'Only production roles have completed orders.'], 403);
        }

        // Get orders where this user completed work today
        $completedOrderIds = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->pluck('order_id')
            ->unique();

        $orders = Order::whereIn('id', $completedOrderIds)
            ->with(['project', 'team'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    /**
     * GET /workflow/my-history
     * Worker's order history (all time, paginated).
     */
    public function myHistory(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            return response()->json(['message' => 'Only production roles have history.'], 403);
        }

        $completedOrderIds = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->pluck('order_id')
            ->unique();

        $orders = Order::whereIn('id', $completedOrderIds)
            ->with(['project', 'team'])
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * GET /workflow/my-performance
     * Worker's performance stats (daily/weekly completion rates).
     */
    public function myPerformance(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            return response()->json(['message' => 'Only production roles have performance stats.'], 403);
        }

        $todayCompleted = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $weekCompleted = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfWeek())
            ->count();

        $monthCompleted = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        // Daily breakdown for last 7 days
        $dailyStats = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $count = WorkItem::where('assigned_user_id', $user->id)
                ->where('status', 'completed')
                ->whereDate('completed_at', $date)
                ->count();
            $dailyStats[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'count' => $count,
            ];
        }

        // Average time per order
        $avgTimeSeconds = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->where('time_spent_seconds', '>', 0)
            ->avg('time_spent_seconds') ?? 0;

        // Completion rate (vs target)
        $weeklyTarget = ($user->daily_target ?? 0) * 5;
        $weeklyRate = $weeklyTarget > 0 ? round(($weekCompleted / $weeklyTarget) * 100, 1) : 100;

        return response()->json([
            'today_completed' => $todayCompleted,
            'week_completed' => $weekCompleted,
            'month_completed' => $monthCompleted,
            'daily_target' => $user->daily_target ?? 0,
            'weekly_target' => $weeklyTarget,
            'weekly_rate' => $weeklyRate,
            'avg_time_minutes' => round($avgTimeSeconds / 60, 1),
            'daily_stats' => $dailyStats,
        ]);
    }

    /**
     * POST /workflow/orders/{id}/reassign-queue
     * Worker reassigns order back to queue (unassigns from self).
     */
    public function reassignToQueue(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        $reason = $request->input('reason', 'Released by worker');

        // Determine which queue state to return to
        $currentState = $order->workflow_state;
        $queueState = match($currentState) {
            'IN_DRAW' => 'QUEUED_DRAW',
            'IN_CHECK' => 'QUEUED_CHECK',
            'IN_QA' => 'QUEUED_QA',
            'IN_DESIGN' => 'QUEUED_DESIGN',
            default => null,
        };

        if (!$queueState) {
            return response()->json(['message' => 'Cannot release from current state.'], 422);
        }

        // Release the order
        $order->update([
            'workflow_state' => $queueState,
            'assigned_to' => null,
        ]);

        $user->decrement('wip_count');

        // Log the action
        AuditService::log($user->id, 'order_released', 'Order', $order->id, $order->project_id, [
            'reason' => $reason,
            'previous_state' => $currentState,
        ]);

        return response()->json([
            'order' => $order->fresh(['project', 'team']),
            'message' => 'Order released back to queue.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/flag-issue
     * Worker flags an issue on an order.
     */
    public function flagIssue(Request $request, int $id)
    {
        $request->validate([
            'flag_type' => 'required|string|in:quality,missing_info,wrong_specs,unclear_instructions,file_issue,other',
            'description' => 'required|string|min:5',
            'severity' => 'nullable|string|in:low,medium,high',
        ]);

        $user = $request->user();
        $order = Order::findOrFail($id);

        // Verify user is working on this order or is a supervisor
        if ($order->assigned_to !== $user->id && !in_array($user->role, ['operations_manager', 'director', 'ceo'])) {
            return response()->json(['message' => 'You cannot flag issues on orders not assigned to you.'], 403);
        }

        $flag = \App\Models\IssueFlag::create([
            'order_id' => $order->id,
            'flagged_by' => $user->id,
            'project_id' => $order->project_id,
            'flag_type' => $request->input('flag_type'),
            'description' => $request->input('description'),
            'severity' => $request->input('severity', 'medium'),
            'status' => 'open',
        ]);

        return response()->json([
            'flag' => $flag->load(['flagger', 'order']),
            'message' => 'Issue flagged successfully.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/request-help
     * Worker requests help/clarification on an order.
     */
    public function requestHelp(Request $request, int $id)
    {
        $request->validate([
            'question' => 'required|string|min:5',
        ]);

        $user = $request->user();
        $order = Order::findOrFail($id);

        // Verify user is working on this order
        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'You cannot request help on orders not assigned to you.'], 403);
        }

        $helpRequest = \App\Models\HelpRequest::create([
            'order_id' => $order->id,
            'requested_by' => $user->id,
            'project_id' => $order->project_id,
            'question' => $request->input('question'),
            'status' => 'pending',
        ]);

        // TODO: Notify supervisors

        return response()->json([
            'help_request' => $helpRequest->load(['requester', 'order']),
            'message' => 'Help request submitted.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/timer/start
     * Start work timer for an order.
     */
    public function startTimer(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        // Find or create work item
        $workItem = WorkItem::where('order_id', $order->id)
            ->where('assigned_user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if (!$workItem) {
            return response()->json(['message' => 'No active work item found.'], 404);
        }

        $workItem->update(['last_timer_start' => now()]);

        return response()->json([
            'work_item' => $workItem,
            'message' => 'Timer started.',
        ]);
    }

    /**
     * POST /workflow/orders/{id}/timer/stop
     * Stop work timer and record time.
     */
    public function stopTimer(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        if ($order->assigned_to !== $user->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }

        $workItem = WorkItem::where('order_id', $order->id)
            ->where('assigned_user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if (!$workItem || !$workItem->last_timer_start) {
            return response()->json(['message' => 'Timer not running.'], 422);
        }

        $elapsed = now()->diffInSeconds($workItem->last_timer_start);
        $workItem->update([
            'time_spent_seconds' => $workItem->time_spent_seconds + $elapsed,
            'last_timer_start' => null,
        ]);

        return response()->json([
            'work_item' => $workItem,
            'time_added_seconds' => $elapsed,
            'total_time_seconds' => $workItem->time_spent_seconds,
            'message' => 'Timer stopped.',
        ]);
    }

    /**
     * GET /workflow/orders/{id}/details
     * Get full order details including supervisor notes, attachments, flags, help requests.
     */
    public function orderFullDetails(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::with([
            'project',
            'team',
            'workItems.assignedUser',
        ])->findOrFail($id);

        // Get help requests for this order
        $helpRequests = \App\Models\HelpRequest::where('order_id', $order->id)
            ->with(['requester', 'responder'])
            ->get();

        // Get issue flags for this order
        $issueFlags = \App\Models\IssueFlag::where('order_id', $order->id)
            ->with(['flagger', 'resolver'])
            ->get();

        // Current work item time tracking
        $currentWorkItem = WorkItem::where('order_id', $order->id)
            ->where('assigned_user_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        $currentTimeSeconds = 0;
        if ($currentWorkItem) {
            $currentTimeSeconds = $currentWorkItem->time_spent_seconds;
            if ($currentWorkItem->last_timer_start) {
                $currentTimeSeconds += now()->diffInSeconds($currentWorkItem->last_timer_start);
            }
        }

        return response()->json([
            'order' => $order,
            'supervisor_notes' => $order->supervisor_notes,
            'attachments' => $order->attachments ?? [],
            'help_requests' => $helpRequests,
            'issue_flags' => $issueFlags,
            'current_time_seconds' => $currentTimeSeconds,
            'timer_running' => $currentWorkItem?->last_timer_start !== null,
        ]);
    }

    // ═══════════════════════════════════════════
    // MANAGEMENT ENDPOINTS (Ops/Director/CEO)
    // ═══════════════════════════════════════════

    /**
     * GET /workflow/{projectId}/queue-health
     * Queue health for a project: counts per state, oldest item, SLA breaches.
     */
    public function queueHealth(Request $request, int $projectId)
    {
        $project = Project::findOrFail($projectId);

        $states = $project->workflow_type === 'PH_2_LAYER'
            ? StateMachine::PH_STATES
            : StateMachine::FP_STATES;

        $counts = [];
        foreach ($states as $state) {
            $query = Order::where('project_id', $projectId)->where('workflow_state', $state);
            $counts[$state] = [
                'count' => $query->count(),
                'oldest' => $query->min('received_at'),
            ];
        }

        // SLA breaches (orders past due_date)
        $slaBreaches = Order::where('project_id', $projectId)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->count();

        return response()->json([
            'project_id' => $projectId,
            'workflow_type' => $project->workflow_type,
            'state_counts' => $counts,
            'sla_breaches' => $slaBreaches,
            'total_pending' => Order::where('project_id', $projectId)
                ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
                ->count(),
            'total_delivered' => Order::where('project_id', $projectId)
                ->where('workflow_state', 'DELIVERED')
                ->count(),
        ]);
    }

    /**
     * GET /workflow/{projectId}/staffing
     * Staffing overview for a project.
     */
    public function staffing(Request $request, int $projectId)
    {
        $project = Project::findOrFail($projectId);

        $stages = StateMachine::getStages($project->workflow_type);
        $staffing = [];

        foreach ($stages as $stage) {
            $role = StateMachine::STAGE_TO_ROLE[$stage];
            $users = \App\Models\User::where('project_id', $projectId)
                ->where('role', $role)
                ->get(['id', 'name', 'role', 'team_id', 'is_active', 'is_absent', 'wip_count', 'today_completed', 'last_activity', 'daily_target']);

            $staffing[$stage] = [
                'role' => $role,
                'total' => $users->count(),
                'active' => $users->where('is_active', true)->where('is_absent', false)->count(),
                'absent' => $users->where('is_absent', true)->count(),
                'users' => $users,
            ];
        }

        return response()->json([
            'project_id' => $projectId,
            'staffing' => $staffing,
        ]);
    }

    /**
     * POST /workflow/orders/{id}/reassign
     * Manually reassign an order (management only).
     */
    public function reassignOrder(Request $request, int $id)
    {
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'reason' => 'required|string',
        ]);

        $actor = $request->user();
        $order = Order::findOrFail($id);

        $oldAssignee = $order->assigned_to;

        // If reassigning to null, return to queue
        if (!$request->input('user_id')) {
            $queueState = str_replace('IN_', 'QUEUED_', $order->workflow_state);
            if (str_starts_with($order->workflow_state, 'IN_')) {
                DB::transaction(function () use ($order, $oldAssignee, $queueState, $actor, $request) {
                    // Abandon current work item
                    WorkItem::where('order_id', $order->id)
                        ->where('assigned_user_id', $oldAssignee)
                        ->where('status', 'in_progress')
                        ->update(['status' => 'abandoned', 'completed_at' => now()]);

                    if ($oldAssignee) {
                        \App\Models\User::where('id', $oldAssignee)->decrement('wip_count');
                    }

                    StateMachine::transition($order, $queueState, $actor->id, [
                        'reason' => $request->input('reason'),
                    ]);
                });
            }
        } else {
            $newUser = \App\Models\User::findOrFail($request->input('user_id'));
            
            DB::transaction(function () use ($order, $oldAssignee, $newUser, $actor, $request) {
                // Decrement old user's WIP
                if ($oldAssignee) {
                    \App\Models\User::where('id', $oldAssignee)->decrement('wip_count');
                }
                
                // Assign to new user and increment their WIP
                $order->update(['assigned_to' => $newUser->id, 'team_id' => $newUser->team_id]);
                $newUser->increment('wip_count');

                AuditService::logAssignment(
                    $order->id,
                    $order->project_id,
                    $oldAssignee,
                    $newUser->id,
                    $request->input('reason')
                );
            });
        }

        return response()->json([
            'order' => $order->fresh(),
            'message' => 'Order reassigned.',
        ]);
    }

    /**
     * POST /workflow/receive
     * Receive a new order into the system (creates in RECEIVED state).
     */
    public function receiveOrder(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'client_reference' => 'required|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'due_date' => 'nullable|date',
            'metadata' => 'nullable|array',
        ]);

        $project = Project::findOrFail($request->input('project_id'));

        // Idempotency check: client_reference + project
        $existing = Order::where('project_id', $project->id)
            ->where('client_reference', $request->input('client_reference'))
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Duplicate order: this client reference already exists for this project.',
                'existing_order' => $existing,
            ], 409);
        }

        $order = DB::transaction(function () use ($request, $project) {
            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'project_id' => $project->id,
                'client_reference' => $request->input('client_reference'),
                'workflow_state' => 'RECEIVED',
                'workflow_type' => $project->workflow_type,
                'current_layer' => $project->workflow_type === 'PH_2_LAYER' ? 'designer' : 'drawer',
                'status' => 'pending',
                'priority' => $request->input('priority', 'normal'),
                'due_date' => $request->input('due_date'),
                'received_at' => now(),
                'metadata' => $request->input('metadata'),
            ]);

            // Auto-advance to first queue
            $firstQueue = $project->workflow_type === 'PH_2_LAYER' ? 'QUEUED_DESIGN' : 'QUEUED_DRAW';
            StateMachine::transition($order, $firstQueue, auth()->id());

            return $order;
        });

        NotificationService::orderReceived($order, auth()->user());

        return response()->json([
            'order' => $order->fresh(),
            'message' => 'Order received and queued.',
        ], 201);
    }

    /**
     * GET /workflow/orders/{id}
     * Get order details with role-based field visibility.
     */
    public function orderDetails(Request $request, int $id)
    {
        $user = $request->user();
        $order = Order::with(['project', 'team', 'assignedUser', 'workItems.assignedUser'])->findOrFail($id);

        // Project isolation check for production users
        if (in_array($user->role, ['drawer', 'checker', 'qa', 'designer'])) {
            if ($order->project_id !== $user->project_id) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
            // Workers can only see their own assigned orders
            if ($order->assigned_to !== $user->id) {
                return response()->json(['message' => 'Access denied.'], 403);
            }
        }

        // Role-based field filtering
        $data = $this->filterOrderFieldsByRole($order, $user->role);

        return response()->json(['order' => $data]);
    }

    /**
     * GET /workflow/{projectId}/orders
     * List orders for a project with filters.
     */
    public function projectOrders(Request $request, int $projectId)
    {
        $query = Order::where('project_id', $projectId)
            ->with(['assignedUser:id,name,role', 'team:id,name']);

        $user = $request->user();
        if (!in_array($user->role, ['ceo', 'director'])) {
            if ($user->project_id && $user->project_id != $projectId) {
                return response()->json(['message' => 'Access denied to this project.'], 403);
            }
        }

        if ($request->has('state')) {
            $query->where('workflow_state', $request->input('state'));
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->input('assigned_to'));
        }
        if ($request->has('team_id')) {
            $query->where('team_id', $request->input('team_id'));
        }

        $orders = $query->orderBy('received_at', 'desc')->paginate(50);

        return response()->json($orders);
    }

    /**
     * GET /workflow/work-items/{orderId}
     * Get all work items (per-stage history) for an order.
     */
    public function workItemHistory(int $orderId)
    {
        $items = WorkItem::where('order_id', $orderId)
            ->with('assignedUser:id,name,role')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['work_items' => $items]);
    }

    // ═══════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════

    /**
     * Filter order fields based on user role.
     * Backend enforces role-based data — not just UI hiding.
     */
    private function filterOrderFieldsByRole(Order $order, string $role): array
    {
        $base = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'client_reference' => $order->client_reference,
            'workflow_state' => $order->workflow_state,
            'priority' => $order->priority,
            'due_date' => $order->due_date,
            'received_at' => $order->received_at,
            'project' => $order->project ? ['id' => $order->project->id, 'name' => $order->project->name, 'code' => $order->project->code] : null,
            'team' => $order->team ? ['id' => $order->team->id, 'name' => $order->team->name] : null,
        ];

        // Drawer/Designer: instructions, specs, assets
        if (in_array($role, ['drawer', 'designer'])) {
            $base['metadata'] = $order->metadata; // Contains specs/instructions
            $base['attempt_draw'] = $order->attempt_draw;
            $base['rejection_reason'] = $order->rejection_reason; // So they know what to fix
            $base['rejection_type'] = $order->rejection_type;
            return $base;
        }

        // Checker: expected vs produced, error points, delta checklist
        if ($role === 'checker') {
            $base['metadata'] = $order->metadata;
            $base['attempt_draw'] = $order->attempt_draw;
            $base['attempt_check'] = $order->attempt_check;
            $base['rejection_reason'] = $order->rejection_reason;
            $base['rejection_type'] = $order->rejection_type;
            $base['recheck_count'] = $order->recheck_count;
            $base['work_items'] = $order->workItems->where('stage', 'DRAW')->values();
            return $base;
        }

        // QA: final checklist + rejection history
        if ($role === 'qa') {
            $base['metadata'] = $order->metadata;
            $base['attempt_draw'] = $order->attempt_draw;
            $base['attempt_check'] = $order->attempt_check;
            $base['attempt_qa'] = $order->attempt_qa;
            $base['rejection_reason'] = $order->rejection_reason;
            $base['rejection_type'] = $order->rejection_type;
            $base['recheck_count'] = $order->recheck_count;
            $base['work_items'] = $order->workItems; // Full history for QA
            return $base;
        }

        // Management: everything
        $base = $order->toArray();
        $base['work_items'] = $order->workItems;
        return $base;
    }
}
