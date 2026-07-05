<?php

namespace App\Enums;

/**
 * FormMappingContentStatus — form_mapping_rules.content_status. The
 * exact mechanism behind "do not hardcode production USCIS field maps
 * unless explicitly marked reviewed/approved" (project rule).
 * SampleOnly is the default for every new rule; only
 * FormMappingRuleService::approveContent(), which requires a
 * PlatformAdmin actor, can move a rule to ReviewedApproved. A form
 * draft's approval is blocked while any mapping rule it used is still
 * SampleOnly (checked live at approval time, not from a stale flag).
 */
enum FormMappingContentStatus: string
{
    case SampleOnly = 'sample_only';
    case ReviewedApproved = 'reviewed_approved';
}
