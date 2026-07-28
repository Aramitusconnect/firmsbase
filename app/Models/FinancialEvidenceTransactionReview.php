<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FinancialEvidenceTransactionReview — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.6.1). Deliberately
 * SEPARATE from the immutable `FinancialEvidenceTransaction` row
 * (provenance split: fact vs. review). Append-only — a re-review
 * creates a NEW row rather than editing the old one; ordinary mutable
 * workflow rows are not used here on purpose, since the append-only
 * shape is what preserves "who said what and when."
 */
class FinancialEvidenceTransactionReview extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_transaction_reviews';

    protected $fillable = [
        'firm_id',
        'transaction_id',
        'reviewed_by_firm_user_id',
        'reviewed_at',
        'flagged',
        'flag_reason',
        'classification',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'flagged' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceTransaction::class, 'transaction_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reviewed_by_firm_user_id');
    }
}
