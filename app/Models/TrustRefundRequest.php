<?php

namespace App\Models;

use App\Enums\TrustRefundRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrustRefundRequest extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'trust_ledger_id',
        'matter_id',
        'amount_cents',
        'status',
        'requested_by_firm_user_id',
        'approved_by_firm_user_id',
        'completed_at',
        'denied_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrustRefundRequestStatus::class,
            'amount_cents' => 'integer',
            'completed_at' => 'datetime',
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
        return $this->hasOne(TrustLedgerEntry::class, 'trust_refund_request_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            TrustRefundRequestStatus::Completed,
            TrustRefundRequestStatus::Denied,
            TrustRefundRequestStatus::Cancelled,
        ], true);
    }
}
