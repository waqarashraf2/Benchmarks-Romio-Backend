<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\StateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /dashboard/master
     * CEO/Director: Org → Country → Department → Project drilldown.
     */
    public function master(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['ceo', 'director'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // BULK LOAD all data up front to avoid N+1 queries
        $activeProjects = Project::where('status', 'active')->get();
        $allProjectIds = $activeProjects->pluck('id');
        
        // Bulk load all order counts by project + state
        $orderCounts = Order::whereIn('project_id', $allProjectIds)
            ->selectRaw('project_id, workflow_state, COUNT(*) as cnt')
            ->groupBy('project_id', 'workflow_state')
            ->get()
            ->groupBy('project_id');

        $deliveredToday = Order::whereIn('project_id', $allProjectIds)
            ->where('workflow_state', 'DELIVERED')
            ->whereDate('delivered_at', today())
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $receivedToday = Order::whereIn('project_id', $allProjectIds)
            ->whereDate('received_at', today())
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        $slaBreaches = Order::whereIn('project_id', $allProjectIds)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->selectRaw('project_id, COUNT(*) as cnt')
            ->groupBy('project_id')
            ->pluck('cnt', 'project_id');

        // Bulk load all staff
        $allStaff = User::whereIn('project_id', $allProjectIds)->where('is_active', true)->get();
        $staffByProject = $allStaff->groupBy('project_id');

        $countries = $activeProjects->groupBy('country');
        $summary = [];

        foreach ($countries as $country => $countryProjects) {
            $countryProjectIds = $countryProjects->pluck('id');

            $departments = [];
            foreach ($countryProjects->groupBy('department') as $dept => $deptProjects) {
                $deptProjectIds = $deptProjects->pluck('id');

                $deptTotalOrders = 0;
                $deptPending = 0;
                foreach ($deptProjectIds as $pid) {
                    $projectOrders = $orderCounts->get($pid, collect());
                    $deptTotalOrders += $projectOrders->sum('cnt');
                    $deptPending += $projectOrders->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->sum('cnt');
                }

                $deptData = [
                    'department' => $dept,
                    'project_count' => $deptProjects->count(),
                    'total_orders' => $deptTotalOrders,
                    'delivered_today' => $deptProjectIds->sum(fn($pid) => $deliveredToday->get($pid, 0)),
                    'pending' => $deptPending,
                    'sla_breaches' => $deptProjectIds->sum(fn($pid) => $slaBreaches->get($pid, 0)),
                    'projects' => $deptProjects->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                        'workflow_type' => $p->workflow_type,
                        'pending' => $orderCounts->get($p->id, collect())
                            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->sum('cnt'),
                        'delivered_today' => $deliveredToday->get($p->id, 0),
                    ]),
                ];
                $departments[] = $deptData;
            }

            $countryStaff = $staffByProject->filter(fn($v, $k) => $countryProjectIds->contains($k))->flatten();
            $totalStaff = $countryStaff->count();
            $activeStaff = $countryStaff->filter(fn($u) => !$u->is_absent && $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count();
            $absentStaff = $countryStaff->where('is_absent', true)->count();

            $summary[] = [
                'country' => $country,
                'project_count' => $countryProjects->count(),
                'total_staff' => $totalStaff,
                'active_staff' => $activeStaff,
                'absent_staff' => $absentStaff,
                'received_today' => $countryProjectIds->sum(fn($pid) => $receivedToday->get($pid, 0)),
                'delivered_today' => $countryProjectIds->sum(fn($pid) => $deliveredToday->get($pid, 0)),
                'total_pending' => $orderCounts->filter(fn($v, $k) => $countryProjectIds->contains($k))
                    ->flatten()->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->sum('cnt'),
                'departments' => $departments,
            ];
        }

        // Productivity & Overtime Analysis (per CEO requirements)
        $standardShiftHours = 9; // 9-hour shift per requirements
        
        // Calculate overtime/undertime based on work items (bulk loaded)
        $todayWorkItems = WorkItem::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->selectRaw('assigned_user_id, COUNT(*) as cnt')
            ->groupBy('assigned_user_id')
            ->pluck('cnt', 'assigned_user_id');
        
        $usersWithOvertime = 0;
        $usersUnderTarget = 0;
        $totalTargetAchieved = 0;
        $totalStaffWithTargets = 0;
        
        foreach ($allStaff as $staff) {
            if ($staff->daily_target > 0) {
                $totalStaffWithTargets++;
                $todayCompleted = $todayWorkItems->get($staff->id, 0);
                if ($todayCompleted >= $staff->daily_target) {
                    $totalTargetAchieved++;
                }
                // Overtime: completed more than 120% of target
                if ($todayCompleted > ($staff->daily_target * 1.2)) {
                    $usersWithOvertime++;
                }
                // Under-target: completed less than 80% of target
                if ($todayCompleted < ($staff->daily_target * 0.8)) {
                    $usersUnderTarget++;
                }
            }
        }
        
        $targetHitRate = $totalStaffWithTargets > 0 
            ? round(($totalTargetAchieved / $totalStaffWithTargets) * 100, 1) 
            : 0;

        // Org-wide totals (reuse already-loaded data)
        $orgTotals = [
            'total_projects' => $activeProjects->count(),
            'total_staff' => $allStaff->count(),
            'active_staff' => $allStaff->filter(fn($u) => !$u->is_absent && $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count(),
            'absentees' => $allStaff->where('is_absent', true)->count(),
            // Inactive users flagged (15+ days) per CEO requirements
            'inactive_flagged' => User::where('is_active', true)
                ->where('inactive_days', '>=', 15)->count(),
            'orders_received_today' => Order::whereDate('received_at', today())->count(),
            'orders_delivered_today' => Order::where('workflow_state', 'DELIVERED')
                ->whereDate('delivered_at', today())->count(),
            'orders_received_week' => Order::where('received_at', '>=', now()->startOfWeek())->count(),
            'orders_delivered_week' => Order::where('workflow_state', 'DELIVERED')
                ->where('delivered_at', '>=', now()->startOfWeek())->count(),
            'orders_received_month' => Order::where('received_at', '>=', now()->startOfMonth())->count(),
            'orders_delivered_month' => Order::where('workflow_state', 'DELIVERED')
                ->where('delivered_at', '>=', now()->startOfMonth())->count(),
            'total_pending' => Order::whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->count(),
            // Overtime/Productivity Analysis per CEO requirements
            'standard_shift_hours' => $standardShiftHours,
            'staff_with_overtime' => $usersWithOvertime,
            'staff_under_target' => $usersUnderTarget,
            'target_hit_rate' => $targetHitRate,
            'staff_achieved_target' => $totalTargetAchieved,
            'staff_with_targets' => $totalStaffWithTargets,
        ];

        return response()->json([
            'org_totals' => $orgTotals,
            'countries' => $summary,
        ]);
    }

    /**
     * GET /dashboard/project/{id}
     * Project dashboard: queue health, staffing, performance.
     */
    public function project(Request $request, int $id)
    {
        $project = Project::findOrFail($id);
        $workflowType = $project->workflow_type ?? 'FP_3_LAYER';
        $states = $workflowType === 'PH_2_LAYER' ? StateMachine::PH_STATES : StateMachine::FP_STATES;

        // Queue health: counts per state
        $stateCounts = [];
        foreach ($states as $state) {
            $stateCounts[$state] = Order::where('project_id', $id)
                ->where('workflow_state', $state)->count();
        }

        // Staffing
        $stages = StateMachine::getStages($workflowType);
        $staffing = [];
        foreach ($stages as $stage) {
            $role = StateMachine::STAGE_TO_ROLE[$stage];
            $users = User::where('project_id', $id)->where('role', $role)->get();
            $staffing[$stage] = [
                'required' => $users->count(),
                'active' => $users->where('is_active', true)->where('is_absent', false)->count(),
                'absent' => $users->where('is_absent', true)->count(),
                'online' => $users->filter(fn($u) => $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count(),
            ];
        }

        // Performance: per role completion and target hit rate
        $performance = [];
        foreach ($stages as $stage) {
            $role = StateMachine::STAGE_TO_ROLE[$stage];
            $users = User::where('project_id', $id)->where('role', $role)->where('is_active', true)->get();
            $totalTarget = $users->sum('daily_target');
            $totalCompleted = WorkItem::where('project_id', $id)
                ->where('stage', $stage)
                ->where('status', 'completed')
                ->whereDate('completed_at', today())
                ->count();

            $performance[$stage] = [
                'today_completed' => $totalCompleted,
                'total_target' => $totalTarget,
                'hit_rate' => $totalTarget > 0 ? round(($totalCompleted / $totalTarget) * 100, 1) : 0,
            ];
        }

        // SLA breaches
        $slaBreaches = Order::where('project_id', $id)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->count();

        return response()->json([
            'project' => $project,
            'state_counts' => $stateCounts,
            'staffing' => $staffing,
            'performance' => $performance,
            'sla_breaches' => $slaBreaches,
            'on_hold' => Order::where('project_id', $id)->where('workflow_state', 'ON_HOLD')->count(),
            'received_today' => Order::where('project_id', $id)->whereDate('received_at', today())->count(),
            'delivered_today' => Order::where('project_id', $id)
                ->where('workflow_state', 'DELIVERED')
                ->whereDate('delivered_at', today())->count(),
        ]);
    }

    /**
     * GET /dashboard/operations
     * Ops Manager: assigned projects overview.
     */
    public function operations(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['ceo', 'director', 'operations_manager', 'manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get projects the ops manager is responsible for
        $projects = $user->project_id
            ? Project::where('id', $user->project_id)->get()
            : Project::where('country', $user->country)->where('status', 'active')->get();

        $projectIds = $projects->pluck('id');

        // Bulk load today's completions to avoid N+1 queries
        $todayCompletions = WorkItem::whereDate('completed_at', today())
            ->where('status', 'completed')
            ->selectRaw('assigned_user_id, COUNT(*) as cnt')
            ->groupBy('assigned_user_id')
            ->pluck('cnt', 'assigned_user_id');

        $data = $projects->map(function ($project) use ($todayCompletions) {
            $pending = Order::where('project_id', $project->id)
                ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->count();
            $deliveredToday = Order::where('project_id', $project->id)
                ->where('workflow_state', 'DELIVERED')
                ->whereDate('delivered_at', today())->count();
            $staff = User::where('project_id', $project->id)
                ->where('is_active', true)->get();
            $activeStaff = $staff->filter(fn($u) => !$u->is_absent && $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count();

            // Queue health per stage
            $workflowType = $project->workflow_type ?? 'FP_3_LAYER';
            $states = $workflowType === 'PH_2_LAYER' ? StateMachine::PH_STATES : StateMachine::FP_STATES;
            $stateCounts = [];
            foreach ($states as $state) {
                $count = Order::where('project_id', $project->id)->where('workflow_state', $state)->count();
                if ($count > 0) {
                    $stateCounts[$state] = $count;
                }
            }

            // Staffing details
            $staffDetails = $staff->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'role' => $s->role,
                'is_online' => $s->last_activity && $s->last_activity->gt(now()->subMinutes(15)),
                'is_absent' => $s->is_absent,
                'wip_count' => $s->wip_count,
                'today_completed' => $todayCompletions->get($s->id, 0),
            ]);

            return [
                'project' => $project->only(['id', 'code', 'name', 'country', 'department', 'workflow_type']),
                'pending' => $pending,
                'delivered_today' => $deliveredToday,
                'total_staff' => $staff->count(),
                'active_staff' => $activeStaff,
                'queue_health' => [
                    'stages' => $stateCounts,
                    'staffing' => $staffDetails,
                ],
            ];
        });

        // Compute totals across all assigned projects
        $allStaff = User::whereIn('project_id', $projectIds)->where('is_active', true)->get();
        $totalActiveStaff = $allStaff->filter(fn($u) => !$u->is_absent && $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count();
        $totalAbsent = $allStaff->where('is_absent', true)->count();
        $totalPending = Order::whereIn('project_id', $projectIds)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])->count();
        $totalDeliveredToday = Order::whereIn('project_id', $projectIds)
            ->where('workflow_state', 'DELIVERED')
            ->whereDate('delivered_at', today())->count();

        // Role-wise completion statistics (reuse todayCompletions)
        $roleStats = [];
        $roles = ['drawer', 'checker', 'qa', 'designer'];
        foreach ($roles as $role) {
            $roleUsers = $allStaff->where('role', $role);
            $roleUserIds = $roleUsers->pluck('id');
            $roleStats[$role] = [
                'total_staff' => $roleUsers->count(),
                'active' => $roleUsers->filter(fn($u) => !$u->is_absent && $u->last_activity && $u->last_activity->gt(now()->subMinutes(15)))->count(),
                'absent' => $roleUsers->where('is_absent', true)->count(),
                'today_completed' => $roleUserIds->sum(fn($uid) => $todayCompletions->get($uid, 0)),
                'total_wip' => $roleUsers->sum('wip_count'),
            ];
        }

        // Date-wise statistics (last 7 days) — bulk load
        $allStaffIds = $allStaff->pluck('id');
        $roleUserIds = [];
        foreach ($roles as $role) {
            $roleUserIds[$role] = $allStaff->where('role', $role)->pluck('id');
        }

        $weekCompletions = WorkItem::whereIn('assigned_user_id', $allStaffIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('assigned_user_id, DATE(completed_at) as completed_date, COUNT(*) as cnt')
            ->groupBy('assigned_user_id', 'completed_date')
            ->get()
            ->groupBy('completed_date');

        $weekReceived = Order::whereIn('project_id', $projectIds)
            ->where('received_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(received_at) as the_date, COUNT(*) as cnt')
            ->groupBy('the_date')
            ->pluck('cnt', 'the_date');

        $weekDelivered = Order::whereIn('project_id', $projectIds)
            ->where('workflow_state', 'DELIVERED')
            ->where('delivered_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(delivered_at) as the_date, COUNT(*) as cnt')
            ->groupBy('the_date')
            ->pluck('cnt', 'the_date');

        $dateStats = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dateLabel = now()->subDays($i)->format('D');
            
            $dayItems = $weekCompletions->get($date, collect());
            $roleCompletions = [];
            foreach ($roles as $role) {
                $roleCompletions[$role] = $dayItems->whereIn('assigned_user_id', $roleUserIds[$role])->sum('cnt');
            }
            
            $dateStats[] = [
                'date' => $date,
                'label' => $dateLabel,
                'received' => $weekReceived->get($date, 0),
                'delivered' => $weekDelivered->get($date, 0),
                'by_role' => $roleCompletions,
            ];
        }

        // Absentees detail
        $absentees = $allStaff->where('is_absent', true)->map(fn($u) => $u->only(['id', 'name', 'role']))->values();

        // Workers list for sidebar (reuse todayCompletions)
        $workers = $allStaff->map(fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'is_active' => $u->is_active,
            'is_absent' => $u->is_absent,
            'wip_count' => $u->wip_count,
            'today_completed' => $todayCompletions->get($u->id, 0),
            'last_activity' => $u->last_activity,
        ])->values();

        return response()->json([
            'projects' => $data,
            'total_active_staff' => $totalActiveStaff,
            'total_absent' => $totalAbsent,
            'total_pending' => $totalPending,
            'total_delivered_today' => $totalDeliveredToday,
            'role_stats' => $roleStats,
            'date_stats' => $dateStats,
            'absentees' => $absentees,
            'workers' => $workers,
        ]);
    }

    /**
     * GET /dashboard/worker
     * Worker's personal dashboard.
     */
    public function worker(Request $request)
    {
        $user = $request->user();

        $currentOrder = Order::where('assigned_to', $user->id)
            ->whereIn('workflow_state', ['IN_DRAW', 'IN_CHECK', 'IN_QA', 'IN_DESIGN'])
            ->with('project:id,name,code')
            ->first();

        $todayCompleted = WorkItem::where('assigned_user_id', $user->id)
            ->where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $queueCount = 0;
        if ($user->project_id) {
            $project = $user->project;
            if ($project) {
                $queueStates = StateMachine::getQueuedStates($project->workflow_type ?? 'FP_3_LAYER');
                foreach ($queueStates as $state) {
                    $role = StateMachine::getRoleForState($state);
                    if ($role === $user->role) {
                        $queueCount = Order::where('project_id', $user->project_id)
                            ->where('workflow_state', $state)->count();
                        break;
                    }
                }
            }
        }

        return response()->json([
            'current_order' => $currentOrder,
            'today_completed' => $todayCompleted,
            'daily_target' => $user->daily_target ?? 0,
            'target_progress' => $user->daily_target > 0
                ? round(($todayCompleted / $user->daily_target) * 100, 1)
                : 0,
            'queue_count' => $queueCount,
            'wip_count' => $user->wip_count,
        ]);
    }

    /**
     * GET /dashboard/absentees
     * List all absentees (org-wide or project-scoped).
     */
    public function absentees(Request $request)
    {
        $user = $request->user();
        $query = User::where('is_active', true)->where('is_absent', true);

        if (!in_array($user->role, ['ceo', 'director','manger'])) {
            if ($user->project_id) {
                $query->where('project_id', $user->project_id);
            }
        }

        return response()->json([
            'absentees' => $query->with('project:id,name,code')->get([
                'id', 'name', 'email', 'role', 'project_id', 'team_id', 'last_activity',
            ]),
        ]);
    }


    public function dailyOperations(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['ceo', 'director','manager'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $date = $request->get('date', today()->format('Y-m-d'));
        
        // Validate date format
        try {
            $dateObj = \Carbon\Carbon::parse($date);
            
            // Don't allow future dates
            if ($dateObj->isFuture()) {
                return response()->json(['message' => 'Cannot view future dates'], 400);
            }
            
            // Don't allow dates too far in the past (optional: 1 year limit)
            if ($dateObj->lt(now()->subYear())) {
                return response()->json(['message' => 'Date too far in the past'], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid date format'], 400);
        }
        
        // Audit log - track CEO viewing sensitive business data
        \App\Models\ActivityLog::log(
            'view_daily_operations',
            'Dashboard',
            null,
            ['date' => $date]
        );

        // Cache for 5 minutes (CEO view - balances freshness vs performance)
        $cacheKey = "daily_operations_{$date}";
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($dateObj) {
            return $this->generateDailyOperationsData($dateObj);
        });

        return response()->json($data);
    }

    /**
     * Internal: Generate daily operations data with optimized queries.
     */
    private function generateDailyOperationsData(\Carbon\Carbon $dateObj)
    {

        // Get all active projects
        $projects = Project::where('status', 'active')
            ->orderBy('country')
            ->orderBy('department')
            ->orderBy('code')
            ->get();

        $projectIds = $projects->pluck('id');

        // BULK LOAD ALL DATA AT ONCE - FIX N+1 PROBLEM
        $allWorkItems = WorkItem::whereIn('project_id', $projectIds)
            ->where('status', 'completed')
            ->whereDate('completed_at', $dateObj)
            ->with(['assignedUser:id,name,email,role', 'order:id,order_number,project_id'])
            ->get()
            ->groupBy('project_id');

        $allDeliveredOrders = Order::whereIn('project_id', $projectIds)
            ->where('workflow_state', 'DELIVERED')
            ->whereDate('delivered_at', $dateObj)
            ->get()
            ->groupBy('project_id');

        $deliveredOrderIds = $allDeliveredOrders->flatten()->pluck('id');
        $allChecklists = \App\Models\OrderChecklist::whereIn('order_id', $deliveredOrderIds)
            ->get()
            ->groupBy('order_id');

        $receivedCounts = Order::whereIn('project_id', $projectIds)
            ->whereDate('received_at', $dateObj)
            ->selectRaw('project_id, COUNT(*) as count')
            ->groupBy('project_id')
            ->pluck('count', 'project_id');

        $pendingCounts = Order::whereIn('project_id', $projectIds)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
            ->selectRaw('project_id, COUNT(*) as count')
            ->groupBy('project_id')
            ->pluck('count', 'project_id');

        $projectsData = [];

        foreach ($projects as $project) {
            $workflowType = $project->workflow_type ?? 'FP_3_LAYER';
            $isFloorPlan = $workflowType === 'FP_3_LAYER';

            $workItems = $allWorkItems->get($project->id, collect());
            $deliveredOrders = $allDeliveredOrders->get($project->id, collect());

            // Group work by stage
            $layerWork = [];
            $stages = $isFloorPlan ? ['DRAW', 'CHECK', 'QA'] : ['DESIGN', 'QA'];

            foreach ($stages as $stage) {
                $stageItems = $workItems->where('stage', $stage);
                $workers = $stageItems->groupBy('assigned_user_id')->map(function ($items) {
                    $user = $items->first()->assignedUser;
                    return [
                        'id' => $user?->id,
                        'name' => $user?->name ?? 'Unknown',
                        'completed' => $items->count(),
                        // Limit to 15 orders to prevent memory bloat
                        'orders' => $items->pluck('order.order_number')->filter()->unique()->take(15)->values(),
                        'has_more' => $items->pluck('order.order_number')->filter()->unique()->count() > 15,
                    ];
                })->values();

                $layerWork[$stage] = [
                    'total' => $stageItems->count(),
                    'workers' => $workers,
                ];
            }

            // QA checklist statistics
            $checklistStats = [
                'total_orders' => $deliveredOrders->count(),
                'total_items' => 0,
                'completed_items' => 0,
                'mistake_count' => 0,
                'compliance_rate' => 0,
            ];

            if ($deliveredOrders->count() > 0) {
                $orderIds = $deliveredOrders->pluck('id');
                $projectChecklists = $allChecklists->filter(function ($items, $orderId) use ($orderIds) {
                    return $orderIds->contains($orderId);
                })->flatten();

                $checklistStats['total_items'] = $projectChecklists->count();
                $checklistStats['completed_items'] = $projectChecklists->where('is_checked', true)->count();
                $checklistStats['mistake_count'] = $projectChecklists->sum('mistake_count');
                $checklistStats['compliance_rate'] = $checklistStats['total_items'] > 0
                    ? round(($checklistStats['completed_items'] / $checklistStats['total_items']) * 100, 1)
                    : 100;
            }

            $projectsData[] = [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'country' => $project->country,
                'department' => $project->department,
                'workflow_type' => $workflowType,
                'received' => $receivedCounts->get($project->id, 0),
                'delivered' => $deliveredOrders->count(),
                'pending' => $pendingCounts->get($project->id, 0),
                'layers' => $layerWork,
                'qa_checklist' => $checklistStats,
            ];
        }

        // Group by country for summary
        $byCountry = collect($projectsData)->groupBy('country')->map(function ($projects, $country) {
            return [
                'country' => $country,
                'project_count' => $projects->count(),
                'total_received' => $projects->sum('received'),
                'total_delivered' => $projects->sum('delivered'),
                'total_pending' => $projects->sum('pending'),
            ];
        })->values();

        // Overall totals
        $totals = [
            'projects' => count($projectsData),
            'received' => collect($projectsData)->sum('received'),
            'delivered' => collect($projectsData)->sum('delivered'),
            'pending' => collect($projectsData)->sum('pending'),
            'total_work_items' => WorkItem::whereDate('completed_at', $dateObj)
                ->where('status', 'completed')->count(),
        ];

        return [
            'date' => $dateObj->format('Y-m-d'),
            'totals' => $totals,
            'by_country' => $byCountry,
            'projects' => $projectsData,
        ];
    }
}
