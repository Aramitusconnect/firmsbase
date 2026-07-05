<?php

namespace App\Models;

use App\Enums\TrustApprovalEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrustApprovalEvent — append-only. No own uuid (mirrors SignatureEvent).
 * Carries STRUCTURED columns (amount_cents, matter_id, approved_entry_type,
 * trust_ledger_id) — not metadata-only — so a deposit approval can be
 * matched exactly by TrustDepositService (correction #3). Immutable via
 * booted() guard, same mechanism as every other append-only log in this
 * codebase.
 */
class TrustApprovalEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'event_type',
        'actor_firm_user_id',
        'amount_cents',
        'matter_id',
        'approved_entry_type',
        'correlation_uuid',
        'trust_ledger_id',
        'trust_transfer_request_id',
        'trust_refund_request_id',
        'high_risk_change_request_id',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => TrustApprovalEventType::class,
            'amount_cents' => 'integer',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('trust_approval_events is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('trust_approval_events is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function trustLedger(): BelongsTo
    {
        return $this->belongsTo(TrustLedger::class);
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TrustTransferRequest::class, 'trust_transfer_request_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(TrustRefundRequest::class, 'trust_refund_request_id');
    }

    /**
     * Read-only reference into the EXISTING Phase 7 table. This model
     * never writes to high_risk_change_requests.
     */
    public function highRiskChangeRequest(): BelongsTo
    {
        return $this->belongsTo(HighRiskChangeRequest::class);
    }
}
