<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderFirmOperationPolicy — the FIRM-EDITABLE, Direct
 * `BelongsToTenant` + FORCE RLS half of the coordinator-resolved
 * two-table split (checkpoint4-combined-design.md §1.8). One row per
 * firm/product/environment override, including this firm's own
 * self-service `optional_operation_suspended` opt-out — see the create
 * migration's own docblock.
 */
class ProviderFirmOperationPolicy extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'provider_firm_operation_policies';

    protected $fillable = [
        'firm_id',
        'provider_key',
        'product',
        'environment',
        'optional_operation_suspended',
        'soft_limit_quantity',
        'hard_limit_quantity',
        'limit_window_seconds',
        'cooldown_seconds',
        'cache_ttl_seconds',
        'updated_by_firm_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'optional_operation_suspended' => 'boolean',
            'soft_limit_quantity' => 'integer',
            'hard_limit_quantity' => 'integer',
            'limit_window_seconds' => 'integer',
            'cooldown_seconds' => 'integer',
            'cache_ttl_seconds' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function updatedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'updated_by_firm_user_id');
    }
}
