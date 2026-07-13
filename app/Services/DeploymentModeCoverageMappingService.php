<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * DeploymentModeCoverageMappingService — declares the master plan's
 * Section 29 deployment-mode controls (4 SaaS, 6 Dedicated, 8 Private
 * Enterprise = 18 keys) and maps each to an EXISTING owning mechanism
 * (Phase 16 + Sections 25-28) or a known, explicitly declared gap.
 * Purely declarative — no new deployment system, no real
 * infrastructure, no schema change. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25-28 cross-cutting
 * package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository at the time this service was written.
 */
class DeploymentModeCoverageMappingService
{
    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge($this->saas(), $this->dedicated(), $this->privateEnterprise());
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
    public function saas(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'saas_firm_isolation_rls_defense_in_depth',
                item_label: 'SaaS firm isolation with RLS as defense-in-depth',
                owning_class: \App\Services\RowLevelSecurityCoverageMappingService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'BelongsToTenant enforces isolation at the application/query layer for every SaaS firm. Database-level RLS enforcement (FORCE ROW LEVEL SECURITY, SET LOCAL app.current_firm_id) is now genuinely ACTIVE for all 52 originally-prepared tables (Section 39A-3L Stage B, complete) — real defense-in-depth for those tables. Still PartiallyImplemented, not Implemented: 61 additional tenant-owned tables discovered by inventory sweeps remain entirely uncovered (no RLS preparation at all) — see the rls_prepared_not_enforced gap\'s own remaining, still-open component. Cannot be Implemented until every tenant-owned table carries enforced RLS, not just the 52 already covered.',
            ),
            new GovernanceMappingResult(
                item_key: 'saas_plan_license_controls',
                item_label: 'SaaS plan and license controls',
                owning_class: \App\Services\EntitlementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmLicense, FirmEntitlement, EntitlementService, and plans/plan_modules/plan_limits are all real, tested mechanisms governing what a SaaS firm may use.',
            ),
            new GovernanceMappingResult(
                item_key: 'saas_centralized_platform_billing',
                item_label: 'Centralized platform billing for SaaS',
                owning_class: \App\Services\UsageRollupService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PlatformSubscriptionService/PlatformInvoiceService/PlatformPaymentService/PlatformRefundService plus PlatformBillingSeparationTest (Section 28 evidence) confirm centralized billing separate from firm-level billing.',
            ),
            new GovernanceMappingResult(
                item_key: 'saas_strict_support_access',
                item_label: 'Strict platform support access for SaaS firms',
                owning_class: \App\Services\SupportAccessPolicyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Standard support access is well-governed: reason-required, firm-approved, time-limited, audited (SupportAccessPolicyService/SupportAccessRequestService). Emergency access is NOT wired to HighRiskPlatformChangePolicyService for platform-admin approval — see the Section 27 emergency_support_access_high_risk_approval_not_wired gap, which applies identically here since SaaS firms use the same support-access services as every other deployment mode.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function dedicated(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'dedicated_signed_offline_license_validation',
                item_label: 'Signed, offline license validation for Dedicated instances',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LicenseFileSigningService (Ed25519/sodium signing) and LicenseFileValidationService (fully offline verification, grace/restricted transitions) are real and thoroughly tested.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_fleet_migration_enrollment',
                item_label: 'Fleet migration enrollment for Dedicated instances',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::createRun() enrolls every current dedicated/private firm as a Pending instance automatically.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_version_skew_limit',
                item_label: 'Dedicated instance version skew limited to one minor version',
                owning_class: \App\Services\VersionSkewPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'VersionSkewPolicyService::check() enforces same-major-version and at most 1 minor version behind SaaS; an instance ahead of SaaS also fails.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_deployment_health_checks',
                item_label: 'Deployment health checks for Dedicated instances',
                owning_class: \App\Services\DeploymentHealthEnvelopeService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DeploymentHealthEnvelopeService::buildEnvelope() records heartbeat/version/migration-status and derives Healthy/Degraded from VersionSkewPolicyService — a real, tested mechanism.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_customer_type_controls',
                item_label: 'Dedicated customer-type approval controls',
                owning_class: \App\Services\DedicatedCustomerTypeApprovalService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DedicatedCustomerTypeApprovalService reuses the existing two-person HighRiskPlatformChangePolicyService workflow to gate a dedicated+legal_specialist firm via HighRiskChangeType::DedicatedLegalSpecialistApproval.',
            ),
            new GovernanceMappingResult(
                item_key: 'dedicated_custom_domain_storage_declarations',
                item_label: 'Custom domain/storage declarations for Dedicated/Private Enterprise instances',
                owning_class: \App\Models\PrivateEnterpriseSettings::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PrivateEnterpriseSettings.requires_custom_domain/requires_isolated_database/requires_isolated_storage are real, declarative booleans. Its own docblock states plainly: "no real provisioning happens in Phase 16." Implemented as declarations only, never as actual provisioning.',
            ),
        ];
    }

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function privateEnterprise(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'private_enterprise_custom_data_region',
                item_label: 'Custom data region for Private Enterprise firms',
                owning_class: \App\Models\Firm::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'firms.data_region is a real, nullable schema column (confirmed in both the Firm model\'s fillable and the firms migration), alongside primary_country/primary_state — a genuine representation of a firm\'s data region, even though no dedicated enforcement service reads it yet.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_support_access_rules',
                item_label: 'Support access rules for Private Enterprise firms',
                owning_class: \App\Services\SupportAccessPolicyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Same SupportAccessPolicyService/SupportAccessRequestService used by every deployment mode — standard access is governed, but emergency access is not wired to HighRiskPlatformChangePolicyService (Section 27 gap), which applies identically to Private Enterprise firms.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_retention',
                item_label: 'Retention policy coverage for Private Enterprise firms',
                owning_class: \App\Services\RetentionPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'RetentionPolicyService::resolveEffectivePolicyFor() is deployment-mode-agnostic and firm-scoped (firm override wins over platform default) — applies to Private Enterprise firms with no special-casing needed. RetentionRecordType includes AuditLog and AiLog cases explicitly.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_ai_mode',
                item_label: 'AI mode control for Private Enterprise firms',
                owning_class: \App\Enums\AiMode::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'AiMode (Disabled/PlatformManaged/FirmOwned) on firm_settings.ai_mode is real and enforced — FirmOwned requires an Active FirmAiProviderKey or the request must be blocked.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_degradation_modes_restricted_integrations',
                item_label: 'Restricted/degraded integrations for Private Enterprise firms (e.g. telemetry)',
                owning_class: \App\Models\PrivateEnterpriseSettings::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'PrivateEnterpriseSettings.telemetry_prohibited is real and enforced: DeploymentHealthEnvelopeService forces reported_via=offline_report when true. However, the general IntegrationDegradationRegistryService only covers IntegrationType::{Stripe,EmailProvider,VirusScanning,Telemetry} — AI providers and SMS/WhatsApp have no declared degradation mode at all, so a Private Enterprise firm restricting those has no formal mechanism to express it. See the integration_degradation_registry_missing_ai_sms_whatsapp gap.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_minimum_health_envelope',
                item_label: 'Minimum contractual health envelope for Private Enterprise firms',
                owning_class: \App\Services\DeploymentHealthEnvelopeService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DeploymentHealthEnvelopeService::buildEnvelope() records exactly the minimum contractual envelope (anonymized heartbeat, version, migration status) per the master plan, with no network call — it works identically whether or not telemetry is prohibited.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_security_review',
                item_label: 'Security review process for Private Enterprise firms',
                owning_class: null,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'No SecurityReview model, service, or representation of any kind exists anywhere in the repository (confirmed by direct search). This is a human/process requirement not yet represented in code at all — not a coding gap this package can close, and deliberately not claimed as a gap-register item since no owning gate has been approved for it.',
            ),
            new GovernanceMappingResult(
                item_key: 'private_enterprise_vendor_requirements',
                item_label: 'Vendor/subprocessor requirements for Private Enterprise firms',
                owning_class: \App\Services\VendorRegisterService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'VendorRegisterService/Vendor/Subprocessor (Section 25) track vendor risk/DPA/SOC/AI-zero-retention status and subprocessor disclosures — deployment-mode-agnostic, applies to Private Enterprise firms with no special-casing needed.',
            ),
        ];
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
