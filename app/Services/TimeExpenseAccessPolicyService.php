<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * TimeExpenseAccessPolicyService — role ceiling for the Time Entry /
 * Timer / Expense cluster (Firm Feature Manifest §6, Tier1-C). Same
 * plain in_array() shape as TaskCrudAccessPolicyService/
 * ClientCrmAccessPolicyService — no requester/approver split except
 * where an action carries the same "narrower ceiling as consequence
 * increases" reasoning those services already establish.
 *
 * Expense APPROVAL is deliberately NOT duplicated here — that ceiling
 * already exists as the single source of truth in
 * AccountingEntitlementPolicyService::canApprove()/assertCanApprove()
 * (FirmOwner, BillingStaff — Phase 12 correction #5). Every Expense
 * Action in this cluster calls that method directly rather than a
 * second, possibly-drifting constant here.
 *
 * Role ceilings, and the reasoning behind each:
 *
 *   - VIEW_TIME_ENTRY / VIEW_EXPENSE (list/view a TimeEntry or
 *     Expense; view the Expense Report page): every active staff role,
 *     including Receptionist and BillingStaff. Mirrors
 *     TaskCrudAccessPolicyService::VIEW_ROLES/ClientCrmAccessPolicyService
 *     ::VIEW_ROLES exactly — nothing here is confidential-by-role, and
 *     BillingStaff in particular needs full read access to do its own
 *     job (build invoices from approved, billable time; reconcile
 *     reimbursable expenses).
 *
 *   - MANAGE_TIME_ENTRY (TimeEntryApprovalService::createManualEntry()/
 *     submit(); TimeTrackingService::start()/pause()/resume()/stop() —
 *     the "log my own billable/non-billable work" action): FirmOwner,
 *     Attorney, Paralegal, LegalAssistant only. Mirrors
 *     TaskCrudAccessPolicyService::DEADLINE_MANAGEMENT_ROLES/
 *     TASK_DEPENDENCY_ROLES — logging time is fee-earner work, not a
 *     front-desk or billing-office task; Receptionist and BillingStaff
 *     are excluded (role ceilings in this codebase may only be
 *     narrowed, never widened by convenience).
 *
 *   - APPROVE_TIME_ENTRY (TimeEntryApprovalService::approve()/
 *     reject() — snapshots the employee's billing rate onto the entry,
 *     the point of no return before invoicing): FirmOwner, Attorney
 *     only. Mirrors ClientCrmAccessPolicyService::CONFLICT_RESOLUTION_ROLES
 *     and the Firm Feature Manifest §7 Trust convention ("Approve:
 *     FirmOwner, Attorney only") — approving a fee-earner's own logged
 *     time is a case-supervision judgment call reserved to firm
 *     leadership, not a billing-office bookkeeping action (that
 *     distinction is what AccountingEntitlementPolicyService::
 *     canApprove() encodes differently for Expenses, which ARE a
 *     billing-office concern).
 *
 *   - MANAGE_EXPENSE (ExpenseService::create()/editWhileDraft()/
 *     submit()/void()): FirmOwner, Attorney, Paralegal, LegalAssistant,
 *     BillingStaff. Unlike time entries, incurring/recording an
 *     operating expense (a filing fee, a process-server invoice, a
 *     courier charge) is routinely both a fee-earner's and the billing
 *     office's job — BillingStaff is included here (unlike
 *     MANAGE_TIME_ENTRY above) because tracking and voiding expense
 *     records is core billing-office work. Receptionist remains
 *     excluded — recording a firm expense is not a front-desk task the
 *     way logging an intake call is.
 */
class TimeExpenseAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const TIME_ENTRY_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const TIME_ENTRY_APPROVAL_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const EXPENSE_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::BillingStaff,
    ];

    public function canViewTimeEntry(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageTimeEntry(FirmUserRole $role): bool
    {
        return in_array($role, self::TIME_ENTRY_MANAGEMENT_ROLES, true);
    }

    public function canApproveTimeEntry(FirmUserRole $role): bool
    {
        return in_array($role, self::TIME_ENTRY_APPROVAL_ROLES, true);
    }

    public function canViewExpense(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageExpense(FirmUserRole $role): bool
    {
        return in_array($role, self::EXPENSE_MANAGEMENT_ROLES, true);
    }
}
