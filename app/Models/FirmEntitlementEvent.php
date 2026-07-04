<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmEntitlementEvent — append-only audit trail for entitlement
 * changes. No uuid, UPDATED_AT disabled.
 */
class FirmEntitlementEvent extends Model
{
    use HasFactory, BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_entitlement_id',
        'firm_id',
        'module_code',
        'source',
        'action',
        'reason',
        'actor_type',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function firmEntitlement(): BelongsTo
    {
        return $this->belongsTo(FirmEntitlement::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
