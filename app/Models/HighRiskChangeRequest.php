<?php

namespace App\Models;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HighRiskChangeRequest — approval-state foundation only. No
 * "executed" status exists on this model, and nothing in this project
 * ever transitions a HighRiskChangeRequest into performing trust mode
 * activation, production data deletion, or trust/IOLTA money movement.
 * HighRiskPlatformChangePolicyService is the only writer.
 */
class HighRiskChangeRequest extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'change_type',
        'status',
        'reason',
        'requested_by',
        'first_approved_by',
        'first_approved_at',
        'second_approved_by',
        'second_approved_at',
        'denied_by',
        'denied_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'change_type' => HighRiskChangeType::class,
            'status' => HighRiskChangeRequestStatus::class,
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by');
    }

    public function firstApprovedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'first_approved_by');
    }

    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'second_approved_by');
    }

    public function deniedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'denied_by');
    }

    /**
     * emergency_support_access is the one change type that, per the
     * approved plan, does not require a second approver — the
     * emergency path is itself the governance mechanism (see
     * SupportAccessPolicyService). All other change types require
     * both approvals.
     */
    public function requiresSecondApproval(): bool
    {
        return $this->change_type !== HighRiskChangeType::EmergencySupportAccess;
    }
}
