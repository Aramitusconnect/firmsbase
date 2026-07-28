<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceReconciliationCandidate — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). EXPLICITLY NEVER
 * AUTO-POSTS TO THE TRUST LEDGER — display-only, attorney-decision-
 * driven. `trust_ledger_entry_id` is a bare, unconstrained,
 * READ-ONLY-BY-CONVENTION integer — this model deliberately does NOT
 * define a `trustLedgerEntry()` relation or import `TrustLedgerEntry`
 * at all, keeping this class fully decoupled from the trust domain at
 * the code level, not merely policy level. Any lookup for display is
 * the sole responsibility of the panel/service that reads this row,
 * via a plain `TrustLedgerEntry::query()->find()` call — never through
 * this model.
 */
class FinancialEvidenceReconciliationCandidate extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_reconciliation_candidates';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'transaction_id',
        'trust_ledger_entry_id',
        'match_confidence',
        'status',
        'reviewed_by_firm_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
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

    public function isCandidate(): bool
    {
        return $this->status === 'candidate';
    }
}
