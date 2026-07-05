<?php

namespace App\Enums;

/**
 * EmailVisibilityScope — email_visibility_rules.visibility_scope. A
 * small, fixed set resolved by EmailVisibilityPolicyService — this is
 * deliberately NOT a flexible per-user grant system (project rule:
 * permission checks are role allowlists, not a generic ACL engine).
 * OwnerOnly is the hard default whenever no rule row exists at all.
 */
enum EmailVisibilityScope: string
{
    case OwnerOnly = 'owner_only';
    case MatterTeam = 'matter_team';
    case FirmWide = 'firm_wide';
}
