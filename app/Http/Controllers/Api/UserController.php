<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\ChecklistTemplate;
use App\Models\Order;
use App\Models\OrderImportLog;
use App\Models\OrderImportSource;
use App\Models\Project;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::with(['project', 'team']);

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Filter by department
        if ($request->has('department')) {
            $query->where('department', $request->department);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by team
        if ($request->has('team_id')) {
            $query->where('team_id', $request->team_id);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Search by name or email
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $users = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        // Password is auto-hashed by User model's 'hashed' cast

        $user = User::create($data);

        ActivityLog::log('created_user', User::class, $user->id, null, $user->toArray());

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user->load(['project', 'team']),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with(['project', 'team', 'workAssignments.order'])->findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, string $id)
    {
        $user = User::findOrFail($id);
        $oldValues = $user->toArray();

        $data = $request->validated();
        // Password is auto-hashed by User model's 'hashed' cast

        $user->update($data);

        ActivityLog::log('updated_user', User::class, $user->id, $oldValues, $user->toArray());

        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user->load(['project', 'team']),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $oldValues = $user->toArray();

        $user->delete();

        ActivityLog::log('deleted_user', User::class, $id, $oldValues, null);

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Update user activity timestamp.
     */
    public function updateActivity(string $id)
    {
        $user = User::findOrFail($id);
        $user->update([
            'last_activity' => now(),
            'inactive_days' => 0,
        ]);

        return response()->json([
            'message' => 'Activity updated',
        ]);
    }

    /**
     * Get inactive users.
     */
    public function inactive()
    {
        $users = User::where('inactive_days', '>', 0)
            ->orWhere('last_activity', '<', now()->subDays(1))
            ->with(['project', 'team'])
            ->get();

        return response()->json($users);
    }

    /**
     * Deactivate a user and reassign their work.
     */
    public function deactivate(string $id)
    {
        $user = User::findOrFail($id);
        $oldValues = ['is_active' => $user->is_active];

        $user->update(['is_active' => false, 'is_absent' => true]);

        // Reassign any active work
        \App\Services\AssignmentEngine::reassignFromUser($user, auth()->id());

        NotificationService::userDeactivated($user, auth()->user());

        ActivityLog::log('deactivated_user', User::class, $user->id, $oldValues, ['is_active' => false]);

        return response()->json([
            'message' => 'User deactivated and work reassigned.',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Reassign all work from a user.
     */
    public function reassignWork(Request $request)
    {
        $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($request->user_id);
        \App\Services\AssignmentEngine::reassignFromUser($user, auth()->id());

        ActivityLog::log('reassigned_work', User::class, $user->id, null, ['reassigned_by' => auth()->id()]);

        return response()->json([
            'message' => 'All work reassigned from user.',
            'data' => $user->fresh(),
        ]);
    }


    
public function projectDrawers($projectId)
{
    $drawers = User::where('project_id', $projectId)
        ->where('role', 'drawer')
        ->where('is_active', true)
        ->where('is_absent', false)
        ->select('id', 'name', 'email', 'wip_count', 'today_completed', 'last_activity')
        ->orderBy('name')
        ->get();

    return response()->json($drawers);
}

// In WorkflowController.php


}
