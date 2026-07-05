<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrustBalance — one cached balance row per TrustLedger. Only
 * TrustBalanceService may write balance_cents — "no silent balance
 * mutation" (project rule) is enforced by there being exactly one
 * method in the entire codebase allowed to write this column.
 */
class TrustBalance extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'trust_ledger_id',
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
}
