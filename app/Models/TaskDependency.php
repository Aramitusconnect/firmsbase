<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TaskDependency — fields match the master plan PDF's appendix row
 * exactly. No own firm_id, no updated_at (append-only join record).
 * TaskDependencyService is the only place rows are created — it
 * rejects cycles at write time (project rule) before ever inserting.
 */
class TaskDependency extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'blocked_by_task_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function blockedByTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocked_by_task_id');
    }
}
