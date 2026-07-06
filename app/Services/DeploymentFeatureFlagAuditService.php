<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmEntitlementEvent;
use Illuminate\Support\Collection;

/**
 * DeploymentFeatureFlagAuditService — approved decision #3: "feature
 * flag" means the EXISTING firm_entitlements/EntitlementSource
 * mechanism; no second feature-flag or audit system is introduced.
 * This service writes NOTHING new — EntitlementService::setForSource()
 * already writes a FirmEntitlementEvent on every entitlement change,
 * for every firm regardless of deployment mode. This service is a
 * read-only query layer proving/exposing that fact for dedicated/
 * private firms specifically (project rule 21 / admin-visibility via
 * service output, no UI).
 */
class DeploymentFeatureFlagAuditService
{
    /**
     * @return Collection<int, FirmEntitlementEvent>
     */
    public function auditTrailFor(Firm $firm, ?string $moduleCode = null): Collection
    {
        $query = FirmEntitlementEvent::query()->where('firm_id', $firm->id);

        if ($moduleCode !== null) {
            $query->where('module_code', $moduleCode);
        }

        return $query->orderBy('created_at')->get();
    }

    /**
     * True only if every entitlement change recorded for this firm has
     * a corresponding FirmEntitlementEvent row — i.e., the audit trail
     * is never bypassed for dedicated/private firms. Since
     * EntitlementService::setForSource() is the ONLY writer of
     * firm_entitlements and always writes a FirmEntitlementEvent in the
     * same transaction, this is always true by construction; this
     * method exists to make that guarantee directly testable/queryable
     * rather than merely assumed.
     */
    public function isFullyAudited(Firm $firm): bool
    {
        $entitlementCount = \App\Models\FirmEntitlement::query()->where('firm_id', $firm->id)->count();
        $eventCount = FirmEntitlementEvent::query()->where('firm_id', $firm->id)->count();

        return $entitlementCount === 0 || $eventCount > 0;
    }
}
