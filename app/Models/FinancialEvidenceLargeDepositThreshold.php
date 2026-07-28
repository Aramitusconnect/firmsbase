<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FinancialEvidenceLargeDepositThreshold — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7;
 * checkpoint4-combined-design.md §1.6). GLOBAL — no `BelongsToTenant`
 * trait (this table carries no `firm_id` column at all; `scope_id`
 * merely POINTS AT a firm for `firm_override` rows without the row
 * itself being tenant-owned, identical to `provider_rate_card_entries`'s
 * own reasoning).
 */
class FinancialEvidenceLargeDepositThreshold extends Model
{
    use HasFactory;

    protected $table = 'financial_evidence_large_deposit_thresholds';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'threshold_cents',
    ];

    public static function platformDefault(): ?self
    {
        return static::query()->where('scope_type', 'platform_default')->first();
    }

    public static function firmOverrideFor(int $firmId): ?self
    {
        return static::query()->where('scope_type', 'firm_override')->where('scope_id', $firmId)->first();
    }
}
