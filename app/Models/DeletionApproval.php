<?php

namespace App\Models;

use App\Enums\HighRiskChangeRequestStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeletionApproval — mirrors KeyDestructionApproval exactly, but wraps
 * HighRiskChangeType::ProductionDataDeletion (the EXISTING case, reused
 * per approved decision #2).
 */
class DeletionApproval extends Model
{
    use HasFactory, HasPublicUuid;

    public $timestamps = false;

    protected $fillable = [
        'deletion_request_id',
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

            if (in_array($original, [HighRiskChangeRequestStatus::Approved->value, HighRiskChangeRequestStatus::Denied->value], true)) {
                throw new \LogicException('deletion_approvals rows are frozen once Approved or Denied — the outcome is irreversible.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('deletion_approvals rows can never be deleted.');
        });
    }

    public function deletionRequest(): BelongsTo
    {
        return $this->belongsTo(DeletionRequest::class);
    }

    public function highRiskChangeRequest(): BelongsTo
    {
        return $this->belongsTo(HighRiskChangeRequest::class);
    }
}
