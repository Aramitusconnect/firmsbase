<?php

namespace App\Models;

use App\Enums\TrustTransferRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TrustTransferRequest — the trust-to-invoice application workflow
 * root. Only TrustTransferRequestService writes this table.
 */
class TrustTransferRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'trust_ledger_id',
        'matter_id',
        'invoice_id',
        'amount_cents',
        'status',
        'requested_by_firm_user_id',
        'approved_by_firm_user_id',
        'applied_at',
        'denied_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrustTransferRequestStatus::class,
            'amount_cents' => 'integer',
            'applied_at' => 'datetime',
        ];
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'requested_by_firm_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'approved_by_firm_user_id');
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(TrustLedgerEntry::class, 'trust_transfer_request_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            TrustTransferRequestStatus::Applied,
            TrustTransferRequestStatus::Denied,
            TrustTransferRequestStatus::Cancelled,
        ], true);
    }
}
