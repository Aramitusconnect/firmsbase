<?php

namespace App\Models;

use App\Enums\PlatformSalesTaskStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * PlatformSalesTask — platform sales follow-up task. Deliberately
 * distinct from Phase 4's Task model (legal/matter workflow), never
 * shares a table or model with it.
 */
class PlatformSalesTask extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'taskable_type',
        'taskable_id',
        'assigned_to',
        'created_by',
        'title',
        'status',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlatformSalesTaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by');
    }
}
