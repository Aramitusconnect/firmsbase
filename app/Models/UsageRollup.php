<?php

namespace App\Models;

use App\Enums\UsageRollupMetric;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UsageRollup — keyed to billing_account_id (project rule 11). A row
 * with firm_id = null is the billing-account/organization-level
 * aggregate for that metric/period; a row with firm_id set is one
 * member firm's contribution. firm_id here is attribution, not a
 * tenant boundary — no BelongsToTenant, no Phase 6 RLS.
 */
class UsageRollup extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'billing_account_id',
        'firm_id',
        'metric',
        'period_starts_at',
        'period_ends_at',
        'quantity',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'metric' => UsageRollupMetric::class,
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isAccountLevelAggregate(): bool
    {
        return $this->firm_id === null;
    }
}
