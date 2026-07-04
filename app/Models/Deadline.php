<?php

namespace App\Models;

use App\Enums\DeadlineStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deadline — deadline_type is a plain string, not a rigid enum (legal
 * deadline types vary too much by practice area/jurisdiction to
 * enumerate in the core schema). No reminder_policy_id — see this
 * table's migration doc comment for why; reminder_offsets_days is
 * stored directly on the row instead. No uuid — internal/staff-facing
 * only in Phase 4.
 */
class Deadline extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'title',
        'deadline_type',
        'due_at',
        'jurisdiction',
        'source',
        'reminder_offsets_days',
        'status',
        'completed_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'reminder_offsets_days' => 'array',
            'status' => DeadlineStatus::class,
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
