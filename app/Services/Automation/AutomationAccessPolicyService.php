<?php

namespace App\Services\Automation;

use App\Enums\FirmUserRole;

/**
 * AutomationAccessPolicyService — Event-Driven Automation Engine, item
 * 17. Who may create/edit/enable/disable an AutomationRule. No
 * established precedent exists in this codebase for "who configures
 * automation-like tooling," so this reuses the SAME three roles
 * PaymentAccessPolicyService::RECORD_PAYMENT_ROLES already trusts with
 * a comparable class of consequential business-configuration decision
 * (FirmOwner, Attorney, BillingStaff) rather than inventing a new
 * ceiling. Deliberately separate from — and broader than —
 * AutomationApprovalService's own FirmOwner-only gate: approving a
 * single high-risk action in the moment is a narrower, higher-stakes
 * decision than authoring the rule that could someday propose one.
 */
class AutomationAccessPolicyService
{
    private const MANAGE_RULES_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    public function canManageRules(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_RULES_ROLES, true);
    }
}
