<?php

namespace App\Models;

use App\Enums\HighRiskChangeRequestStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KeyDestructionApproval — no firm_id of its own (scoped via
 * key_destruction_request_id). Wraps HighRiskPlatformChangePolicyService
 * via high_risk_change_request_id; status reuses the EXISTING
 * HighRiskChangeRequestStatus enum (no duplicate). Deletion is always
 * blocked. Mutation is blocked once a terminal outcome (Approved or
 * Denied) has been recorded — the approval workflow's own progression
 * writes (KeyDestructionApprovalService) happen before that point, but
 * once irreversible, the row freezes, matching the "must be irreversible
 * and fully audited" project rule for key destruction specifically.
 */
class KeyDestructionApproval extends Model
{
    use HasFactory, HasPublicUuid;

    public $timestamps = false;

    protected $fillable = [
        'key_destruction_request_id',
        'high_risk_change_request_id',
        'status',
        'first_approved_by',
        'first_approved_at',
        'second_approved_by',
        'second_approved_at',
        'denied_by',
        'denied_at',
        'denial_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => HighRiskChangeRequestStatus::class,
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $approval) {
            $original = $approval->getOriginal('status');

            if (in_array($original, [HighRiskChangeRequestStatus::Approved, HighRiskChangeRequestStatus::Denied], true)) {
                throw new \LogicException('key_destruction_approvals rows are frozen once Approved or Denied — the outcome is irreversible.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('key_destruction_approvals rows can never be deleted.');
        });
    }

    public function keyDestructionRequest(): BelongsTo
    {
        return $this->belongsTo(KeyDestructionRequest::class);
    }

    public function highRiskChangeRequest(): BelongsTo
    {
        return $this->belongsTo(HighRiskChangeRequest::class);
    }
}
