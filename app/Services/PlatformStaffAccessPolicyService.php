<?php

namespace App\Services;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\ValueObjects\PlatformStaffAccessDecision;

/**
 * PlatformStaffAccessPolicyService — enforces the Phase 7 critical
 * access-control rules at the ROLE level:
 *  1. Sales reps cannot see client data.
 *  2. Sales reps cannot see matter data.
 *  3. Sales reps cannot see legal documents.
 *  4. Billing admins can see platform billing only.
 *  5. Billing admins cannot see legal document contents.
 *  6. Support agents require approved, time-limited access with reason
 *     (enforced separately by SupportAccessPolicyService; a support
 *     agent's document-content access below still requires
 *     $hasGovernedSupportAccess = true).
 *  7. Security auditors can see security logs.
 *  8. Security auditors cannot see document contents unless explicitly
 *     approved under governed support access.
 *  9. Read-only auditors must not mutate data.
 * A PlatformAdmin may hold multiple roles; a decision is permissive-OR
 * across all of the admin's currently active roles.
 */
class PlatformStaffAccessPolicyService
{
    private const CLIENT_AND_MATTER_DATA_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SupportAgent,
        PlatformRoleCode::ImplementationSpecialist,
    ];

    private const DOCUMENT_CONTENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::ImplementationSpecialist,
    ];

    private const DOCUMENT_CONTENT_ROLES_REQUIRING_GOVERNED_ACCESS = [
        PlatformRoleCode::SupportAgent,
        PlatformRoleCode::SecurityAuditor,
    ];

    private const PLATFORM_BILLING_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::BillingAdmin,
    ];

    private const SECURITY_LOG_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SecurityAuditor,
    ];

    /**
     * Phase 1 FirmsVault Admin Control Center addition. "Platform
     * administration" here means the coarse, read-oriented ability to
     * view the cross-firm Firms/Firm Users oversight lists (the new
     * FirmResource/FirmUserResource) — NOT client/matter/document
     * content (those remain governed by CLIENT_AND_MATTER_DATA_ROLES/
     * DOCUMENT_CONTENT_ROLES above, unchanged). Every non-sales
     * platform-operations role is included: SupportAgent and
     * ImplementationSpecialist legitimately need to look up which firms
     * exist and who their users are as part of day-to-day support/
     * implementation work; BillingAdmin needs the firm list to correlate
     * against platform billing; SecurityAuditor/ReadOnlyAuditor need it
     * for oversight/audit work (ReadOnlyAuditor's blanket "never
     * mutate" rule is enforced separately by canMutate(), not by
     * narrowing this read-only view gate). SalesManager/SalesRep are
     * deliberately excluded — Firms/Firm Users here is administrative
     * oversight data (firm staff accounts, activation status), not the
     * sales-pipeline data those roles are scoped to, and Rule 1 already
     * establishes sales roles as the ones restricted from adjacent
     * platform data in this class.
     */
    private const PLATFORM_ADMINISTRATION_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SupportAgent,
        PlatformRoleCode::ImplementationSpecialist,
        PlatformRoleCode::BillingAdmin,
        PlatformRoleCode::SecurityAuditor,
        PlatformRoleCode::ReadOnlyAuditor,
    ];

    /**
     * Phase 1 addition. Mutating a firm's status (e.g. suspending/
     * reactivating) is a materially more sensitive action than merely
     * viewing the firm list — narrowed to the same unconditionally-
     * trusted ceiling PlatformFirmIntegrationBoundedAccessService already
     * uses for cross-firm mutating actions (SuperAdmin/PlatformAdmin),
     * deliberately excluding SupportAgent/ImplementationSpecialist/
     * BillingAdmin even though they can view the firm list above.
     */
    private const FIRM_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 1 addition. Creating/deactivating/role-assigning other
     * PlatformAdmins is the single most sensitive administrative action
     * this service gates — per this checkpoint's explicit brief,
     * restricted to SuperAdmin only, not even PlatformAdmin (unlike
     * every other *_ROLES ceiling above, which treats SuperAdmin and
     * PlatformAdmin as an equivalent trusted pair).
     */
    private const PLATFORM_ADMINISTRATOR_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
    ];

    /**
     * Phase 1 addition. Role/permission catalog mutation (granting or
     * revoking a PlatformRoleCode grant) is treated as equally sensitive
     * to platform-administrator management above, for the same reason —
     * both are direct privilege-escalation surfaces — so this is also
     * SuperAdmin-only rather than reusing FIRM_MANAGEMENT_ROLES' broader
     * SuperAdmin+PlatformAdmin ceiling.
     */
    private const ROLE_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
    ];

    public function __construct(
        private readonly PlatformRoleService $platformRoleService,
    ) {}

    public function canAccessClientData(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::CLIENT_AND_MATTER_DATA_ROLES, 'client data');
    }

    public function canAccessMatterData(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::CLIENT_AND_MATTER_DATA_ROLES, 'matter data');
    }

    public function canAccessDocumentContent(PlatformAdmin $admin, bool $hasGovernedSupportAccess = false): PlatformStaffAccessDecision
    {
        $roles = $this->platformRoleService->activeRolesFor($admin);

        foreach ($roles as $role) {
            if (in_array($role, self::DOCUMENT_CONTENT_ROLES, true)) {
                return PlatformStaffAccessDecision::allow();
            }

            if ($hasGovernedSupportAccess && in_array($role, self::DOCUMENT_CONTENT_ROLES_REQUIRING_GOVERNED_ACCESS, true)) {
                return PlatformStaffAccessDecision::allow();
            }
        }

        return PlatformStaffAccessDecision::deny('document contents require a governed support access session for this role, or are not permitted for this role at all');
    }

    public function canAccessPlatformBilling(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::PLATFORM_BILLING_ROLES, 'platform billing');
    }

    public function canAccessSecurityLogs(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::SECURITY_LOG_ROLES, 'security logs');
    }

    /**
     * Checkpoint 11 addition (frozen-design-post-security-review.md §11;
     * agent-11h-architecture-security-review.md). The one new additive
     * method this checkpoint adds — purely additive, no existing
     * method's behavior changes. Reuses CLIENT_AND_MATTER_DATA_ROLES
     * unchanged: SuperAdmin/PlatformAdmin/ImplementationSpecialist are
     * unconditionally trusted for cross-firm integration oversight;
     * SupportAgent also passes this coarse, role-level gate but — per
     * PlatformFirmIntegrationBoundedAccessService, the new caller-layer
     * chokepoint this method feeds — additionally requires an active,
     * governed SupportAccessSession scoped to the exact target firm
     * before any PER-FIRM drill-down read or mutating action is allowed
     * (the always-visible, aggregate/sanitized platform overview itself
     * requires no such session). Every other role (BillingAdmin,
     * SalesManager, SalesRep, SecurityAuditor, ReadOnlyAuditor) is
     * denied outright.
     */
    public function canAccessIntegrationOversight(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::CLIENT_AND_MATTER_DATA_ROLES, 'integration oversight');
    }

    /**
     * Phase 1 FirmsVault Admin Control Center addition. Gates the new
     * cross-firm Firms/Firm Users oversight lists (FirmResource/
     * FirmUserResource) — see PLATFORM_ADMINISTRATION_ROLES' own
     * docblock for the role-set reasoning.
     */
    public function canAccessPlatformAdministration(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::PLATFORM_ADMINISTRATION_ROLES, 'platform administration');
    }

    /**
     * Phase 1 addition. Gates mutation of a firm's status (suspend/
     * reactivate/etc.) — narrower than canAccessPlatformAdministration()
     * above; see FIRM_MANAGEMENT_ROLES' own docblock. No Filament UI
     * wires this yet in this checkpoint (FirmResource is List+View
     * only, no mutating Action) — this gate exists so a future mutating
     * Action has a ready-made, correctly-scoped check to call rather
     * than inventing one ad hoc at that time.
     */
    public function canManageFirms(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::FIRM_MANAGEMENT_ROLES, 'firm management');
    }

    /**
     * Phase 1 addition. Gates creating/deactivating/role-assigning other
     * PlatformAdmins — SuperAdmin only; see
     * PLATFORM_ADMINISTRATOR_MANAGEMENT_ROLES' own docblock. No Filament
     * UI wires this yet in this checkpoint (a Platform Administrators
     * resource is explicitly out of this checkpoint's scope per the
     * architecture map's sequencing note) — this gate exists ready for
     * that future, separately-authorized build.
     */
    public function canManagePlatformAdministrators(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::PLATFORM_ADMINISTRATOR_MANAGEMENT_ROLES, 'platform administrator management');
    }

    /**
     * Phase 1 addition. Gates role/permission-catalog mutation
     * (granting/revoking a PlatformRoleCode grant) — SuperAdmin only;
     * see ROLE_MANAGEMENT_ROLES' own docblock. No Filament UI wires
     * this yet in this checkpoint either.
     */
    public function canManageRoles(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::ROLE_MANAGEMENT_ROLES, 'role management');
    }

    /**
     * Blanket rule 9: a read_only_auditor may never mutate data,
     * regardless of any other role also held.
     */
    public function canMutate(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        if ($this->platformRoleService->hasRole($admin, PlatformRoleCode::ReadOnlyAuditor)) {
            return PlatformStaffAccessDecision::deny('read_only_auditor may never mutate data');
        }

        return PlatformStaffAccessDecision::allow();
    }

    private function decideAgainst(PlatformAdmin $admin, array $allowedRoles, string $resourceLabel): PlatformStaffAccessDecision
    {
        $roles = $this->platformRoleService->activeRolesFor($admin);

        foreach ($roles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return PlatformStaffAccessDecision::allow();
            }
        }

        return PlatformStaffAccessDecision::deny("no active role grants access to {$resourceLabel}");
    }
}
