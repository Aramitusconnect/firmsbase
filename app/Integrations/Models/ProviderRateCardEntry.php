<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderRateCardEntry — Global (not tenant-owned), see
 * database/migrations/2026_09_24_500001_create_provider_rate_card_entries_table.php
 * for the full RLS-classification reasoning. No `BelongsToTenant` —
 * mirrors `App\Models\Plan`'s own "platform reference/commercial data"
 * shape. `App\Integrations\Billing\ProviderRateCardResolver` is the
 * sole reader; mutation is admin-only (a later checkpoint's Filament
 * concern).
 */
class ProviderRateCardEntry extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'provider_key',
        'product',
        'billing_operation',
        'environment',
        'scope_type',
        'scope_id',
        'provider_cost_cents',
        'customer_price_cents',
        'currency',
        'included_allowance_quantity',
        'overage_price_cents',
        'unit',
        'effective_from',
        'effective_to',
        'created_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost_cents' => 'integer',
            'customer_price_cents' => 'integer',
            'included_allowance_quantity' => 'integer',
            'overage_price_cents' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpenEnded(): bool
    {
        return is_null($this->effective_to);
    }
}
