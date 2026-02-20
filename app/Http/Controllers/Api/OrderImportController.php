<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChecklistTemplate;
use App\Models\Order;
use App\Models\OrderImportLog;
use App\Models\OrderImportSource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderImportController extends Controller
{
    /**
     * List all import sources for a project.
     */
    public function sources(Request $request, int $projectId)
    {
        $sources = OrderImportSource::where('project_id', $projectId)
            ->with('latestImport')
            ->get();

        return response()->json($sources);
    }

    /**
     * Create a new import source.
     */
    public function createSource(Request $request, int $projectId)
    {
        $validated = $request->validate([
            'type' => 'required|in:api,cron,csv,manual',
            'name' => 'required|string|max:255',
            'api_endpoint' => 'nullable|url',
            'api_credentials' => 'nullable|array',
            'cron_schedule' => 'nullable|string',
            'field_mapping' => 'nullable|array',
        ]);

        $source = OrderImportSource::create([
            'project_id' => $projectId,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Import source created successfully',
            'data' => $source,
        ], 201);
    }

    
// In OrderController.php
public function pendingForAssignment($projectId, Request $request)
{
    $query = Order::where('project_id', $projectId)
        ->whereNull('assigned_to')
        ->whereIn('workflow_state', ['QUEUED_DRAW', 'REJECTED_BY_CHECK', 'REJECTED_BY_QA'])
        ->with('project:id,name,code');

    if ($request->queue_state) {
        $query->where('workflow_state', $request->queue_state);
    }

    $orders = $query->orderByRaw("CASE priority 
        WHEN 'urgent' THEN 1 
        WHEN 'high' THEN 2 
        WHEN 'normal' THEN 3 
        WHEN 'low' THEN 4 
        ELSE 5 END")
        ->orderBy('received_at', 'asc')
        ->get(['id', 'order_number', 'priority', 'workflow_state', 'received_at']);

    return response()->json($orders);
}


    /**
     * Update an import source.
     */
    public function updateSource(Request $request, int $sourceId)
    {
        $source = OrderImportSource::findOrFail($sourceId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'api_endpoint' => 'nullable|url',
            'api_credentials' => 'nullable|array',
            'cron_schedule' => 'nullable|string',
            'field_mapping' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $source->update($validated);

        return response()->json([
            'message' => 'Import source updated successfully',
            'data' => $source,
        ]);
    }

    /**
     * Import orders from CSV file.
     */
    public function importCsv(Request $request, int $projectId)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'source_id' => 'nullable|exists:order_import_sources,id',
        ]);

        $project = Project::findOrFail($projectId);
        $user = auth()->user();

        // Get or create import source
        $source = $request->source_id 
            ? OrderImportSource::findOrFail($request->source_id)
            : OrderImportSource::firstOrCreate(
                ['project_id' => $projectId, 'type' => 'csv', 'name' => 'CSV Import'],
                ['is_active' => true]
            );

        // Store the file
        $path = $request->file('file')->store('imports');

        // Create import log
        $importLog = OrderImportLog::create([
            'import_source_id' => $source->id,
            'imported_by' => $user->id,
            'status' => 'pending',
            'file_path' => $path,
        ]);

        // Process CSV
        $result = $this->processCsvFile($path, $project, $importLog, $source->field_mapping);

        return response()->json([
            'message' => 'CSV import completed',
            'data' => [
                'import_log_id' => $importLog->id,
                'total_rows' => $result['total'],
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
            ],
        ]);
    }

    /**
     * Process CSV file and import orders.
     */
    private function processCsvFile(string $path, Project $project, OrderImportLog $importLog, ?array $fieldMapping = null): array
    {
        $importLog->markStarted();

        $handle = fopen(Storage::path($path), 'r');
        $headers = fgetcsv($handle);
        
        // Default field mapping
        $mapping = $fieldMapping ?? [
            'order_number' => 'order_number',
            'client_reference' => 'client_reference',
            'priority' => 'priority',
            'received_at' => 'received_at',
        ];

        $total = 0;
        $imported = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $data = array_combine($headers, $row);

            try {
                // Map fields
                $orderData = [
                    'project_id' => $project->id,
                    'import_source' => 'csv',
                    'import_log_id' => $importLog->id,
                    'current_layer' => $project->workflow_layers[0] ?? 'drawer',
                    'status' => 'pending',
                ];

                foreach ($mapping as $ourField => $csvField) {
                    if (isset($data[$csvField])) {
                        $orderData[$ourField] = $data[$csvField];
                    }
                }

                // Generate order number if not provided
                if (empty($orderData['order_number'])) {
                    $orderData['order_number'] = $project->code . '-' . Str::upper(Str::random(8));
                }

                // Set default received_at
                if (empty($orderData['received_at'])) {
                    $orderData['received_at'] = now();
                }

                // Validate
                $validator = Validator::make($orderData, [
                    'order_number' => 'required|unique:orders,order_number',
                    'project_id' => 'required|exists:projects,id',
                    'priority' => 'nullable|in:low,normal,high,urgent',
                ]);

                if ($validator->fails()) {
                    $errors[] = [
                        'row' => $total,
                        'message' => $validator->errors()->first(),
                        'data' => $data,
                    ];
                    $skipped++;
                    continue;
                }

                // Set default priority
                $orderData['priority'] = $orderData['priority'] ?? 'normal';

                Order::create($orderData);
                $imported++;
                $importLog->incrementImported();

            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $total,
                    'message' => $e->getMessage(),
                ];
                $skipped++;
                $importLog->addError($e->getMessage(), $total);
            }
        }

        fclose($handle);

        $importLog->update([
            'total_rows' => $total,
            'skipped_count' => $skipped,
        ]);
        $importLog->markCompleted();

        // Update source stats
        $source = $importLog->importSource;
        $source->update([
            'last_sync_at' => now(),
            'orders_synced' => $source->orders_synced + $imported,
        ]);

        return [
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Sync orders from API source.
     */
    public function syncFromApi(Request $request, int $sourceId)
    {
        $source = OrderImportSource::findOrFail($sourceId);

        if ($source->type !== 'api' && $source->type !== 'cron') {
            return response()->json([
                'message' => 'This source is not configured for API sync',
            ], 400);
        }

        if (!$source->api_endpoint) {
            return response()->json([
                'message' => 'API endpoint not configured',
            ], 400);
        }

        $user = auth()->user();
        $project = $source->project;

        // Create import log
        $importLog = OrderImportLog::create([
            'import_source_id' => $source->id,
            'imported_by' => $user->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            // Make API request
            $response = Http::withHeaders($this->buildApiHeaders($source))
                ->get($source->api_endpoint);

            if (!$response->successful()) {
                $importLog->markFailed(['API request failed: ' . $response->status()]);
                return response()->json([
                    'message' => 'API request failed',
                    'status' => $response->status(),
                ], 400);
            }

            $orders = $response->json('orders') ?? $response->json('data') ?? $response->json();
            
            if (!is_array($orders)) {
                $importLog->markFailed(['Invalid response format']);
                return response()->json([
                    'message' => 'Invalid response format from API',
                ], 400);
            }

            $result = $this->processApiOrders($orders, $project, $importLog, $source);

            return response()->json([
                'message' => 'API sync completed',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            $importLog->markFailed([$e->getMessage()]);
            return response()->json([
                'message' => 'API sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build API headers from source credentials.
     */
    private function buildApiHeaders(OrderImportSource $source): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        $creds = $source->api_credentials ?? [];

        if (isset($creds['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $creds['api_key'];
        }

        if (isset($creds['headers'])) {
            $headers = array_merge($headers, $creds['headers']);
        }

        return $headers;
    }

    /**
     * Process orders from API response.
     */
    private function processApiOrders(array $orders, Project $project, OrderImportLog $importLog, OrderImportSource $source): array
    {
        $mapping = $source->field_mapping ?? [];
        $total = count($orders);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($orders as $index => $orderData) {
            try {
                // Map fields
                $mappedData = [
                    'project_id' => $project->id,
                    'import_source' => $source->type,
                    'import_log_id' => $importLog->id,
                    'current_layer' => $project->workflow_layers[0] ?? 'drawer',
                    'status' => 'pending',
                    'received_at' => now(),
                ];

                // Apply field mapping
                foreach ($mapping as $ourField => $apiField) {
                    if (isset($orderData[$apiField])) {
                        $mappedData[$ourField] = $orderData[$apiField];
                    }
                }

                // Use API's order ID as client_portal_id
                if (isset($orderData['id'])) {
                    $mappedData['client_portal_id'] = (string) $orderData['id'];
                }

                // Generate order number if not mapped
                if (empty($mappedData['order_number'])) {
                    $mappedData['order_number'] = $project->code . '-' . ($mappedData['client_portal_id'] ?? Str::upper(Str::random(8)));
                }

                // Check for duplicate
                $exists = Order::where('order_number', $mappedData['order_number'])
                    ->orWhere(function ($query) use ($mappedData, $project) {
                        if (!empty($mappedData['client_portal_id'])) {
                            $query->where('project_id', $project->id)
                                ->where('client_portal_id', $mappedData['client_portal_id']);
                        }
                    })->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                Order::create($mappedData);
                $imported++;

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ];
                $importLog->addError($e->getMessage(), $index);
            }
        }

        $importLog->update([
            'total_rows' => $total,
            'imported_count' => $imported,
            'skipped_count' => $skipped,
        ]);
        $importLog->markCompleted();

        $source->update([
            'last_sync_at' => now(),
            'orders_synced' => $source->orders_synced + $imported,
        ]);

        return [
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Get import history for a project.
     */
    public function importHistory(Request $request, int $projectId)
    {
        $logs = OrderImportLog::whereHas('importSource', function ($query) use ($projectId) {
            $query->where('project_id', $projectId);
        })
        ->with(['importSource', 'importedBy'])
        ->orderBy('created_at', 'desc')
        ->paginate(20);

        return response()->json($logs);
    }

    /**
     * Get details of a specific import.
     */
    public function importDetails(int $importLogId)
    {
        $log = OrderImportLog::with(['importSource', 'importedBy', 'orders'])
            ->findOrFail($importLogId);

        return response()->json($log);
    }
}
