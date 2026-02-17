<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class AuditService
{
    /**
     * Log an action with full context.
     */
    public static function log(
        ?int $actorId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $projectId = null,
        ?array $before = null,
        ?array $after = null
    ): ActivityLog {
        return ActivityLog::create([
            'user_id'     => $actorId ?? Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'project_id'  => $projectId,
            'model_type'  => $entityType,
            'model_id'    => $entityId,
            'old_values'  => $before,
            'new_values'  => $after,
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ]);
    }

    /**
     * Log a login attempt.
     */
    public static function logLogin(int $userId, bool $success, ?string $reason = null): ActivityLog
    {
        return self::log(
            $userId,
            $success ? 'LOGIN' : 'LOGIN_FAILED',
            'User',
            $userId,
            null,
            null,
            ['success' => $success, 'reason' => $reason, 'ip' => request()->ip()]
        );
    }

    /**
     * Log a logout.
     */
    public static function logLogout(int $userId): ActivityLog
    {
        return self::log($userId, 'LOGOUT', 'User', $userId);
    }

    /**
     * Log an assignment action.
     */
    public static function logAssignment(int $orderId, int $projectId, ?int $fromUserId, ?int $toUserId, string $reason = 'auto'): ActivityLog
    {
        return self::log(
            Auth::id(),
            'ASSIGN',
            'Order',
            $orderId,
            $projectId,
            ['assigned_to' => $fromUserId],
            ['assigned_to' => $toUserId, 'reason' => $reason]
        );
    }

    /**
     * Log an invoice action.
     */
    public static function logInvoiceAction(int $invoiceId, int $projectId, string $action, ?array $before = null, ?array $after = null): ActivityLog
    {
        return self::log(Auth::id(), $action, 'Invoice', $invoiceId, $projectId, $before, $after);
    }

    /**
     * Log month lock/unlock.
     */
    public static function logMonthLock(int $lockId, int $projectId, string $action): ActivityLog
    {
        return self::log(Auth::id(), $action, 'MonthLock', $lockId, $projectId);
    }
}
