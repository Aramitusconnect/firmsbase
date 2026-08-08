<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmUserRole;

/**
 * TaskCrudAccessPolicyService — role ceiling for the Tasks/Deadlines
 * cluster (Firm Feature Manifest §3). Same plain in_array() shape as
 * ClientCrmAccessPolicyService/MatterAccessPolicyService/
 * IntegrationAccessPolicyService — no requester/approver split, since
 * nothing in this cluster carries Trust's two-person-approval
 * consequence.
 *
 * Role ceilings, and the reasoning behind each (documented per this
 * mission's own instruction, mirroring ClientCrmAccessPolicyService's
 * terse-comment style):
 *
 *   - VIEW (list/view Task, Deadline): every active staff role,
 *     including Receptionist and BillingStaff. A task or deadline is
 *     routine, day-to-day operational visibility — nothing here is
 *     confidential-by-role the way Trust/Billing money-movement is, and
 *     front-desk/billing staff both legitimately need to see what is
 *     due to do their own job (confirm an appointment, chase a
 *     payment-related follow-up).
 *
 *   - MANAGE_TASK (create/edit a Task; assign/start/complete/cancel its
 *     own workflow-status transitions — never TaskDependencyService's
 *     Blocked transition, see MANAGE_TASK_DEPENDENCY below): FirmOwner,
 *     Attorney, Paralegal, LegalAssistant, Receptionist. Mirrors
 *     ClientCrmAccessPolicyService's INTAKE_ROLES ceiling exactly — a
 *     Task is a generic, low-stakes workflow item ("call client back",
 *     "confirm appointment") that front-desk staff routinely create and
 *     work, same reasoning that lets Receptionist manage Contacts/
 *     Leads. BillingStaff is excluded: their job is billing, not case
 *     workflow, and role ceilings in this codebase may only be
 *     narrowed, never widened by convenience.
 *
 *   - MANAGE_TASK_DEPENDENCY (TaskDependencyService::addDependency()/
 *     removeDependency() — sequencing which task blocks which):
 *     FirmOwner, Attorney, Paralegal, LegalAssistant only. Deciding
 *     that one task must block another is a case-sequencing judgment
 *     call, one narrower step up from plain task management — mirrors
 *     the same "narrower ceiling as action severity increases" pattern
 *     ClientCrmAccessPolicyService's CLIENT_MANAGEMENT_ROLES already
 *     establishes over its own INTAKE_ROLES.
 *
 *   - MANAGE_DEADLINE (DeadlineService::create()/complete()/cancel() —
 *     a legal filing/court deadline, not a generic reminder):
 *     FirmOwner, Attorney, Paralegal, LegalAssistant only, same ceiling
 *     as MANAGE_TASK_DEPENDENCY and matching
 *     ClientCrmAccessPolicyService's CLIENT_MANAGEMENT_ROLES. A missed
 *     or mis-set legal deadline can carry real professional-liability
 *     consequences (a statute-of-limitations miss, a missed filing
 *     window) — this is not a routine front-desk task, so Receptionist
 *     is deliberately excluded here even though it is included in
 *     MANAGE_TASK above.
 */
class TaskCrudAccessPolicyService
{
    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
        FirmUserRole::BillingStaff,
    ];

    private const TASK_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
        FirmUserRole::Receptionist,
    ];

    private const TASK_DEPENDENCY_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const DEADLINE_MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canManageTask(FirmUserRole $role): bool
    {
        return in_array($role, self::TASK_MANAGEMENT_ROLES, true);
    }

    public function canManageTaskDependency(FirmUserRole $role): bool
    {
        return in_array($role, self::TASK_DEPENDENCY_ROLES, true);
    }

    public function canManageDeadline(FirmUserRole $role): bool
    {
        return in_array($role, self::DEADLINE_MANAGEMENT_ROLES, true);
    }
}
