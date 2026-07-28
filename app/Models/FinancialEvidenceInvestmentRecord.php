<?php

declare(strict_types=1);

namespace App\Models;

use App\Integrations\Models\FirmIntegration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FinancialEvidenceInvestmentRecord — FirmsVault Live Integrations,
 * Checkpoint 4. Materialized exclusively by
 * `App\Integrations\Support\FinancialEvidenceMaterializerService` from
 * Plaid's Investments product (`/investments/holdings/get` +
 * `/investments/transactions/get`, merged with a `record_type`
 * discriminator) — never created or updated from any other code path.
 *
 * IMMUTABLE, append-only evidentiary row — see
 * `FinancialEvidenceBankAccount`'s own class docblock for the full
 * "copies DocumentHash's booted()-guard idiom" reasoning, identical
 * here.
 */
class FinancialEvidenceInvestmentRecord extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_investment_records';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'record_type',
        'plaid_external_id',
        'plaid_security_id',
        'plaid_investment_transaction_id',
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
                    'financial_evidence_investment_records rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_investment_records rows are immutable — an existing row can never be deleted.'
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
}
