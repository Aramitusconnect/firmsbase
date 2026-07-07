<?php

namespace App\Services;

use App\Enums\GovernanceGapSeverity;
use App\ValueObjects\GapRegisterItem;

/**
 * ComplianceGapRegistryService — a static, array-backed register of
 * known cross-cutting gaps surfaced by SecurityBaselineMappingService/
 * ComplianceReviewGateMappingService/AccessibilityCoverageMappingService.
 * No gap_register table exists or is created — this class IS the
 * entire register (approved decision #3).
 */
class ComplianceGapRegistryService
{
    private const GAP_ITEMS = [
        [
            'key' => 'rls_prepared_not_enforced',
            'area' => 'tenant_isolation',
            'description' => 'PostgreSQL row-level security policies are prepared (ENABLE ROW LEVEL SECURITY + CREATE POLICY) but not enforced (no FORCE ROW LEVEL SECURITY, no SET LOCAL app.current_firm_id wiring). Defense-in-depth is not yet active at the database layer. RLS preparation itself is also incomplete: it covers only tenant tables introduced through Phase 6 — every tenant-owned table introduced from Phase 7 onward (see RowLevelSecurityCoverageMappingService::missingPreparedTables()) has no RLS policy at all. Later tenant-owned tables must be covered before FORCE ROW LEVEL SECURITY / SET LOCAL enforcement can safely be turned on for the whole schema. Section 28 test-coverage impact: the master plan\'s required "broken query scope caught by row-level security" test group cannot honestly be classified Implemented while this same blocker persists — tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php explicitly asserts FORCE ROW LEVEL SECURITY is NOT enabled, proving there is nothing at the database layer yet to catch a broken/bypassed application-layer scope. See TestCoverageMappingService::byKey(\'tenant_isolation_broken_scope_caught_by_rls\'). Section 29 deployment-mode impact: SaaS is the deployment mode most exposed by this gap, since SaaS firms share the same database/schema and rely on tenant isolation the most heavily — see DeploymentModeCoverageMappingService::byKey(\'saas_firm_isolation_rls_defense_in_depth\').',
            'severity' => GovernanceGapSeverity::High,
            'suggested_owning_gate' => 'Phase 1 RLS Enforcement Activation',
            'status' => 'open',
        ],
        [
            'key' => 'firm_user_2fa_missing',
            'area' => 'authentication',
            'description' => 'No two-factor authentication verification/enforcement exists for firm users. users.two_factor_* columns exist as unused Laravel default scaffolding only.',
            'severity' => GovernanceGapSeverity::High,
            'suggested_owning_gate' => 'future authentication-hardening phase',
            'status' => 'open',
        ],
        [
            'key' => 'client_portal_2fa_missing',
            'area' => 'authentication',
            'description' => 'No two-factor authentication exists for client portal logins. firm_settings.client_2fa_mode is an unenforced attribute only; no client portal exists yet to enforce it against.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future authentication-hardening phase',
            'status' => 'open',
        ],
        [
            'key' => 'login_policy_wrappers_missing',
            'area' => 'authentication',
            'description' => 'No login rate limiting or password rule enforcement exists — there is no login/registration HTTP flow anywhere in the repo to attach either to yet.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future web/HTTP layer phase',
            'status' => 'open',
        ],
        [
            'key' => 'signed_document_url_service_missing',
            'area' => 'document_security',
            'description' => 'No signed, time-limited temporary URL service exists for sharing a document; DocumentSecurityService::canAccess() is the documented gate any future implementation must call first.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future web/HTTP layer phase',
            'status' => 'open',
        ],
        [
            'key' => 'real_malware_scanning_engine_stubbed',
            'area' => 'document_security',
            'description' => 'VirusScanner\'s only implementation is FakeVirusScanner (deterministic, no real scanning engine). The document-acceptance gate itself is real and enforced; only the scanning engine behind it is a stub.',
            'severity' => GovernanceGapSeverity::Low,
            'suggested_owning_gate' => 'production-readiness/ops phase',
            'status' => 'open',
        ],
        [
            'key' => 'auth_admin_override_events_generic_only',
            'area' => 'audit_logging',
            'description' => 'Authentication and admin-override events are recorded only via the generic SecurityEvent model / TimelineEventRecorder, not a dedicated authentication-event or admin-override model/columns.',
            'severity' => GovernanceGapSeverity::Low,
            'suggested_owning_gate' => 'accepted generic SecurityEvent/TimelineEventRecorder mapping; no second audit system recommended',
            'status' => 'open',
        ],
        [
            'key' => 'org_admin_role_missing',
            'area' => 'permission_matrix',
            'description' => 'No org_admin role exists: no organization_users table, no OrganizationRole enum, no organization-level admin grant/membership mechanism of any kind. Organization-level administration (as distinct from firm-level FirmUserRole access) is a real, unimplemented boundary — confirmed by direct repository search.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future organization administration access model',
            'status' => 'open',
        ],
        [
            'key' => 'emergency_support_access_high_risk_approval_not_wired',
            'area' => 'permission_matrix',
            'description' => 'HighRiskChangeType::EmergencySupportAccess exists and HighRiskPlatformChangePolicyService can process it in isolation, but SupportAccessPolicyService/SupportAccessRequestService never call it — the real emergency support access flow allows a request the instant emergency_justification is non-empty, with no platform-admin approval step and no high_risk_change_requests row ever created for it.',
            'severity' => GovernanceGapSeverity::High,
            'suggested_owning_gate' => 'future emergency-access hardening phase',
            'status' => 'open',
        ],
        [
            'key' => 'seed_data_defaults_and_test_secrets_not_audited',
            'area' => 'release_quality_gates',
            'description' => 'No seed-data audit/check service exists anywhere in the repository (confirmed by direct search). database/seeders/DatabaseSeeder.php seeds a fixed test@example.com user via the default UserFactory, whose password is always the literal string "password" (Hash::make(\'password\')) — a classic test-secret default with nothing in place to flag or rotate it before a real deployment.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future release-engineering phase',
            'status' => 'open',
        ],
        [
            'key' => 'restore_tests_do_not_exercise_real_restore_path',
            'area' => 'release_quality_gates',
            'description' => 'BackupRestoreTestService\'s own docblock states it "never performs a real infrastructure backup/restore" — it only records the result of whatever BackupRestoreDrillRunner it is given, and FakeBackupRestoreDrillRunner is the only implementation exercised by tests/Feature/BackupRestore/BackupRestoreTestServiceTest.php. Restore testing today is readiness/bookkeeping only and does not exercise a real restore path.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future restore-drill hardening phase',
            'status' => 'open',
        ],
        [
            'key' => 'integration_degradation_registry_missing_ai_sms_whatsapp',
            'area' => 'deployment_environment',
            'description' => 'IntegrationDegradationRegistryService only declares a degradation mode for IntegrationType::{Stripe,EmailProvider,VirusScanning,Telemetry}. AiProvider (5 real, modeled provider cases backed by FirmAiProviderKey) and ConsentChannel::{Sms,WhatsApp} (real, modeled communication channels) are external dependencies with no declared degradation mode at all. everyIntegrationHasADeclaredMode() is scoped only to IntegrationType::cases(), so it would silently report coverage as complete even though these three dependencies are uncovered.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future integration-degradation expansion',
            'status' => 'open',
        ],
        [
            'key' => 'secret_rotation_schedule_or_reminder_missing',
            'area' => 'deployment_environment',
            'description' => 'AiProviderKeyService::rotate() and EncryptionKeyService::rotate() are real, callable rotation capabilities, but no automated rotation schedule, key-age policy, or reminder mechanism exists anywhere in the repository (confirmed by direct search) — rotation only ever happens if a human explicitly calls it.',
            'severity' => GovernanceGapSeverity::Low,
            'suggested_owning_gate' => 'future operational-hardening phase',
            'status' => 'open',
        ],
        [
            'key' => 'client_facing_payment_receipts_missing',
            'area' => 'market_ready_value_multipliers',
            'description' => 'No client-facing payment receipt concept exists anywhere in the repository (confirmed by direct search). The only Receipt-named model is ExpenseReceipt, an internal firm expense record unrelated to confirming a client\'s payment. Payment rows are real and canonical, but nothing renders or issues a receipt from them back to a client.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future mobile-portal implementation phase',
            'status' => 'open',
        ],
        [
            'key' => 'template_pack_per_pack_commercial_differentiation_missing',
            'area' => 'market_ready_value_multipliers',
            'description' => 'TemplatePackCommercialService::installIfEntitled() gates every template pack behind a single blanket "practice_area_templates" module entitlement resolved via EntitlementService. No per-pack pricing, tier, add-on-purchase, or implementation-services-bundle mechanism exists anywhere (confirmed by direct search) — a firm entitled to install one pack is equally entitled to install any other, with no commercial differentiation between packs.',
            'severity' => GovernanceGapSeverity::Medium,
            'suggested_owning_gate' => 'future template-pack commercialization phase',
            'status' => 'open',
        ],
    ];

    /**
     * @return array<int, GapRegisterItem>
     */
    public function all(): array
    {
        return array_map(fn (array $item) => new GapRegisterItem(
            key: $item['key'],
            area: $item['area'],
            description: $item['description'],
            severity: $item['severity'],
            suggested_owning_gate: $item['suggested_owning_gate'],
            status: $item['status'],
        ), self::GAP_ITEMS);
    }

    /**
     * @return array<int, GapRegisterItem>
     */
    public function bySeverity(GovernanceGapSeverity $severity): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GapRegisterItem $item) => $item->severity === $severity,
        ));
    }

    public function isTracked(string $key): bool
    {
        return $this->byKey($key) !== null;
    }

    public function byKey(string $key): ?GapRegisterItem
    {
        foreach ($this->all() as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }
}
