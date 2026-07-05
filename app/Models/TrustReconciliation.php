<?php

namespace App\Models;

use App\Enums\TrustReconciliationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TrustReconciliation — a periodic, firm-initiated snapshot. Discrepancy
 * is never auto-corrected by any service (project rule).
 */
class TrustReconciliation extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'trust_account_id',
        'period_start',
        'period_end',
        'system_balance_cents',
        'asserted_bank_balance_cents',
        'discrepancy_cents',
        'status',
        'performed_by_firm_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'system_balance_cents' => 'integer',
            'asserted_bank_balance_cents' => 'integer',
            'discrepancy_cents' => 'integer',
            'status' => TrustReconciliationStatus::class,
            'completed_at' => 'datetime',
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

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'performed_by_firm_user_id');
    }

    public function isBalanced(): bool
    {
        return $this->status === TrustReconciliationStatus::Balanced;
    }
}
