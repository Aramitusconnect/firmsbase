<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceLargeDepositFlag — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Written by
 * `FinancialEvidenceLargeDepositDetectionService`. Display-only,
 * FirmsVaultObservation provenance.
 */
class FinancialEvidenceLargeDepositFlag extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_large_deposit_flags';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'transaction_id',
        'threshold_cents_applied',
        'detected_at',
        'dismissed_at',
        'dismissed_by_firm_user_id',
        'confirmed_at',
        'confirmed_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceTransaction::class, 'transaction_id');
    }

    public function isOpen(): bool
    {
        return $this->dismissed_at === null && $this->confirmed_at === null;
    }
}
