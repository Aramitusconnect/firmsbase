<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceDuplicateTransferFlag — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Written by
 * `FinancialEvidenceDuplicateTransferDetectionService`. Display-only,
 * FirmsVaultObservation provenance, never auto-posting anywhere.
 * Ordinary mutable workflow row (dismiss/confirm), not evidentiary
 * itself.
 */
class FinancialEvidenceDuplicateTransferFlag extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_duplicate_transfer_flags';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'transaction_id_a',
        'transaction_id_b',
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

    public function transactionA(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceTransaction::class, 'transaction_id_a');
    }

    public function transactionB(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceTransaction::class, 'transaction_id_b');
    }

    public function isOpen(): bool
    {
        return $this->dismissed_at === null && $this->confirmed_at === null;
    }
}
