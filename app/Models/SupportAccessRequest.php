<?php

namespace App\Models;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SupportAccessRequest — request-based, reason-required, firm-approved
 * unless emergency. approved_by/denied_by are FirmUser (the FIRM'S OWN
 * approver), never PlatformAdmin — a platform admin cannot approve
 * access into a firm on the firm's behalf except via the emergency
 * path, which is governed separately by SupportAccessPolicyService.
 */
class SupportAccessRequest extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'requested_by',
        'access_type',
        'reason',
        'status',
        'approved_by',
        'denied_by',
        'approved_at',
        'denied_at',
        'requested_duration_minutes',
        'emergency_justification',
    ];

    protected function casts(): array
    {
        return [
            'access_type' => SupportAccessType::class,
            'status' => SupportAccessRequestStatus::class,
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'approved_by');
    }

    public function deniedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'denied_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SupportAccessSession::class);
    }

    public function isEmergency(): bool
    {
        return $this->access_type === SupportAccessType::Emergency;
    }
}
