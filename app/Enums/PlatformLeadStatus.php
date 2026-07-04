<?php

namespace App\Enums;

/**
 * PlatformLeadStatus — platform_leads.status. Platform sales pipeline
 * only. Deliberately unrelated to Phase 2's FirmLeadStatus (client
 * intake leads) — never shared or reused, per the Phase 7 naming-
 * conflict decision.
 */
enum PlatformLeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Disqualified = 'disqualified';
    case Converted = 'converted';
    case Lost = 'lost';
}
