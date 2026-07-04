<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * LicenseEvent — append-only audit trail covering BOTH FirmLicense and
 * OrgLicense transitions via a polymorphic licensable relation. No
 * uuid (internal audit log, mirrors firm_entitlement_events). No
 * BelongsToTenant — ownership is mixed (some rows belong to a firm via
 * FirmLicense, others to an organization via OrgLicense), so a single
 * firm_id tenant scope cannot safely apply here; this table is
 * deliberately excluded from Phase 6 RLS for the same reason.
 * event_type is a plain string (project convention for event-log
 * tables).
 */
class LicenseEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'licensable_type',
        'licensable_id',
        'event_type',
        'from_status',
        'to_status',
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

    public function licensable(): MorphTo
    {
        return $this->morphTo();
    }
}
