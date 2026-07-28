<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderBalanceSnapshot — Direct `BelongsToTenant`, FORCE RLS. One row
 * per (firm_integration_id, account_id), upserted only on a
 * `finalized_billable` Balance outcome by
 * `App\Integrations\Billing\ProviderLiveBalanceConfirmationService`
 * (checkpoint4-design-cost-control.md §5.3).
 */
class ProviderBalanceSnapshot extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'provider_balance_snapshots';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'account_id',
        'available_cents',
        'current_cents',
        'iso_currency_code',
        'retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'available_cents' => 'integer',
            'current_cents' => 'integer',
            'retrieved_at' => 'datetime',
        ];
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
