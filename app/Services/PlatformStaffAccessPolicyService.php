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

    public function __construct(
        private readonly PlatformRoleService $platformRoleService,
    ) {
    }

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
