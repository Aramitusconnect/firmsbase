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
