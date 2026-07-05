<?php

namespace App\Models;

use App\Enums\TrustLedgerEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TrustLedgerEntry — append-only, no status column (approved correction
 * #5). No own uuid (pure evidentiary ledger row, mirrors
 * SignatureEvent/DocumentHash). $timestamps = false; only `posted_at`
 * exists. The model's own booted() hook throws \LogicException on ANY
 * update or delete of an existing row — the strictest reading of
 * "no silent edits, ever." The ONLY way to correct a posted entry is
 * TrustLedgerEntryReversalService creating a brand-new row referencing
 * this one via reverses_entry_id; this row's own fields never change.
 */
class TrustLedgerEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'trust_ledger_id',
        'matter_id',
        'entry_type',
        'amount_cents',
        'reverses_entry_id',
        'trust_approval_event_id',
        'trust_transfer_request_id',
        'trust_refund_request_id',
        'source_payment_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => TrustLedgerEntryType::class,
            'amount_cents' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'trust_ledger_entries is append-only: an existing row can never be updated. Post a Reversal entry instead.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'trust_ledger_entries is append-only: an existing row can never be deleted. Post a Reversal entry instead.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function trustLedger(): BelongsTo
    {
        return $this->belongsTo(TrustLedger::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function approvalEvent(): BelongsTo
    {
        return $this->belongsTo(TrustApprovalEvent::class, 'trust_approval_event_id');
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TrustTransferRequest::class, 'trust_transfer_request_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(TrustRefundRequest::class, 'trust_refund_request_id');
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }
}
