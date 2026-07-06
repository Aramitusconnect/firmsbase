<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * PermissionMatrixMappingService — declares the master plan's Section
 * 27 permission matrix (1 organization role, 7 firm roles, 9 platform
 * roles) and maps each to its EXISTING owning role enum/model/service,
 * or the explicit absence of one. Purely declarative — no new role
 * system, no organization_users table, no OrganizationRole enum, no
 * enforcement rewrite. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25/26 cross-cutting
 * package rather than inventing a parallel type.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (FirmUserRole, PlatformRoleCode, Client,
 * PlatformStaffAccessPolicyService, TrustAccessPolicyService,
 * MatterAccessPolicyService, SupportAccessPolicyService) at the time
 * this service was written.
 */
class PermissionMatrixMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge(
            $this->organizationRoles(),
            $this->firmRoles(),
            $this->platformRoles(),
        );
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function organizationRoles(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'org_admin',
                item_label: 'Organization administrator',
                owning_class: null,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No org_admin role exists anywhere: no organization_users table, no OrganizationRole enum, no organization-level admin grant/membership mechanism of any kind (confirmed by direct repository search). This is a real, documented missing role/boundary — see the org_admin_role_missing gap-register item. Per approved decision, organization roles must never imply firm data access even once built: an organization spans many firms, and org-level administration is not the same authorization surface as a FirmUserRole.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function firmRoles(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'firm_owner',
                item_label: 'Firm owner',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::FirmOwner. Blanket matter access (MatterAccessPolicyService); may request and approve trust actions (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'attorney',
                item_label: 'Attorney',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::Attorney. Blanket matter access (MatterAccessPolicyService); may request and approve trust actions (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'paralegal',
                item_label: 'Paralegal',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::Paralegal. Matter access requires an active MatterAssignment (MatterAccessPolicyService); may not approve trust actions (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'legal_assistant',
                item_label: 'Legal assistant',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::LegalAssistant. Matter access requires an active MatterAssignment (MatterAccessPolicyService); may not approve trust actions (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'receptionist',
                item_label: 'Receptionist',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::Receptionist. Matter access requires an active MatterAssignment (MatterAccessPolicyService); may not request or approve trust actions (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'billing_staff',
                item_label: 'Billing staff',
                owning_class: \App\Enums\FirmUserRole::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmUserRole::BillingStaff. Matter access requires an active MatterAssignment (MatterAccessPolicyService); may REQUEST but never APPROVE a trust action (TrustAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'client',
                item_label: 'Client (portal user)',
                owning_class: \App\Models\Client::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Client is structurally its own model, entirely separate from FirmUser/FirmUserRole — clients are never firm_users rows and have no FirmUserRole cast. FirmUserRole\'s own docblock states this is deliberate: mixing "internal firm staff" and "external client" into one role enum would blur a permission boundary that must stay hard. This is Implemented structurally even though no client-portal UI exists yet.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function platformRoles(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'super_admin',
                item_label: 'Super admin',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::SuperAdmin. Granted access to client/matter data, document content, platform billing, and security logs in PlatformStaffAccessPolicyService.',
            ),
            new GovernanceMappingResult(
                item_key: 'platform_admin',
                item_label: 'Platform admin',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::PlatformAdmin. Granted access to client/matter data, document content, platform billing, and security logs in PlatformStaffAccessPolicyService.',
            ),
            new GovernanceMappingResult(
                item_key: 'support_agent',
                item_label: 'Support agent',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'PlatformRoleCode::SupportAgent. Standard support access is well-governed: client/matter access is granted, and document content access requires a governed support access session (PlatformStaffAccessPolicyService::canAccessDocumentContent with $hasGovernedSupportAccess). However, the EMERGENCY access path (SupportAccessType::Emergency) is not wired to HighRiskPlatformChangePolicyService for approval — see EmergencyAccessGovernanceGapService and the emergency_support_access_high_risk_approval_not_wired gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'billing_admin',
                item_label: 'Billing admin',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::BillingAdmin. Granted platform billing access; NOT granted document content access (PlatformStaffAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'sales_manager',
                item_label: 'Sales manager',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'PlatformRoleCode::SalesRep\'s sibling role. The current allow-list design in PlatformStaffAccessPolicyService is deny-safe — sales_manager is absent from every allow-list (client data, matter data, document content, platform billing, security logs), so no unauthorized access is possible. But no affirmative sales-data access model/method exists yet (e.g. viewing leads/opportunities/pipeline) — the role can neither see legal data (correct) nor its own intended sales data (a gap, not a security hole).',
            ),
            new GovernanceMappingResult(
                item_key: 'sales_rep',
                item_label: 'Sales rep',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'PlatformRoleCode::SalesRep. The current allow-list design in PlatformStaffAccessPolicyService is deny-safe — sales_rep is absent from every allow-list (client data, matter data, document content, platform billing, security logs), so no unauthorized access is possible. But no affirmative sales-data access model/method exists yet (e.g. viewing leads/opportunities/pipeline) — the role can neither see legal data (correct) nor its own intended sales data (a gap, not a security hole).',
            ),
            new GovernanceMappingResult(
                item_key: 'implementation_specialist',
                item_label: 'Implementation specialist',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::ImplementationSpecialist. Granted access to client/matter data and document content directly (no governed-session requirement) in PlatformStaffAccessPolicyService.',
            ),
            new GovernanceMappingResult(
                item_key: 'security_auditor',
                item_label: 'Security auditor',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::SecurityAuditor. Granted security log access unconditionally; document content access only under a governed support access session, never by default (PlatformStaffAccessPolicyService).',
            ),
            new GovernanceMappingResult(
                item_key: 'read_only_auditor',
                item_label: 'Read-only auditor',
                owning_class: \App\Enums\PlatformRoleCode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformRoleCode::ReadOnlyAuditor. PlatformStaffAccessPolicyService::canMutate() unconditionally denies mutation for any admin holding this role, regardless of any other role also held.',
            ),
        ];
    }

    public function clientBoundary(): GovernanceMappingResult
    {
        return $this->byKey('client');
    }

    /**
     * @return array<int, GovernanceMappingResult> every item not classified Implemented
     */
    public function gaps(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status !== GovernanceMappingStatus::Implemented,
        ));
    }
}
