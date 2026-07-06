<?php

namespace App\Enums;

/**
 * GovernanceGapSeverity — the closed set of severities a
 * ComplianceGapRegistryService item may hold. Declarative only.
 */
enum GovernanceGapSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
