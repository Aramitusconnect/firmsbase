<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterTrustBalance — the per-matter attribution of a client's
 * TrustLedger balance. No own uuid (accessed only through its parent
 * Matter/TrustLedger, mirrors Phase 12's MatterExpense). firm_id IS
 * present as a direct column for TenantSafeTrustPolicyService's
 * defense-in-depth checks, but this model does NOT use BelongsToTenant
 * (mirrors the Phase 8-12 precedent for firm_id-bearing child rows).
 * Only TrustBalanceService may write balance_cents. This row must
 * never go negative — the entire mechanism behind "no cross-matter use
 * of trust funds" (project rule).
 */
class MatterTrustBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'firm_id',
        'trust_ledger_id',
        'matter_id',
        'balance_cents',
        'last_recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'last_recomputed_at' => 'datetime',
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
}
