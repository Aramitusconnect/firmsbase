<?php

namespace App\Enums;

/**
 * EntitlementSource — firm_entitlements.source. Precedence (highest
 * wins): admin_override > firm_override > org_inherited > plan.
 * Implemented as a method (not array order) so the ranking is directly
 * testable and cannot silently drift if cases are ever reordered.
 * Entitlements are the sole grant mechanism — feature flags may only
 * restrict what an entitlement already grants, never widen it.
 */
enum EntitlementSource: string
{
    case AdminOverride = 'admin_override';
    case FirmOverride = 'firm_override';
    case OrgInherited = 'org_inherited';
    case Plan = 'plan';

    public function precedence(): int
    {
        return match ($this) {
            self::AdminOverride => 4,
            self::FirmOverride => 3,
            self::OrgInherited => 2,
            self::Plan => 1,
        };
    }
}
