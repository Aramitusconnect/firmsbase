<?php

namespace App\Enums;

/**
 * ConflictScope — organizations.conflict_scope. Governs whether
 * conflict-of-interest checking (a later-phase feature) is scoped per
 * firm or consolidated across every firm under one organization. Only
 * the enum/attribute is defined in Phase 1 — no conflict-checking
 * logic is built yet.
 */
enum ConflictScope: string
{
    case FirmScoped = 'firm_scoped';
    case OrganizationWide = 'organization_wide';
}
