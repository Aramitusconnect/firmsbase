<?php

namespace App\Services;

use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;

/**
 * AccountingEntitlementPolicyService — the single gate every Phase 12
 * service calls before any read/write/export/report/invoice-
 * reimbursement action (correction #6). Reuses the existing `expenses`
 * module_catalog row (seeded in Phase 6) and the existing
 * EntitlementService four-source resolution completely unchanged — no
 * second entitlement mechanism, no new module_catalog row.
 *
 * The firm-level "may reimbursable expenses appear on invoices" toggle
 * is read from the SAME expenses entitlement's settings_json (key
 * reimbursable_expenses_on_invoices_enabled), mirroring Phase 11's
 * annotations_enabled pattern exactly — no new column on
 * firms/firm_settings, no new table.
 */
class AccountingEntitlementPolicyService
{
    private const MODULE_CODE = 'expenses';

    private const APPROVER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::BillingStaff,
    ];

    public function __construct(private readonly EntitlementService $entitlementService) {}

    public function isExpensesEnabledForFirm(Firm $firm): bool
    {
        return $this->entitlementService->isEnabled($firm->id, self::MODULE_CODE);
    }

    public function assertExpensesEnabled(Firm $firm): void
    {
        if (! $this->isExpensesEnabledForFirm($firm)) {
            throw new \RuntimeException('Expenses module is disabled for this firm.');
        }
    }

    /**
     * Approver role set fixed to FirmOwner/BillingStaff (correction
     * #5). Attorneys, paralegals, legal assistants, and receptionists
     * may not approve expenses unless a later permission layer adds
     * that — this method is the single place that decision is made.
     */
    public function canApprove(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    public function assertCanApprove(FirmUser $firmUser): void
    {
        if (! $this->canApprove($firmUser->role)) {
            throw new \RuntimeException('Only FirmOwner or BillingStaff may approve expenses.');
        }
    }

    /**
     * FirmsVault staging follow-up addition ("Application Completion —
     * Catalogs + Firm-Owned Reference Data"). Gates the new Firm
     * Management "Expense Categories" page (create/edit/activate/
     * deactivate). Reuses APPROVER_ROLES unchanged — whoever is
     * authorized to approve an expense against a category is the same
     * "appropriately authorized billing role" this mission's own spec
     * names for managing the category list itself.
     */
    public function canManageExpenseCategories(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    /**
     * The firm-level reimbursement toggle, read from the SAME expenses
     * entitlement row's settings_json — not a new firms/firm_settings
     * column. Returns false whenever the module itself is disabled,
     * even if a stray settings_json flag were somehow true.
     */
    public function reimbursableExpensesOnInvoicesEnabled(Firm $firm): bool
    {
        $resolution = $this->entitlementService->resolve($firm->id, self::MODULE_CODE);

        if (! $resolution->enabled) {
            return false;
        }

        return (bool) ($resolution->settings['reimbursable_expenses_on_invoices_enabled'] ?? false);
    }
}
