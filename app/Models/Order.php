<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'project_id', 'client_reference','address',
        'current_layer', 'status', 'workflow_state', 'workflow_type',
        'assigned_to', 'team_id', 'priority', 'ausDatein', 'due_in',
        'received_at', 'started_at', 'completed_at', 'delivered_at', 'due_date',
        'metadata', 'import_source', 'import_log_id',
        'recheck_count', 'rejected_by', 'rejected_at',
        'rejection_reason', 'rejection_type', 'checker_self_corrected',
        'client_portal_id', 'client_portal_synced_at',
        'attempt_draw', 'attempt_check', 'attempt_qa',
        'is_on_hold', 'hold_reason', 'hold_set_by',
        'supervisor_notes', 'attachments','created_at',
        'pre_hold_state',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'rejected_at' => 'datetime',
        'client_portal_synced_at' => 'datetime',
        'due_date' => 'date',
        'metadata' => 'array',
        'attachments' => 'array',
        'checker_self_corrected' => 'boolean',
        'is_on_hold' => 'boolean',
    ];

    /**
     * Get the project that owns the order.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user assigned to this order.
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the team assigned to this order.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get all work assignments for this order.
     */
    public function workAssignments()
    {
        return $this->hasMany(WorkAssignment::class);
    }

    public function workItems()
    {
        return $this->hasMany(WorkItem::class);
    }

    /**
     * Get the import log for this order.
     */
    public function importLog()
    {
        return $this->belongsTo(OrderImportLog::class, 'import_log_id');
    }

    /**
     * Get the user who rejected this order.
     */
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get all checklist items for this order.
     */
    public function checklists()
    {
        return $this->hasMany(OrderChecklist::class);
    }

    /**
     * Check if all required checklist items are completed for current layer.
     */
    public function hasCompletedChecklist(): bool
    {
        $project = $this->project;
        $requiredItems = ChecklistTemplate::where('project_id', $project->id)
            ->where('layer', $this->current_layer)
            ->where('is_required', true)
            ->where('is_active', true)
            ->pluck('id');

        if ($requiredItems->isEmpty()) {
            return true; // No required items
        }

        $completedItems = $this->checklists()
            ->whereIn('checklist_template_id', $requiredItems)
            ->where('is_checked', true)
            ->pluck('checklist_template_id');

        return $requiredItems->diff($completedItems)->isEmpty();
    }

    /**
     * Reject the order and send back to designer.
     */
    public function reject(int $rejectedById, string $reason, string $type = 'quality')
    {
        $this->update([
            'status' => 'pending',
            'current_layer' => 'drawer', // Send back to drawer
            'rejected_by' => $rejectedById,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'rejection_type' => $type,
            'recheck_count' => $this->recheck_count + 1,
            'assigned_to' => null, // Needs reassignment
        ]);
    }

    /**
     * Mark as self-corrected by checker.
     */
    public function markSelfCorrected()
    {
        $this->update([
            'checker_self_corrected' => true,
        ]);
    }

    /**
     * Sync status to client portal.
     */
    public function markSyncedToClientPortal()
    {
        $this->update([
            'client_portal_synced_at' => now(),
        ]);
    }

    /**
     * Scope a query to only include pending orders.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include in-progress orders.
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in-progress');
    }

    /**
     * Scope a query to filter by priority.
     */
    public function scopePriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope a query to filter by current layer.
     */
    public function scopeLayer($query, $layer)
    {
        return $query->where('current_layer', $layer);
    }

    /**
     * Scope to rejected orders.
     */
    public function scopeRejected($query)
    {
        return $query->whereNotNull('rejected_at');
    }

    /**
     * Scope to orders needing recheck.
     */
    public function scopeNeedsRecheck($query)
    {
        return $query->where('recheck_count', '>', 0)
            ->where('status', 'pending');
    }

    /**
     * Scope to unsynced orders (completed but not synced to client portal).
     */
    public function scopeUnsynced($query)
    {
        return $query->where('status', 'completed')
            ->whereNull('client_portal_synced_at');
    }
}
