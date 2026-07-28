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
 * FinancialEvidenceTransaction — FirmsVault Live Integrations,
 * Checkpoint 4. Materialized exclusively by
 * `App\Integrations\Support\FinancialEvidenceMaterializerService` from
 * Plaid's Transactions product (`/transactions/sync`) — never created
 * or updated from any other code path.
 *
 * IMMUTABLE, append-only evidentiary row — see
 * `FinancialEvidenceBankAccount`'s own class docblock for the full
 * "copies DocumentHash's booted()-guard idiom" reasoning, identical
 * here. A transaction Plaid later reports as `modified` (e.g. pending
 * -> posted) must not silently overwrite what the firm saw yesterday.
 */
class FinancialEvidenceTransaction extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_transactions';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'plaid_transaction_id',
        'plaid_account_id',
        'bank_account_id',
        'amount_cents',
        'iso_currency_code',
        'transaction_date',
        'posted_date',
        'merchant_name',
        'category_json',
        'pending',
        'provider_retrieved_at',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'posted_date' => 'date',
            'category_json' => 'array',
            'pending' => 'boolean',
            'provider_retrieved_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_transactions rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_transactions rows are immutable — an existing row can never be deleted.'
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

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceBankAccount::class, 'bank_account_id');
    }
}
