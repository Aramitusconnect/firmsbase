<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderKillSwitch — Global (not tenant-owned), no `uuid` column (the
 * design's own §4.1 schema block omits one — internal admin/ops
 * configuration only, never publicly addressed). No `BelongsToTenant` —
 * see the create migration's own docblock for the full RLS-classification
 * reasoning. `ProviderKillSwitchResource` (a later checkpoint's
 * PlatformAdmin UI concern) is the one place this table is writable.
 */
class ProviderKillSwitch extends Model
{
    use HasFactory;

    protected $table = 'provider_kill_switches';

    public const LEVEL_PRODUCT = 'product';

    public const LEVEL_ENDPOINT_CATEGORY = 'endpoint_category';

    public const LEVEL_OPERATION = 'operation';

    /**
     * Checkpoint 6 addition (cross-provider security/ops review): a
     * fourth, coarser, provider-agnostic level — "the entire provider is
     * disabled," `target` set to the provider key itself. Unlike
     * PRODUCT/ENDPOINT_CATEGORY/OPERATION (Plaid-billing-specific
     * granularity, checked only by ProviderOperationPolicyResolver via
     * the billing pipeline), this level is checked by
     * ProviderRequestExecutor::send() — the shared outbound path every
     * provider (Microsoft 365, Google Workspace, Plaid) routes through —
     * so it is the one kill-switch granularity that works uniformly for
     * every provider, including the two that have no billing-pipeline
     * concept at all.
     */
    public const LEVEL_PROVIDER = 'provider';

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_FIRM = 'firm';

    protected $fillable = [
        'provider_key',
        'scope_type',
        'scope_id',
        'level',
        'target',
        'suspended',
        'reason',
        'suspended_by',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'suspended_by');
    }
}
