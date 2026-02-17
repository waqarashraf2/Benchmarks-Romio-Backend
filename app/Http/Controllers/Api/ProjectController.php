<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Project::with(['teams', 'users']);

        $user = $request->user();
        if ($user->role === 'operations_manager') {
            if ($user->project_id) {
                $query->where('id', $user->project_id);
            } else {
                $query->where('country', $user->country);
            }
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Filter by department
        if ($request->has('department')) {
            $query->where('department', $request->department);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by name or code
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('code', 'like', '%' . $request->search . '%')
                  ->orWhere('client_name', 'like', '%' . $request->search . '%');
            });
        }

        $projects = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($projects);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request)
    {
        $project = Project::create($request->validated());

        ActivityLog::log('created_project', Project::class, $project->id, null, $project->toArray());

        return response()->json([
            'message' => 'Project created successfully',
            'data' => $project->load(['teams', 'users']),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $project = Project::with(['teams.users', 'users', 'orders', 'invoices'])->findOrFail($id);

        return response()->json([
            'data' => $project,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, string $id)
    {
        $project = Project::findOrFail($id);
        $oldValues = $project->toArray();

        $project->update($request->validated());

        ActivityLog::log('updated_project', Project::class, $project->id, $oldValues, $project->toArray());

        return response()->json([
            'message' => 'Project updated successfully',
            'data' => $project->load(['teams', 'users']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $project = Project::findOrFail($id);
        $oldValues = $project->toArray();

        $project->delete();

        ActivityLog::log('deleted_project', Project::class, $id, $oldValues, null);

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }

    /**
     * Get project statistics.
     */
    public function statistics(string $id)
    {
        $project = Project::findOrFail($id);

        $stats = [
            'total_orders' => $project->orders()->count(),
            'pending_orders' => $project->orders()->where('status', 'pending')->count(),
            'in_progress_orders' => $project->orders()->where('status', 'in-progress')->count(),
            'completed_orders' => $project->orders()->where('status', 'completed')->count(),
            'total_teams' => $project->teams()->count(),
            'active_teams' => $project->teams()->where('is_active', true)->count(),
            'total_staff' => $project->users()->count(),
            'active_staff' => $project->users()->where('is_active', true)->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Get teams for a project.
     */
    public function teams(string $id)
    {
        $project = Project::findOrFail($id);
        $teams = $project->teams()->with('users:id,name,email,role,team_id,is_active,is_absent')->get();

        return response()->json([
            'data' => $teams,
        ]);
    }
}
