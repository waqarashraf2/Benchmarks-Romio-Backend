<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'password', 'role', 'country', 'department',
        'project_id', 'team_id', 'layer', 'is_active',
        'last_activity', 'inactive_days',
        'current_session_token', 'wip_count', 'today_completed',
        'shift_start', 'shift_end', 'is_absent', 'daily_target',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_activity' => 'datetime',
            'is_active' => 'boolean',
            'is_absent' => 'boolean',
            'shift_start' => 'datetime:H:i',
            'shift_end' => 'datetime:H:i',
        ];
    }

    /**
     * Get the project that the user belongs to.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the team that the user belongs to.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get all work assignments for the user.
     */
    public function workAssignments()
    {
        return $this->hasMany(WorkAssignment::class);
    }

    /**
     * Get all activity logs for the user.
     */
    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function managedProjects()
{
    // Adjust this based on your actual relationship structure
    // This could be through a team, direct assignment, etc.
    return $this->belongsToMany(Project::class, 'project_managers', 'user_id', 'project_id');
}

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function workItems()
    {
        return $this->hasMany(WorkItem::class, 'assigned_user_id');
    }
}
