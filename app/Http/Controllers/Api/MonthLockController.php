<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthLock;
use App\Models\Order;
use App\Models\WorkItem;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class MonthLockController extends Controller
{
    /**
     * GET /month-locks/{projectId}
     * List all month locks for a project.
     */
    public function index(int $projectId)
    {
        $locks = MonthLock::where('project_id', $projectId)
            ->with(['lockedByUser:id,name', 'unlockedByUser:id,name'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json(['locks' => $locks]);
    }

    /**
     * POST /month-locks/{projectId}/lock
     * Lock a month for a project — freezes production counts.
     */
    public function lock(Request $request, int $projectId)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['operations_manager', 'director', 'ceo'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $month = $request->input('month');
        $year = $request->input('year');

        // Check if already locked
        $existing = MonthLock::where('project_id', $projectId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($existing && $existing->is_locked) {
            return response()->json(['message' => 'Month is already locked.'], 422);
        }

        // Compute frozen counts
        $frozenCounts = $this->computeProductionCounts($projectId, $month, $year);

        $lock = MonthLock::updateOrCreate(
            ['project_id' => $projectId, 'month' => $month, 'year' => $year],
            [
                'is_locked' => true,
                'locked_by' => $user->id,
                'locked_at' => now(),
                'unlocked_by' => null,
                'unlocked_at' => null,
                'frozen_counts' => $frozenCounts,
            ]
        );

        AuditService::logMonthLock($lock->id, $projectId, 'LOCK_MONTH');

        NotificationService::monthLocked($projectId, $month, $year, $user);

        return response()->json([
            'lock' => $lock,
            'message' => "Month {$month}/{$year} locked successfully.",
        ]);
    }

    /**
     * POST /month-locks/{projectId}/unlock
     * Unlock a month (CEO/Director only).
     */
    public function unlock(Request $request, int $projectId)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['director', 'ceo'])) {
            return response()->json(['message' => 'Only CEO/Director can unlock months.'], 403);
        }

        $lock = MonthLock::where('project_id', $projectId)
            ->where('month', $request->input('month'))
            ->where('year', $request->input('year'))
            ->firstOrFail();

        if (!$lock->is_locked) {
            return response()->json(['message' => 'Month is not locked.'], 422);
        }

        $lock->update([
            'is_locked' => false,
            'unlocked_by' => $user->id,
            'unlocked_at' => now(),
        ]);

        AuditService::logMonthLock($lock->id, $projectId, 'UNLOCK_MONTH');

        return response()->json([
            'lock' => $lock->fresh(),
            'message' => 'Month unlocked.',
        ]);
    }

    /**
     * GET /month-locks/{projectId}/counts
     * Get production counts for a month (frozen if locked, live if not).
     */
    public function counts(Request $request, int $projectId)
    {
        $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $month = $request->input('month');
        $year = $request->input('year');

        $lock = MonthLock::where('project_id', $projectId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($lock && $lock->is_locked) {
            return response()->json([
                'counts' => $lock->frozen_counts,
                'is_locked' => true,
                'locked_at' => $lock->locked_at,
            ]);
        }

        $counts = $this->computeProductionCounts($projectId, $month, $year);

        return response()->json([
            'counts' => $counts,
            'is_locked' => false,
        ]);
    }

    /**
     * POST /month-locks/{projectId}/clear
     * Clear panel — resets view to new month. Does NOT delete data.
     */
    public function clearPanel(Request $request, int $projectId)
    {
        $user = $request->user();
        if (!in_array($user->role, ['operations_manager', 'director', 'ceo'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        AuditService::log($user->id, 'CLEAR_PANEL', 'Project', $projectId, $projectId, null, [
            'cleared_at' => now()->toIso8601String(),
        ]);

        return response()->json(['message' => 'Panel cleared. Historical data preserved.']);
    }

    // ── Private ──

    private function computeProductionCounts(int $projectId, int $month, int $year): array
    {
        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        $received = Order::where('project_id', $projectId)
            ->whereBetween('received_at', [$startDate, $endDate . ' 23:59:59'])
            ->count();

        $delivered = Order::where('project_id', $projectId)
            ->where('workflow_state', 'DELIVERED')
            ->whereBetween('delivered_at', [$startDate, $endDate . ' 23:59:59'])
            ->count();

        $pending = Order::where('project_id', $projectId)
            ->whereNotIn('workflow_state', ['DELIVERED', 'CANCELLED'])
            ->count();

        // Per-stage completed work items
        $stageCompletions = WorkItem::where('project_id', $projectId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('stage, COUNT(*) as count')
            ->groupBy('stage')
            ->pluck('count', 'stage')
            ->all();

        return [
            'received' => $received,
            'delivered' => $delivered,
            'pending' => $pending,
            'stage_completions' => $stageCompletions,
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
