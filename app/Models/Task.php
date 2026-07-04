<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Task — TaskDependencyService is the only place status becomes/
 * leaves Blocked; TaskService derives Overdue from due_at rather than
 * accepting it as directly settable ("overdue is derived... not
 * manually trusted" — PDF). No uuid — internal/staff-facing only in
 * Phase 4.
 */
class Task extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'client_id',
        'assigned_to',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tasks that block THIS task from proceeding.
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /**
     * Tasks that are blocked BY this task.
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'blocked_by_task_id');
    }

    public function isOpenForWork(): bool
    {
        return in_array($this->status, [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Overdue], true);
    }
}
