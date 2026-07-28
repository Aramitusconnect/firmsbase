<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * FinancialEvidenceBankAccount — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Materialized exclusively
 * by `App\Integrations\Support\FinancialEvidenceMaterializerService`
 * from Plaid's Auth product (`/auth/get`) — never created or updated
 * from any other code path.
 *
 * IMMUTABLE, append-only evidentiary row — copies `App\Models\DocumentHash`'s
 * `booted()`-guard idiom exactly: an existing row can never be updated
 * or deleted. A resync that detects a changed external account creates
 * a NEW row and tombstones the OLD `IntegrationExternalMapping` row
 * that pointed to the superseded one (never an in-place update here).
 */
class FinancialEvidenceBankAccount extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_bank_accounts';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'plaid_account_id',
        'account_name',
        'account_subtype',
        'mask',
        'classification',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_bank_accounts rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_bank_accounts rows are immutable — an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialEvidenceTransaction::class, 'bank_account_id');
    }
}
