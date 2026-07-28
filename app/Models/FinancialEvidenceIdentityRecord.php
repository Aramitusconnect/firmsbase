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
 * FinancialEvidenceIdentityRecord — FirmsVault Live Integrations,
 * Checkpoint 4. Materialized exclusively by
 * `App\Integrations\Support\FinancialEvidenceMaterializerService` from
 * Plaid's Identity product (`/identity/get`) — never created or updated
 * from any other code path. Only the names array is guaranteed
 * populated by Plaid; every other owner-detail array may legitimately
 * be empty, hence all four are nullable.
 *
 * IMMUTABLE, append-only evidentiary row — see
 * `FinancialEvidenceBankAccount`'s own class docblock for the full
 * "copies DocumentHash's booted()-guard idiom" reasoning, identical
 * here.
 */
class FinancialEvidenceIdentityRecord extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'financial_evidence_identity_records';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'plaid_account_id',
        'owner_names_json',
        'owner_emails_json',
        'owner_phones_json',
        'owner_addresses_json',
        'raw_json',
    ];

    protected function casts(): array
    {
        return [
            'owner_names_json' => 'array',
            'owner_emails_json' => 'array',
            'owner_phones_json' => 'array',
            'owner_addresses_json' => 'array',
            'raw_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_identity_records rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_identity_records rows are immutable — an existing row can never be deleted.'
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
