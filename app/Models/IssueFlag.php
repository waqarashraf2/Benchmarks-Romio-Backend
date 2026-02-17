<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'flagged_by',
        'project_id',
        'flag_type',
        'description',
        'severity',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    const FLAG_TYPES = [
        'quality',
        'missing_info',
        'wrong_specs',
        'unclear_instructions',
        'file_issue',
        'other',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function flagger()
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}
