<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceProjectIsolation
{
    /**
     * Ensure users can only access resources within their assigned project(s).
     * CEO/Director bypass this (they have org-wide access).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // CEO and Director have org-wide access
        if (in_array($user->role, ['ceo', 'director', 'admin'])) {
            return $next($request);
        }

        // Check route parameters for project_id
        $projectId = $request->route('projectId')
            ?? $request->route('project')
            ?? $request->input('project_id');

        if ($projectId) {
            // Operations managers may have multiple projects (check via project assignments)
            if ($user->role === 'operations_manager') {
                // For now, check the user's primary project or query assignments
                $allowedProjects = $user->project_id
                    ? [$user->project_id]
                    : [];

                if (!in_array((int)$projectId, $allowedProjects)) {
                    return response()->json([
                        'message' => 'Access denied: you do not have access to this project.',
                        'code' => 'PROJECT_ISOLATION_VIOLATION',
                    ], 403);
                }
            } else {
                // Production workers: must match their project_id exactly
                if ($user->project_id && (int)$projectId !== $user->project_id) {
                    return response()->json([
                        'message' => 'Access denied: you do not have access to this project.',
                        'code' => 'PROJECT_ISOLATION_VIOLATION',
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
