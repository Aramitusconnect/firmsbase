<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FinancialEvidenceSnapshot — FirmsVault Live Integrations, Checkpoint
 * 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.9). Immutable, one
 * row per snapshot-creation event — every required field from the
 * spec's own table mapped one-to-one to a column. Report Export MUST
 * originate from an existing row here, never a live re-query.
 *
 * Copies the `DocumentHash`/`TrustLedgerEntry` `booted()`-guard
 * immutability idiom — an existing row can never be updated or
 * deleted.
 */
class FinancialEvidenceSnapshot extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    public $timestamps = false;

    protected $table = 'financial_evidence_snapshots';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'generated_by_firm_user_id',
        'consent_id',
        'authorized_source_json',
        'authorized_account_ids_json',
        'date_range_start',
        'date_range_end',
        'retrieved_record_refs_json',
        'provider_retrieved_at',
        'redacted_request_reference',
        'source_product',
        'report_version',
        'checksum',
        'checksum_source',
        'limitations_text',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized_source_json' => 'array',
            'authorized_account_ids_json' => 'array',
            'date_range_start' => 'date',
            'date_range_end' => 'date',
            'retrieved_record_refs_json' => 'array',
            'provider_retrieved_at' => 'datetime',
            'report_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_snapshots rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_snapshots rows are immutable — an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'generated_by_firm_user_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvidenceClientConsent::class, 'consent_id');
    }
}
