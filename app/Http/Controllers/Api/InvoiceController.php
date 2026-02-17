<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MonthLock;
use App\Models\Project;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * Invoice status workflow: draft → prepared → approved → issued → sent
     */
    const STATUS_TRANSITIONS = [
        'draft'    => ['prepared'],
        'prepared' => ['approved', 'draft'], // Can send back to draft
        'approved' => ['issued'],
        'issued'   => ['sent'],
        'sent'     => [],
    ];

    /**
     * GET /invoices
     * List invoices with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Invoice::with(['project:id,name,code,country', 'preparedBy:id,name', 'approvedBy:id,name']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('month') && $request->has('year')) {
            $query->where('month', $request->input('month'))->where('year', $request->input('year'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate(25));
    }

    /**
     * POST /invoices
     * Create a draft invoice from locked month counts.
     * Only CEO/Director can create invoices.
     */
    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2100',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['ceo', 'director'])) {
            return response()->json(['message' => 'Only CEO/Director can create invoices.'], 403);
        }

        $projectId = $request->input('project_id');
        $month = $request->input('month');
        $year = $request->input('year');

        // Check if month is locked
        $lock = MonthLock::where('project_id', $projectId)
            ->where('month', $month)
            ->where('year', $year)
            ->where('is_locked', true)
            ->first();

        if (!$lock) {
            return response()->json([
                'message' => 'Month must be locked before creating an invoice. Lock the month first.',
            ], 422);
        }

        // Check for duplicate invoice
        $existing = Invoice::where('project_id', $projectId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Invoice already exists for this month.',
                'invoice' => $existing,
            ], 409);
        }

        $project = Project::findOrFail($projectId);

        // Calculate total from frozen counts + project invoice category config
        $counts = $lock->frozen_counts;
        $totalAmount = $this->calculateTotal($counts, $project->invoice_categories_config);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-' . strtoupper($project->code) . '-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT),
            'project_id' => $projectId,
            'month' => $month,
            'year' => $year,
            'service_counts' => $counts,
            'total_amount' => $totalAmount,
            'status' => 'draft',
            'prepared_by' => $user->id,
            'locked_month_id' => $lock->id,
        ]);

        AuditService::logInvoiceAction($invoice->id, $projectId, 'INVOICE_CREATED', null, [
            'status' => 'draft',
            'total_amount' => $totalAmount,
        ]);

        return response()->json(['invoice' => $invoice], 201);
    }

    /**
     * GET /invoices/{id}
     */
    public function show(int $id)
    {
        $invoice = Invoice::with([
            'project:id,name,code,country,department',
            'preparedBy:id,name',
            'approvedBy:id,name',
        ])->findOrFail($id);

        return response()->json(['invoice' => $invoice]);
    }

    /**
     * POST /invoices/{id}/transition
     * Advance invoice through workflow: draft→prepared→approved→issued→sent
     */
    public function transition(Request $request, int $id)
    {
        $request->validate([
            'to_status' => 'required|string|in:draft,prepared,approved,issued,sent',
        ]);

        $user = $request->user();
        $invoice = Invoice::findOrFail($id);
        $toStatus = $request->input('to_status');

        // Validate transition
        $allowed = self::STATUS_TRANSITIONS[$invoice->status] ?? [];
        if (!in_array($toStatus, $allowed)) {
            return response()->json([
                'message' => "Cannot transition from '{$invoice->status}' to '{$toStatus}'.",
                'allowed' => $allowed,
            ], 422);
        }

        // Only CEO/Director can approve/issue
        if (in_array($toStatus, ['approved', 'issued', 'sent']) && !in_array($user->role, ['ceo', 'director'])) {
            return response()->json(['message' => 'Only CEO/Director can approve/issue invoices.'], 403);
        }

        $before = ['status' => $invoice->status];
        $updates = ['status' => $toStatus];

        if ($toStatus === 'approved') {
            $updates['approved_by'] = $user->id;
            $updates['approved_at'] = now();
        }
        if ($toStatus === 'issued') {
            $updates['issued_by'] = $user->id;
            $updates['issued_at'] = now();
        }
        if ($toStatus === 'sent') {
            $updates['sent_at'] = now();
        }

        $invoice->update($updates);

        AuditService::logInvoiceAction($invoice->id, $invoice->project_id, 'INVOICE_' . strtoupper($toStatus), $before, $updates);

        NotificationService::invoiceTransition($invoice->id, $before['status'], $toStatus, $user);

        return response()->json([
            'invoice' => $invoice->fresh(),
            'message' => "Invoice status changed to '{$toStatus}'.",
        ]);
    }

    /**
     * DELETE /invoices/{id}
     * Only draft invoices can be deleted.
     */
    public function destroy(int $id)
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted.'], 422);
        }

        AuditService::logInvoiceAction($invoice->id, $invoice->project_id, 'INVOICE_DELETED');
        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted.']);
    }

    // ── Private ──

    private function calculateTotal(?array $counts, ?array $categoryConfig): float
    {
        if (!$counts || !$categoryConfig) {
            // Simple count-based calculation
            return ($counts['delivered'] ?? 0) * 10.0; // Default rate
        }

        $total = 0;
        foreach ($categoryConfig as $category) {
            $rate = $category['rate'] ?? 0;
            $countKey = $category['count_key'] ?? 'delivered';
            $count = $counts[$countKey] ?? $counts['delivered'] ?? 0;
            $total += $rate * $count;
        }

        return round($total, 2);
    }
}
