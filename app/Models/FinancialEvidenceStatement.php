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
 * FinancialEvidenceStatement — FirmsVault Live Integrations,
 * Checkpoint 4. Materialized exclusively by
 * `App\Integrations\Support\FinancialEvidenceMaterializerService` from
 * Plaid's Statements product (`/statements/list`) — never created or
 * updated from any other code path. `storage_disk`/`storage_path`
 * remain null until a later, separate download action persists the
 * binary PDF (out of this model's own materialization scope).
 *
 * IMMUTABLE, append-only evidentiary row — see
 * `FinancialEvidenceBankAccount`'s own class docblock for the full
 * "copies DocumentHash's booted()-guard idiom" reasoning, identical
 * here.
 */
class FinancialEvidenceStatement extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_statements';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'plaid_statement_id',
        'month',
        'year',
        'available_date',
        'storage_disk',
        'storage_path',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'available_date' => 'date',
            'raw_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_statements rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_statements rows are immutable — an existing row can never be deleted.'
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
