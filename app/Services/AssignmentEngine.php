<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class AssignmentEngine
{
    /**
     * Start next: find the next order in the user's queue and assign it.
     * Returns the assigned order or null if queue is empty.
     */
    public static function startNext(User $user): ?Order
    {
        $project = $user->project;
        if (!$project) return null;

        $role = $user->role;
        $queueState = self::getQueueStateForRole($role, $project->workflow_type);
        if (!$queueState) return null;

        // Check WIP cap
        $wipCap = $project->wip_cap ?? 1;
        $currentWip = Order::where('project_id', $project->id)
            ->where('assigned_to', $user->id)
            ->whereIn('workflow_state', self::getInProgressStatesForRole($role, $project->workflow_type))
            ->count();

        if ($currentWip >= $wipCap) {
            return null; // Already at max WIP
        }

        // Find next order: priority first, then oldest received
        $order = Order::where('project_id', $project->id)
            ->where('workflow_state', $queueState)
            ->whereNull('assigned_to')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('received_at', 'asc')
            ->lockForUpdate()
            ->first();

        if (!$order) return null;

        $inState = StateMachine::getInProgressState($queueState);
        if (!$inState) return null;

        return DB::transaction(function () use ($order, $user, $inState, $queueState) {
            // Assign + transition
            $order->update(['assigned_to' => $user->id, 'team_id' => $user->team_id]);

            StateMachine::transition($order, $inState, $user->id);

            // Create work item
            $stage = StateMachine::STATE_TO_STAGE[$inState] ?? null;
            WorkItem::create([
                'order_id'         => $order->id,
                'project_id'       => $order->project_id,
                'stage'            => $stage,
                'assigned_user_id' => $user->id,
                'team_id'          => $user->team_id,
                'status'           => 'in_progress',
                'assigned_at'      => now(),
                'started_at'       => now(),
                'attempt_number'   => self::getAttemptNumber($order, $stage),
            ]);

            // Update user WIP
            $user->increment('wip_count');

            return $order->fresh();
        });
    }

    /**
     * Submit work: transition order to next stage.
     */
    public static function submitWork(Order $order, User $user, ?string $comments = null): Order
    {
        $submittedState = StateMachine::getSubmittedState($order->workflow_state);
        if (!$submittedState) {
            throw new \InvalidArgumentException("Cannot submit from state: {$order->workflow_state}");
        }

        return DB::transaction(function () use ($order, $user, $submittedState, $comments) {
            // Complete the work item
            $stage = StateMachine::STATE_TO_STAGE[$order->workflow_state] ?? null;
            $workItem = WorkItem::where('order_id', $order->id)
                ->where('stage', $stage)
                ->where('assigned_user_id', $user->id)
                ->where('status', 'in_progress')
                ->latest()
                ->first();

            if ($workItem) {
                $workItem->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'comments'     => $comments,
                ]);
            }

            // Transition to submitted state
            StateMachine::transition($order, $submittedState, $user->id);

            // Auto-advance to next queue
            $nextQueue = StateMachine::getNextQueueState($submittedState, $order->workflow_type);
            if ($nextQueue) {
                StateMachine::transition($order, $nextQueue, $user->id);
            }

            // Update user stats
            $user->decrement('wip_count');
            $user->increment('today_completed');

            return $order->fresh();
        });
    }

    /**
     * Reject an order (by checker or QA).
     */
    public static function rejectOrder(
        Order $order,
        User $actor,
        string $reason,
        string $rejectionCode,
        ?string $routeTo = null
    ): Order {
        $currentState = $order->workflow_state;

        // Determine rejection target state
        if ($currentState === 'IN_CHECK') {
            $targetState = 'REJECTED_BY_CHECK';
        } elseif ($currentState === 'IN_QA') {
            $targetState = 'REJECTED_BY_QA';
        } else {
            throw new \InvalidArgumentException("Cannot reject from state: {$currentState}");
        }

        return DB::transaction(function () use ($order, $actor, $reason, $rejectionCode, $targetState, $routeTo) {
            // Complete current work item as rejected
            $stage = StateMachine::STATE_TO_STAGE[$order->workflow_state] ?? null;
            $workItem = WorkItem::where('order_id', $order->id)
                ->where('stage', $stage)
                ->where('assigned_user_id', $actor->id)
                ->where('status', 'in_progress')
                ->latest()
                ->first();

            if ($workItem) {
                $workItem->update([
                    'status'         => 'completed',
                    'completed_at'   => now(),
                    'rework_reason'  => $reason,
                    'rejection_code' => $rejectionCode,
                ]);
            }

            // Update order rejection fields
            $order->update([
                'rejected_by'      => $actor->id,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
                'rejection_type'   => $rejectionCode,
                'recheck_count'    => $order->recheck_count + 1,
            ]);

            // Transition to rejected state
            StateMachine::transition($order, $targetState, $actor->id, [
                'rejection_reason' => $reason,
                'rejection_code'   => $rejectionCode,
            ]);

            // Route to the appropriate queue
            if ($targetState === 'REJECTED_BY_CHECK') {
                StateMachine::transition($order, 'QUEUED_DRAW', $actor->id);
            } elseif ($targetState === 'REJECTED_BY_QA') {
                $target = ($routeTo === 'draw') ? 'QUEUED_DRAW' : 'QUEUED_CHECK';
                if ($order->workflow_type === 'PH_2_LAYER') {
                    $target = 'QUEUED_DESIGN';
                }
                StateMachine::transition($order, $target, $actor->id);
            }

            // Update actor stats
            $actor->decrement('wip_count');
            $actor->increment('today_completed');

            return $order->fresh();
        });
    }

    /**
     * Reassign work from an inactive/terminated user.
     */
    public static function reassignFromUser(User $user, ?int $actorId = null): int
    {
        $orders = Order::where('assigned_to', $user->id)
            ->whereIn('workflow_state', [
                'IN_DRAW', 'IN_CHECK', 'IN_QA', 'IN_DESIGN',
            ])
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $user, $actorId) {
                $currentState = $order->workflow_state;
                $queueState = str_replace('IN_', 'QUEUED_', $currentState);

                // Revert work item
                $stage = StateMachine::STATE_TO_STAGE[$currentState] ?? null;
                WorkItem::where('order_id', $order->id)
                    ->where('stage', $stage)
                    ->where('assigned_user_id', $user->id)
                    ->where('status', 'in_progress')
                    ->update(['status' => 'abandoned', 'completed_at' => now()]);

                // Directly update state (admin override — bypasses state machine validation)
                $oldState = $order->workflow_state;
                $order->update([
                    'workflow_state' => $queueState,
                    'assigned_to' => null,
                ]);

                // Create audit log manually
                \App\Models\ActivityLog::log(
                    'admin_reassign',
                    \App\Models\Order::class,
                    $order->id,
                    ['workflow_state' => $oldState, 'assigned_to' => $user->id],
                    ['workflow_state' => $queueState, 'assigned_to' => null]
                );
            });
            $count++;
        }

        $user->update(['wip_count' => 0]);
        return $count;
    }

    /**
     * Find the best user for auto-assignment in a project queue.
     */
    public static function findBestUser(int $projectId, string $role): ?User
    {
        return User::where('project_id', $projectId)
            ->where('role', $role)
            ->where('is_active', true)
            ->where('is_absent', false)
            ->whereHas('sessions', function ($q) {
                $q->where('last_activity', '>', now()->subMinutes(15));
            })
            ->orderBy('wip_count', 'asc')
            ->orderBy('today_completed', 'asc')
            ->orderBy('last_activity', 'asc')
            ->first();
    }

    // ── Private helpers ──

    private static function getQueueStateForRole(string $role, string $workflowType): ?string
    {
        if ($workflowType === 'PH_2_LAYER') {
            return match ($role) {
                'designer' => 'QUEUED_DESIGN',
                'qa'       => 'QUEUED_QA',
                default    => null,
            };
        }
        return match ($role) {
            'drawer'  => 'QUEUED_DRAW',
            'checker' => 'QUEUED_CHECK',
            'qa'      => 'QUEUED_QA',
            default   => null,
        };
    }

    private static function getInProgressStatesForRole(string $role, string $workflowType): array
    {
        if ($workflowType === 'PH_2_LAYER') {
            return match ($role) {
                'designer' => ['IN_DESIGN'],
                'qa'       => ['IN_QA'],
                default    => [],
            };
        }
        return match ($role) {
            'drawer'  => ['IN_DRAW'],
            'checker' => ['IN_CHECK'],
            'qa'      => ['IN_QA'],
            default   => [],
        };
    }

    private static function getAttemptNumber(Order $order, ?string $stage): int
    {
        return match ($stage) {
            'DRAW'   => $order->attempt_draw + 1,
            'CHECK'  => $order->attempt_check + 1,
            'DESIGN' => $order->attempt_draw + 1,
            'QA'     => $order->attempt_qa + 1,
            default  => 1,
        };
    }
}
