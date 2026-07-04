<?php

namespace App\Models;

use App\Enums\BackupRestoreTestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BackupRestoreTest — firm_id is NULLABLE (null = platform-wide drill,
 * non-null = one firm's tenant data was specifically verified).
 * Deliberately does NOT use BelongsToTenant for the same reason as
 * HealthCheck — a nullable firm_id would be hidden by that trait's
 * global scope whenever a tenant context is active. No real
 * infrastructure backup/restore happens here (project rule) — this
 * table records the RESULT of a BackupRestoreDrillRunner.
 */
class BackupRestoreTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'firm_id',
        'status',
        'components_verified_json',
        'rpo_target_seconds',
        'rto_target_seconds',
        'rpo_actual_seconds',
        'rto_actual_seconds',
        'started_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => BackupRestoreTestStatus::class,
            'components_verified_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * "RPO target: maximum 24 hours... RTO target: maximum 8 hours...
     * before paid launch unless a stricter target is approved" (PDF).
     * True only once the drill has actually completed and both actuals
     * are within their configured targets.
     */
    public function meetsTargets(): bool
    {
        if ($this->status !== BackupRestoreTestStatus::Passed) {
            return false;
        }

        if (is_null($this->rpo_actual_seconds) || is_null($this->rto_actual_seconds)) {
            return false;
        }

        return $this->rpo_actual_seconds <= $this->rpo_target_seconds
            && $this->rto_actual_seconds <= $this->rto_target_seconds;
    }
}
