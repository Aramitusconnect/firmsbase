<?php

namespace App\Models;

use App\Enums\AccountingExportLineStatus;
use App\Enums\AccountingExportSourceRecordType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AccountingExportLine — one row per exported (or attempted-export)
 * source record. firm_id is present as a direct column for defense-in-
 * depth queries (mirrors signature_events' precedent) but this model
 * does NOT use BelongsToTenant, since the row's real ownership root is
 * accounting_export_batch_id, not firm_id directly (TenantSafeAccountingPolicyService
 * provides the explicit cross-firm assertion instead).
 *
 * Immutable once its status leaves Pending: only
 * AccountingExportSimulationService may transition Pending -> Exported
 * or Pending -> Failed, exactly once (correction #9 — "exported lines
 * cannot be modified after exported except through the approved local
 * simulation service before finalization" — enforced here by rejecting
 * any update to an already-non-Pending row's status).
 */
class AccountingExportLine extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'accounting_export_batch_id',
        'firm_id',
        'source_record_type',
        'invoice_id',
        'payment_id',
        'expense_id',
        'chart_of_accounts_id',
        'mapped_amount_cents',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'source_record_type' => AccountingExportSourceRecordType::class,
            'status' => AccountingExportLineStatus::class,
            'mapped_amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            $originalStatus = $line->getRawOriginal('status');

            if ($originalStatus !== AccountingExportLineStatus::Pending->value
                && $originalStatus !== null
                && $line->isDirty('status')) {
                throw new \LogicException(
                    'An accounting_export_lines row can only transition out of Pending once; it cannot be re-exported or re-failed.'
                );
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingExportBatch::class, 'accounting_export_batch_id');
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_accounts_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(AccountingExportError::class);
    }
}
