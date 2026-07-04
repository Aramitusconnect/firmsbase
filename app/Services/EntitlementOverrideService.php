<?php

namespace App\Services;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\User;

/**
 * EntitlementOverrideService — the ONLY place a firm_override or
 * admin_override entitlement row is written. A thin, validating wrapper
 * over the EXISTING EntitlementService::setForSource() — approved
 * decision: do not create a firm_entitlement_overrides table; the
 * existing firm_entitlements per-source design (with its
 * firm_entitlement_events audit trail) already is the standard override
 * machinery. This service exists only to enforce override-specific
 * rules (a reason is mandatory; only FirmOverride/AdminOverride sources
 * are accepted here) before delegating.
 */
class EntitlementOverrideService
{
    public function __construct(private EntitlementService $entitlementService)
    {
    }

    public function setOverride(
        Firm $firm,
        string $moduleCode,
        EntitlementSource $source,
        bool $enabled,
        string $reason,
        User $actor,
        ?\DateTimeInterface $endsAt = null,
    ): FirmEntitlement {
        if (! in_array($source, [EntitlementSource::FirmOverride, EntitlementSource::AdminOverride], true)) {
            throw new \InvalidArgumentException(
                'EntitlementOverrideService only accepts FirmOverride or AdminOverride sources.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An override reason is required.');
        }

        return $this->entitlementService->setForSource(
            firm: $firm,
            moduleCode: $moduleCode,
            source: $source,
            enabled: $enabled,
            actor: $actor,
            reason: $reason,
            endsAt: $endsAt,
        );
    }
}
