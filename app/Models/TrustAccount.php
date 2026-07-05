<?php

namespace App\Models;

use App\Enums\TrustAccountStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TrustAccount — the firm-owned root of the IOLTA trust foundation.
 * firm_id is non-nullable, so this model uses BelongsToTenant.
 */
class TrustAccount extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'account_name',
        'bank_name_reference',
        'status',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TrustAccountStatus::class,
            'opened_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(TrustLedger::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(TrustReconciliation::class);
    }
}
