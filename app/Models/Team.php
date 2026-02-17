<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'qa_count',
        'checker_count',
        'drawer_count',
        'designer_count',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'qa_count' => 'integer',
        'checker_count' => 'integer',
        'drawer_count' => 'integer',
        'designer_count' => 'integer',
    ];

    /**
     * Get the project that owns the team.
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all users in this team.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all orders assigned to this team.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope a query to only include active teams.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
