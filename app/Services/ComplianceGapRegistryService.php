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
            'description' => 'PostgreSQL row-level security policies are prepared (ENABLE ROW LEVEL SECURITY + CREATE POLICY) but not enforced (no FORCE ROW LEVEL SECURITY, no SET LOCAL app.current_firm_id wiring). Defense-in-depth is not yet active at the database layer. RLS preparation itself is also incomplete: it covers only tenant tables introduced through Phase 6 — every tenant-owned table introduced from Phase 7 onward (see RowLevelSecurityCoverageMappingService::missingPreparedTables()) has no RLS policy at all. Later tenant-owned tables must be covered before FORCE ROW LEVEL SECURITY / SET LOCAL enforcement can safely be turned on for the whole schema.',
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
