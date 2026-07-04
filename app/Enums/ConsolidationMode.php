<?php

namespace App\Enums;

/**
 * ConsolidationMode — organizations.consolidation_mode. Governs whether
 * firms under one organization are billed/reported independently or
 * consolidated. Attribute only in Phase 1 — no billing consolidation
 * logic is built yet (that is Phase 6+, platform billing territory,
 * kept strictly separate from firm-client billing).
 */
enum ConsolidationMode: string
{
    case Independent = 'independent';
    case Consolidated = 'consolidated';
}
