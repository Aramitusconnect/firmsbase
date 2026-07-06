<?php

namespace App\Models;

use App\Enums\DeletionRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * DeletionRequest — approved decision #9: firm_id + subject_type +
 * subject_id + subject_snapshot_json (not a fixed set of nullable FKs,
 * since deletion governance may target many record types over time).
 * Approved decision #1: terminal success status is ReadyForExecution —
 * this model, and Phase 17 generally, never performs the physical row
 * delete. Deletion of the DeletionRequest row itself is always blocked
 * (governance evidence).
 */
class DeletionRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'subject_type',
        'subject_id',
        'subject_snapshot_json',
        'reason',
        'status',
        'offboarding_export_id',
        'requested_by_type',
        'requested_by_id',
        'requested_at',
        'executed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_snapshot_json' => 'array',
            'status' => DeletionRequestStatus::class,
            'requested_at' => 'datetime',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('deletion_requests rows can never be deleted — they are permanent governance evidence.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function offboardingExport(): BelongsTo
    {
        return $this->belongsTo(OffboardingExport::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(DeletionApproval::class);
    }

    public function isReadyForExecution(): bool
    {
        return $this->status === DeletionRequestStatus::ReadyForExecution;
    }
}
