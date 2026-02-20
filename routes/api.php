<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MonthLockController;
use App\Http\Controllers\Api\OrderImportController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderAssignmentController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Controllers\ManagerDashboardController;


Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/orders/{order}/assign', [OrderAssignmentController::class, 'assign']);
    Route::put('/orders/{order}/reassign', [OrderAssignmentController::class, 'reassign']);
});


// routes/api.php


// Protected routes (add your auth middleware)
Route::middleware(['auth:sanctum'])->group(function () {
    // Manager Dashboard Routes
    Route::prefix('manager')->group(function () {
        Route::get('/dashboard', [ManagerDashboardController::class, 'dashboard']);
        Route::get('/orders', [ManagerDashboardController::class, 'getOrders']);
        Route::get('/orders/{order}', [ManagerDashboardController::class, 'getOrderDetails']);
        Route::get('/orders/{order}/timeline', [ManagerDashboardController::class, 'getOrderTimeline']);
        
        // QA Users route
        Route::get('/qa-users', [ManagerDashboardController::class, 'getQaUsers']);
        
        // Reassignment routes
        Route::post('/orders/{order}/reassign', [ManagerDashboardController::class, 'reassignRejected']);
        Route::post('/orders/bulk-assign', [ManagerDashboardController::class, 'bulkAssign']);
        
        // Analytics
        Route::get('/rejection-analytics', [ManagerDashboardController::class, 'getRejectionAnalytics']);
        
        // Assignment routes
        Route::get('/available-workers', [ManagerDashboardController::class, 'getAvailableWorkers']);
        Route::post('/orders/{order}/assign', [ManagerDashboardController::class, 'assignOrder']);
        
        // Order management
        Route::post('/orders/{order}/hold', [ManagerDashboardController::class, 'holdOrder']);
        Route::post('/orders/{order}/resume', [ManagerDashboardController::class, 'resumeOrder']);
        
        // Queue health
        Route::get('/queue-health', [ManagerDashboardController::class, 'getQueueHealth']);
    });
});

// ── Health Check (no auth required) ──
Route::get('/health', [HealthController::class, 'check']);
Route::get('/ping', [HealthController::class, 'ping']);

// ── Rate limiting ──
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

// ── Public: Auth ──
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// ── Authenticated routes ──
Route::middleware(['auth:sanctum', 'single.session', 'throttle:api'])->group(function () {

    // ── Auth ──
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::get('/auth/session-check', [AuthController::class, 'sessionCheck']);

    // ── Notifications (all authenticated users) ──
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // ═══════════════════════════════════════════
    // PRODUCTION WORKER ROUTES
    // (drawer, checker, qa, designer)
    // ═══════════════════════════════════════════
    Route::prefix('workflow')->group(function () {
        // Start Next (auto-assignment — NO manual picking)
        Route::post('/start-next', [WorkflowController::class, 'startNext']);

        // Current assigned order
        Route::get('/my-current', [WorkflowController::class, 'myCurrent']);

        // My stats
        Route::get('/my-stats', [WorkflowController::class, 'myStats']);
        
        // My queue (drawer order list)
        Route::get('/my-queue', [WorkflowController::class, 'myQueue']);
        
        // My completed orders today
        Route::get('/my-completed', [WorkflowController::class, 'myCompleted']);
        
        // My order history (all time)
        Route::get('/my-history', [WorkflowController::class, 'myHistory']);
        
        // My performance stats
        Route::get('/my-performance', [WorkflowController::class, 'myPerformance']);

        // Submit completed work
        Route::post('/orders/{id}/submit', [WorkflowController::class, 'submitWork']);

        // Reject (checker/QA only)
        Route::post('/orders/{id}/reject', [WorkflowController::class, 'rejectOrder']);

        // Hold/Resume
        Route::post('/orders/{id}/hold', [WorkflowController::class, 'holdOrder']);
        Route::post('/orders/{id}/resume', [WorkflowController::class, 'resumeOrder']);
        
        // Reassign to queue (worker releases order)
        Route::post('/orders/{id}/reassign-queue', [WorkflowController::class, 'reassignToQueue']);
        
        // Flag issue
        Route::post('/orders/{id}/flag-issue', [WorkflowController::class, 'flagIssue']);
        
        // Request help/clarification
        Route::post('/orders/{id}/request-help', [WorkflowController::class, 'requestHelp']);
        
        // Timer controls
        Route::post('/orders/{id}/timer/start', [WorkflowController::class, 'startTimer']);
        Route::post('/orders/{id}/timer/stop', [WorkflowController::class, 'stopTimer']);
        
        // Full order details (with notes, attachments, flags, help requests)
        Route::get('/orders/{id}/full-details', [WorkflowController::class, 'orderFullDetails']);

        // Order details (role-filtered)
        Route::get('/orders/{id}', [WorkflowController::class, 'orderDetails']);

        // Work item history for an order
        Route::get('/work-items/{orderId}', [WorkflowController::class, 'workItemHistory']);
    });

    // Order checklists (accessible to production + management)
    Route::get('/orders/{orderId}/checklist', [ChecklistController::class, 'orderChecklist']);
    Route::put('/orders/{orderId}/checklist/{templateId}', [ChecklistController::class, 'updateOrderChecklist']);
    Route::put('/orders/{orderId}/checklist', [ChecklistController::class, 'bulkUpdateOrderChecklist']);
    Route::get('/orders/{orderId}/checklist-status', [ChecklistController::class, 'checklistStatus']);

    // ── Dashboards ──
    Route::get('/dashboard/master', [DashboardController::class, 'master']);
    Route::get('/dashboard/project/{id}', [DashboardController::class, 'project']);
    Route::get('/dashboard/operations', [DashboardController::class, 'operations']);
    Route::get('/dashboard/worker', [DashboardController::class, 'worker']);
    Route::get('/dashboard/absentees', [DashboardController::class, 'absentees']);
    
    // Daily operations - rate limited separately to protect DB
    Route::middleware('throttle:10,1')->get('/dashboard/daily-operations', [DashboardController::class, 'dailyOperations']);

    // ═══════════════════════════════════════════
    // MANAGEMENT ROUTES (ops_manager, director, ceo)
    // ═══════════════════════════════════════════
    Route::middleware('role:ceo,director,operations_manager,manger,drawer')->group(function () {

        // Projects
        Route::apiResource('projects', ProjectController::class);
        Route::get('/projects/{id}/statistics', [ProjectController::class, 'statistics']);
        Route::get('/projects/{id}/teams', [ProjectController::class, 'teams']);

        // Users
        Route::apiResource('users', UserController::class);
        Route::post('/users/{id}/deactivate', [UserController::class, 'deactivate']);
        Route::get('/users-inactive', [UserController::class, 'inactive']);
        Route::post('/users/reassign-work', [UserController::class, 'reassignWork']);

        // Force logout
        Route::post('/auth/force-logout/{userId}', [AuthController::class, 'forceLogout']);

        // Workflow management
        Route::post('/workflow/receive', [WorkflowController::class, 'receiveOrder']);
        Route::post('/workflow/orders/{id}/reassign', [WorkflowController::class, 'reassignOrder']);
        Route::get('/workflow/{projectId}/queue-health', [WorkflowController::class, 'queueHealth']);
        Route::get('/workflow/{projectId}/staffing', [WorkflowController::class, 'staffing']);
        Route::get('/workflow/{projectId}/orders', [WorkflowController::class, 'projectOrders']);

        // Month Lock
        Route::get('/month-locks/{projectId}', [MonthLockController::class, 'index']);
        Route::post('/month-locks/{projectId}/lock', [MonthLockController::class, 'lock']);
        Route::post('/month-locks/{projectId}/unlock', [MonthLockController::class, 'unlock']);
        Route::get('/month-locks/{projectId}/counts', [MonthLockController::class, 'counts']);
        Route::post('/month-locks/{projectId}/clear', [MonthLockController::class, 'clearPanel']);

        // Order Import
        Route::get('/projects/{projectId}/import-sources', [OrderImportController::class, 'sources']);
        Route::post('/projects/{projectId}/import-sources', [OrderImportController::class, 'createSource']);
        Route::put('/import-sources/{sourceId}', [OrderImportController::class, 'updateSource']);
        Route::post('/projects/{projectId}/import-csv', [OrderImportController::class, 'importCsv']);
        Route::post('/import-sources/{sourceId}/sync', [OrderImportController::class, 'syncFromApi']);
        Route::get('/projects/{projectId}/import-history', [OrderImportController::class, 'importHistory']);
        Route::get('/import-logs/{importLogId}', [OrderImportController::class, 'importDetails']);

        // Checklist templates
        Route::get('/projects/{projectId}/checklists', [ChecklistController::class, 'templates']);
        Route::post('/projects/{projectId}/checklists', [ChecklistController::class, 'createTemplate']);
        Route::put('/checklists/{templateId}', [ChecklistController::class, 'updateTemplate']);
        Route::delete('/checklists/{templateId}', [ChecklistController::class, 'deleteTemplate']);

        // Audit logs
        Route::get('/audit-logs', function (\Illuminate\Http\Request $request) {
            $query = \App\Models\ActivityLog::with('user:id,name,email,role')
                ->orderBy('created_at', 'desc');

            if ($request->has('action')) {
                $query->where('action', $request->action);
            }
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->has('entity_type')) {
                $query->where('model_type', $request->entity_type);
            }

            return response()->json($query->paginate(50));
        });
    });

    // ═══════════════════════════════════════════
    // FINANCE ROUTES (CEO/Director only)
    // ═══════════════════════════════════════════
    Route::middleware('role:ceo,director')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoices/{id}/transition', [InvoiceController::class, 'transition']);
        Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
    });
});
