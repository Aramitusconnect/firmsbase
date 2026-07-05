<?php

namespace App\Models;

use App\Enums\TrustChargebackStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrustChargebackEvent — records the externally-reported fact of a
 * chargeback and its resolution lifecycle (Reported -> Reversed ->
 * Resolved). Unlike TrustLedgerEntry/TrustApprovalEvent, this table is
 * NOT append-only — it has a real, narrow lifecycle of its own
 * (reversal_trust_ledger_entry_id and resolved_at are set later) — but
 * it never mutates the ORIGINAL trust_ledger_entries row it references.
 */
class TrustChargebackEvent extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'original_trust_ledger_entry_id',
        'reversal_trust_ledger_entry_id',
        'amount_cents',
        'reason',
        'status',
        'reported_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrustChargebackStatus::class,
            'amount_cents' => 'integer',
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function originalEntry(): BelongsTo
    {
        return $this->belongsTo(TrustLedgerEntry::class, 'original_trust_ledger_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(TrustLedgerEntry::class, 'reversal_trust_ledger_entry_id');
    }
}
