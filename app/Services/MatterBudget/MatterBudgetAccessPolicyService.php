<?php

namespace App\Services\MatterBudget;

use App\Enums\FirmUserRole;

/**
 * MatterBudgetAccessPolicyService — Predictive Matter Budget Alerts,
 * item 21/22. Splits visibility into two distinct tiers per the
 * spec's own explicit instruction ("separate operational budget
 * visibility from internal profitability visibility"):
 *
 * - OPERATIONAL (hours/expense/progress consumption, no dollar
 *   margin/internal-cost figures): FirmOwner, Attorney, BillingStaff,
 *   Paralegal, LegalAssistant. The spec names Paralegal explicitly
 *   ("may see role/time budget if product policy supports it");
 *   LegalAssistant is not named either way, and is included here as a
 *   reasoned, deliberate choice — a similar assistant-tier role doing
 *   the same kind of time-tracked work, granted the same operational
 *   (never profitability) visibility. Receptionist is granted neither
 *   tier — the spec's own "normally no profitability access" is
 *   applied conservatively to operational visibility too, since
 *   Receptionist is never mentioned as needing budget visibility at
 *   all.
 * - PROFITABILITY (margin, internal labor cost, EmployeeRate.cost_rate_cents,
 *   revenue figures): FirmOwner, Attorney, BillingStaff only — exactly
 *   the three roles the spec explicitly names for "budget/profitability
 *   visibility."
 *
 * Template/matter-budget MANAGEMENT (create/edit/deactivate a template;
 * apply/revise a Matter's own budget) reuses the exact same three-role
 * set AutomationAccessPolicyService::canManageRules() already
 * established for "who configures this kind of firm-wide financial/
 * workflow tooling" (FirmOwner, Attorney, BillingStaff) — the spec's
 * own "Attorney: ...perhaps revision where permitted" is exercised
 * here as an actual grant, not left as a maybe.
 */
class MatterBudgetAccessPolicyService
{
    private const MANAGE_ROLES = [FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff];

    private const OPERATIONAL_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const PROFITABILITY_VIEW_ROLES = [FirmUserRole::FirmOwner, FirmUserRole::Attorney, FirmUserRole::BillingStaff];

    public function canManageTemplates(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_ROLES, true);
    }

    public function canReviseMatterBudget(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGE_ROLES, true);
    }

    public function canViewOperationalBudget(FirmUserRole $role): bool
    {
        return in_array($role, self::OPERATIONAL_VIEW_ROLES, true);
    }

    public function canViewProfitability(FirmUserRole $role): bool
    {
        return in_array($role, self::PROFITABILITY_VIEW_ROLES, true);
    }
}
