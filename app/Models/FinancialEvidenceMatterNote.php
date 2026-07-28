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
 * FinancialEvidenceMatterNote — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.8). Append-only
 * Attorney Notes — no `updated_at` edit path, matching
 * `TrustLedgerEntry`'s `$timestamps = false`/`booted()`-guard idiom
 * exactly since a note is evidentiary once written. Provenance is
 * always `AttorneyConfirmedClassification`. NO Client Portal read path
 * anywhere (checkpoint4-combined-design.md §4.12) — structural, not a
 * permission check.
 */
class FinancialEvidenceMatterNote extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    public $timestamps = false;

    protected $table = 'financial_evidence_matter_notes';

    protected $fillable = [
        'firm_id',
        'matter_id',
        'author_firm_user_id',
        'body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->exists) {
                throw new LogicException(
                    'financial_evidence_matter_notes rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $model): void {
            throw new LogicException(
                'financial_evidence_matter_notes rows are immutable — an existing row can never be deleted.'
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'author_firm_user_id');
    }
}
