<?php

namespace App\Models;

use App\Enums\TrustLedgerStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TrustLedger — one client's IOLTA sub-ledger within a firm's pooled
 * TrustAccount. firm_id is non-nullable, so this model uses
 * BelongsToTenant.
 */
class TrustLedger extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'trust_account_id',
        'client_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrustLedgerStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function trustAccount(): BelongsTo
    {
        return $this->belongsTo(TrustAccount::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(TrustBalance::class);
    }

    public function matterBalances(): HasMany
    {
        return $this->hasMany(MatterTrustBalance::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TrustLedgerEntry::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(TrustTransferRequest::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(TrustRefundRequest::class);
    }

    public function isActive(): bool
    {
        return $this->status === TrustLedgerStatus::Active;
    }
}
