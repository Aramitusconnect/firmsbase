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
     * Phase 2 (FirmsVault Platform Admin Control Center, "Integration
     * Operations Center") addition. Gates
     * PlatformFirmIntegrationBoundedAccessService::disconnectConnection()
     * — mutating a firm's live provider connection is a materially more
     * sensitive action than the broad, read-oriented
     * canAccessIntegrationOversight() gate that method's sibling
     * read/requeue/nudge methods use, so this is narrowed to the same
     * unconditionally-trusted ceiling FIRM_MANAGEMENT_ROLES already
     * uses (SuperAdmin/PlatformAdmin only), deliberately excluding
     * SupportAgent/ImplementationSpecialist even though both pass the
     * broader canAccessIntegrationOversight() gate — mirrors
     * canManageFirms()'s exact shape and rationale.
     */
    private const INTEGRATION_CONNECTION_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
     * Commercial Administration") addition. Gates mutation of platform
     * billing state (cancelling a subscription, activating/archiving a
     * Plan, enabling/retiring a PlanModule, transitioning a
     * TrialRequest, finalizing/voiding a PlatformInvoice) — narrower
     * than the existing canAccessPlatformBilling() read gate (which
     * stays unchanged and keeps its broader PLATFORM_BILLING_ROLES set,
     * including BillingAdmin).
     *
     * Deliberately narrowed all the way to SuperAdmin/PlatformAdmin,
     * excluding BillingAdmin even though BillingAdmin passes
     * canAccessPlatformBilling() — this mirrors the exact shape and
     * reasoning canManageFirms() and canManageIntegrationConnections()
     * already established in this file: every "manage" gate added so
     * far narrows its corresponding broader read gate down to the same
     * unconditionally-trusted ceiling (SuperAdmin/PlatformAdmin only),
     * never to a wider role set, regardless of which roles legitimately
     * read that domain's data. Platform billing mutations carry real
     * commercial/financial consequence (an admin cancelling a paying
     * account's subscription, voiding an issued invoice, or archiving a
     * live plan) — materially more sensitive than BillingAdmin's
     * intended scope of viewing/reconciling billing data, and there is
     * no existing precedent anywhere in this file for a "manage" gate
     * that is wider than SuperAdmin+PlatformAdmin. A future, separately
     * -authorized change could widen this to include BillingAdmin if a
     * concrete workflow need for it is identified — this gate is not
     * meant to foreclose that, just to not assume it without one.
     */
    private const PLATFORM_BILLING_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
     * addition. Gates read-only visibility across the whole Operations
     * category (Service Health, Queues & Jobs, Scheduler, Deployments,
     * Backups, Incidents/Status Page) — infrastructure/ops-monitoring
     * data, not client/matter/billing data, so this deliberately does
     * NOT reuse CLIENT_AND_MATTER_DATA_ROLES or PLATFORM_BILLING_ROLES
     * (neither role set is a good conceptual fit — a SalesRep or
     * BillingAdmin has no legitimate reason to see queue/incident
     * internals). Mirrors SECURITY_LOG_ROLES' exact role set
     * (SuperAdmin/PlatformAdmin/SecurityAuditor) plus ReadOnlyAuditor —
     * operational/infrastructure oversight is audit-adjacent by
     * nature, and ReadOnlyAuditor's own blanket "never mutate" rule is
     * enforced separately by canMutate(), not by narrowing this
     * read-only view gate (same precedent already established by
     * PLATFORM_ADMINISTRATION_ROLES above).
     */
    private const OPERATIONS_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SecurityAuditor,
        PlatformRoleCode::ReadOnlyAuditor,
    ];

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
     * addition. Gates every mutating action across the Operations
     * category (running health checks on demand, retrying/deleting a
     * failed job, fleet migration run lifecycle actions, incident/
     * status-page lifecycle actions) — narrower than OPERATIONS_ROLES
     * above, mirroring every other "manage" gate's established
     * precedent in this file: narrowed all the way down to the same
     * unconditionally-trusted ceiling (SuperAdmin/PlatformAdmin only),
     * deliberately excluding SecurityAuditor/ReadOnlyAuditor even
     * though both pass the broader read gate — an auditor role has no
     * legitimate reason to mutate operational state, only to observe
     * it.
     */
    private const OPERATIONS_MANAGEMENT_ROLES = [
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
     * Phase 4 (FirmsVault Platform Admin Control Center, "Operations,
     * Governance, Support, and Configuration") addition. Gates the new
     * read-oriented Governance modules (Audit Logs, Retention, Legal
     * Holds list, Data Exports list, Deletion Requests list) — these are
     * compliance/oversight surfaces over sensitive cross-firm data
     * (legal holds, deletion governance, retention policy, general
     * business-activity timeline events), so the role set is narrower
     * than PLATFORM_ADMINISTRATION_ROLES (which includes SupportAgent/
     * ImplementationSpecialist/BillingAdmin — none of whom have a
     * documented need for governance/compliance oversight) and instead
     * mirrors SECURITY_LOG_ROLES' compliance-oriented shape, but adds
     * ReadOnlyAuditor (SECURITY_LOG_ROLES deliberately excludes it,
     * since general security-log review was scoped narrower in Phase
     * 1) — a read-only auditor reviewing legal holds/retention/deletion
     * governance status is squarely the intended use of that role, and
     * canMutate()'s blanket rule below already guarantees a
     * ReadOnlyAuditor can never act on any of this data, only view it.
     */
    private const GOVERNANCE_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SecurityAuditor,
        PlatformRoleCode::ReadOnlyAuditor,
    ];

    /**
     * Phase 4 addition. Gates LegalHoldService::place()/release() — both
     * methods accept a loosely-typed $placedBy/$releasedBy object with
     * no authorization logic of their own (see that service's own
     * docblock), so a platform-admin console wiring them needs its own
     * gate. Narrowed to the same unconditionally-trusted SuperAdmin/
     * PlatformAdmin ceiling every other "manage" gate in this file uses
     * — placing/releasing a legal hold is a real, consequential
     * governance action (it can block deletion/offboarding for an
     * entire firm), not a routine oversight-role action.
     */
    private const LEGAL_HOLD_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 addition. Gates the safe, already-PlatformAdmin-typed Data
     * Exports mutations: OffboardingRequestService::advance()/complete()/
     * cancel() and OffboardingExportService::verify(). Same
     * SuperAdmin/PlatformAdmin ceiling as every other manage gate.
     */
    private const DATA_EXPORT_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 addition. Gates the Deletion Requests approve/deny
     * workflow (DeletionRequestService::request()/cancel(),
     * DeletionGovernanceService::submitForApproval(),
     * DeletionApprovalService::requestApproval()/firstApprove()/
     * secondApprove()/deny()). Verified directly against
     * HighRiskPlatformChangePolicyService (the underlying engine
     * DeletionApprovalService routes through): that service enforces
     * ONLY "a reason string is required" and "the second approver must
     * differ from the first" — it carries NO role-based authorization
     * check of its own, so this UI-layer gate is not redundant with an
     * existing one. Given this workflow governs actual production data
     * deletion clearance (the most sensitive action in the entire
     * Governance category), narrowed to the same SuperAdmin/PlatformAdmin
     * ceiling as every other manage gate — deliberately not widened to
     * SecurityAuditor/ReadOnlyAuditor even though GOVERNANCE_ROLES above
     * lets them view this data.
     */
    private const DELETION_GOVERNANCE_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
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

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Support"
     * category) addition. Gates mutation of support-access state
     * (revoking an Approved Support Session, marking a stale Support
     * Case Expired) — narrower than canAccessIntegrationOversight(),
     * the existing broad read gate this phase's Support Cases/Approved
     * Support Sessions resources reuse for reads (see
     * PlatformSupportAccessDirectoryService's own docblock for why: it
     * shares PlatformFirmIntegrationBoundedAccessService's chokepoint
     * and governed-SupportAgent-session semantics with Checkpoint 11's
     * existing single-firm support-access actions, so reusing the same
     * read gate keeps read-side authorization consistent across both
     * surfaces rather than introducing a second, potentially divergent
     * read gate over the identical underlying data/session model).
     * Mirrors every other "manage" gate in this file: narrowed all the
     * way to the unconditionally-trusted SuperAdmin/PlatformAdmin
     * ceiling, deliberately excluding SupportAgent/
     * ImplementationSpecialist even though both pass the broader read
     * gate — revoking another admin's active session or expiring a
     * stale request is a more consequential action than merely viewing
     * the support-access directory.
     */
    private const SUPPORT_ACCESS_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates the new
     * cross-firm Entitlement Overrides read (EntitlementOverrideResource,
     * over FirmEntitlement/EntitlementSource — the real per-firm
     * mechanism behind the "Feature Flags" nav item, relabeled
     * honestly). Broader than the corresponding manage gate below,
     * mirroring canAccessPlatformBilling()/canManagePlatformBilling()'s
     * existing read/manage split. Scoped to the roles that legitimately
     * need to see which modules are entitled/overridden for a firm as
     * part of their normal work: SuperAdmin/PlatformAdmin (unconditional
     * ceiling), ImplementationSpecialist (module rollout/configuration
     * during onboarding), BillingAdmin (entitlements correlate directly
     * with what a firm is being billed for). SupportAgent is
     * deliberately excluded here — unlike integration oversight,
     * browsing another firm's entitlement configuration is not a normal
     * part of a governed support session and has no existing precedent
     * requiring it.
     */
    private const ENTITLEMENT_OVERRIDE_READ_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::ImplementationSpecialist,
        PlatformRoleCode::BillingAdmin,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates the mutating
     * Set Override action (EntitlementOverrideService::
     * setOverrideAsPlatformAdmin()) — narrowed to the unconditionally-
     * trusted SuperAdmin/PlatformAdmin ceiling, same as every other
     * "manage" gate in this file (see PLATFORM_BILLING_MANAGEMENT_ROLES'
     * own docblock for the established reasoning this mirrors):
     * granting/revoking a firm's module access is a materially more
     * sensitive, directly commercially-consequential action than merely
     * viewing entitlement state, so it is deliberately narrower than
     * ENTITLEMENT_OVERRIDE_READ_ROLES above, excluding
     * ImplementationSpecialist/BillingAdmin even though both may read.
     */
    private const ENTITLEMENT_OVERRIDE_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates the new AI
     * Policy Settings mini-module (AiPolicySettingResource, over the
     * previously-zero-service-layer AiPolicySetting table — relabeled
     * honestly from "Platform Settings", which has no general backing
     * store at all). AI policy defaults are platform-wide guardrail
     * configuration with governance/compliance weight (e.g. whether
     * firm_owned AI mode is globally permitted) — SecurityAuditor is
     * included in the READ set for exactly that reason (mirrors
     * SECURITY_LOG_ROLES' own inclusion of SecurityAuditor), unlike
     * ENTITLEMENT_OVERRIDE_READ_ROLES above which has no security/
     * compliance-audit angle and so does not include it.
     */
    private const AI_POLICY_SETTING_READ_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::SecurityAuditor,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates the Edit Value
     * action on AiPolicySettingResource — narrowed to SuperAdmin/
     * PlatformAdmin only, same unconditionally-trusted ceiling every
     * other "manage" gate in this file uses; SecurityAuditor may read
     * AI policy settings above but must never mutate them (consistent
     * with canMutate()'s own blanket read_only_auditor rule, applied
     * here at the role-ceiling level instead since SecurityAuditor is a
     * distinct role from ReadOnlyAuditor).
     */
    private const AI_POLICY_SETTING_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates the new
     * Notification Templates management surface (relabeled from "Email
     * Templates" — the backend is channel-agnostic across Email/Sms/
     * WhatsApp/Portal, see NotificationTemplateResource's own docblock).
     * ImplementationSpecialist is included: template content/metadata
     * configuration (subject/body copy, sender domain records) is
     * ordinary implementation/onboarding work, the same class of task
     * that role already performs elsewhere in this file (e.g.
     * INTEGRATION_CONNECTION_MANAGEMENT_ROLES' sibling read gates).
     * BillingAdmin/SupportAgent are excluded — template content has no
     * billing or support-session angle.
     */
    private const NOTIFICATION_TEMPLATE_READ_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
        PlatformRoleCode::ImplementationSpecialist,
    ];

    /**
     * Phase 4 ("Configuration" category) addition. Gates Create Global
     * Default / Create Firm Override / Archive on
     * NotificationTemplateResource — narrowed to SuperAdmin/PlatformAdmin
     * only, same unconditionally-trusted ceiling every other "manage"
     * gate in this file uses. A global default template affects every
     * firm's outbound notification content platform-wide; narrower than
     * NOTIFICATION_TEMPLATE_READ_ROLES for the same reason every other
     * manage/read split in this file exists.
     */
    private const NOTIFICATION_TEMPLATE_MANAGEMENT_ROLES = [
        PlatformRoleCode::SuperAdmin,
        PlatformRoleCode::PlatformAdmin,
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
     * Phase 2 (FirmsVault Platform Admin Control Center, "Integration
     * Operations Center") addition. See
     * INTEGRATION_CONNECTION_MANAGEMENT_ROLES' own docblock. No Filament
     * UI wires this yet in this phase (the Connections module's
     * disconnect action is built in a later Phase 2 UI pass) — this
     * gate exists ready for that future, separately-authorized build,
     * and is exercised now by
     * PlatformFirmIntegrationBoundedAccessService::disconnectConnection().
     */
    public function canManageIntegrationConnections(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::INTEGRATION_CONNECTION_MANAGEMENT_ROLES, 'integration connection management');
    }

    /**
     * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
     * Commercial Administration") addition. See
     * PLATFORM_BILLING_MANAGEMENT_ROLES' own docblock for the role-set
     * reasoning. Gates the actor-parameterized mutating methods on
     * PlatformSubscriptionService, PlanService, PlanModuleService,
     * TrialRequestService, and PlatformInvoiceService added in this
     * same phase.
     */
    public function canManagePlatformBilling(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::PLATFORM_BILLING_MANAGEMENT_ROLES, 'platform billing management');
    }

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
     * addition. Gates read-only visibility of Service Health,
     * Queues & Jobs, Scheduler, Deployments, Backups, and Incidents/
     * Status Page — see OPERATIONS_ROLES' own docblock for the role-set
     * reasoning.
     */
    public function canAccessOperations(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::OPERATIONS_ROLES, 'operations');
    }

    /**
     * Phase 4 (FirmsVault Platform Admin Control Center, "Operations")
     * addition. Gates every mutating action across the Operations
     * category — see OPERATIONS_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageOperations(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::OPERATIONS_MANAGEMENT_ROLES, 'operations management');
    }

    /**
     * Phase 4 addition. See GOVERNANCE_ROLES' own docblock for the
     * role-set reasoning. Gates the new cross-firm read layers over
     * TimelineEvent (Audit Logs), RetentionPolicy/
     * RetentionGovernanceRegistryService (Retention), LegalHold (list),
     * ExportJob/OffboardingRequest/OffboardingExport/ImportBatch/
     * MigrationProject (Data Exports list), and DeletionRequest/
     * DeletionApproval (Deletion Requests list).
     */
    public function canAccessGovernance(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::GOVERNANCE_ROLES, 'governance');
    }

    /**
     * Phase 4 addition. See LEGAL_HOLD_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageLegalHolds(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::LEGAL_HOLD_MANAGEMENT_ROLES, 'legal hold management');
    }

    /**
     * Phase 4 addition. See DATA_EXPORT_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageDataExports(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::DATA_EXPORT_MANAGEMENT_ROLES, 'data export management');
    }

    /**
     * Phase 4 addition. See DELETION_GOVERNANCE_MANAGEMENT_ROLES' own
     * docblock.
     */
    public function canManageDeletionGovernance(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::DELETION_GOVERNANCE_MANAGEMENT_ROLES, 'deletion governance management');
    }

    /**
     * Phase 4 ("Support" category) addition. See
     * SUPPORT_ACCESS_MANAGEMENT_ROLES' own docblock for why reads
     * against Support Cases/Approved Support Sessions deliberately
     * reuse canAccessIntegrationOversight() rather than a new read gate
     * (this method exists only for the narrower mutating actions —
     * Revoke/Expire).
     */
    public function canManageSupportAccess(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::SUPPORT_ACCESS_MANAGEMENT_ROLES, 'support access management');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * ENTITLEMENT_OVERRIDE_READ_ROLES' own docblock.
     */
    public function canAccessEntitlementOverrides(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::ENTITLEMENT_OVERRIDE_READ_ROLES, 'entitlement overrides');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * ENTITLEMENT_OVERRIDE_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageEntitlementOverrides(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::ENTITLEMENT_OVERRIDE_MANAGEMENT_ROLES, 'entitlement override management');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * AI_POLICY_SETTING_READ_ROLES' own docblock.
     */
    public function canAccessAiPolicySettings(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::AI_POLICY_SETTING_READ_ROLES, 'AI policy settings');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * AI_POLICY_SETTING_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageAiPolicySettings(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::AI_POLICY_SETTING_MANAGEMENT_ROLES, 'AI policy setting management');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * NOTIFICATION_TEMPLATE_READ_ROLES' own docblock.
     */
    public function canAccessNotificationTemplates(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::NOTIFICATION_TEMPLATE_READ_ROLES, 'notification templates');
    }

    /**
     * Phase 4 ("Configuration" category) addition. See
     * NOTIFICATION_TEMPLATE_MANAGEMENT_ROLES' own docblock.
     */
    public function canManageNotificationTemplates(PlatformAdmin $admin): PlatformStaffAccessDecision
    {
        return $this->decideAgainst($admin, self::NOTIFICATION_TEMPLATE_MANAGEMENT_ROLES, 'notification template management');
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
