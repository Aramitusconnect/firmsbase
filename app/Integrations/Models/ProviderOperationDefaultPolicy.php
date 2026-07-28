<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderOperationDefaultPolicy — the GLOBAL, no-RLS half of the
 * coordinator-resolved two-table split (checkpoint4-combined-design.md
 * §1.8). One row per (provider_key, product, environment) — the
 * platform-default fallback `App\Integrations\Billing\ProviderOperationPolicyResolver`
 * reads on a firm-scope miss.
 */
class ProviderOperationDefaultPolicy extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'provider_operation_default_policies';

    protected $fillable = [
        'provider_key',
        'product',
        'environment',
        'soft_limit_quantity',
        'hard_limit_quantity',
        'limit_window_seconds',
        'cooldown_seconds',
        'cache_ttl_seconds',
        'created_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'soft_limit_quantity' => 'integer',
            'hard_limit_quantity' => 'integer',
            'limit_window_seconds' => 'integer',
            'cooldown_seconds' => 'integer',
            'cache_ttl_seconds' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
