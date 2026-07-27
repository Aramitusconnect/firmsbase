<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Section40LimitedPilotSafetyGateService — the Section 40 "limited pilot
 * safety gate." Purely read-only and declarative, like
 * PrePilotRemediationBacklogService/ProfessionalReviewGateMappingService
 * before it: it never forces RLS, never writes a migration, never
 * creates a route, and never modifies ComplianceGapRegistryService. It
 * only inspects the live database (pg_class), the live route table, and
 * a handful of existing, already-tested governance/policy services, and
 * synthesizes a single go/no-go answer for exactly one narrow question:
 * is it safe to begin INTERNAL login/panel/domain SMOKE TESTING.
 *
 * This gate is deliberately NOT a public production launch gate. It
 * both computes and reports explicit limitations (remaining
 * prepared-but-unforced RLS tables, the fully uncovered Section 39A-4
 * tenant tables, missing legal documents, no domain/HTTPS, no
 * production hardening) that must remain true and visible even when the
 * internal-pilot answer is yes — a future, separate gate is required
 * before any public production launch.
 */
class Section40LimitedPilotSafetyGateService
{
    /**
     * The 8 tables Section 39A-3A through 39A-3H forced, in the order
     * they were forced.
     *
     * @var array<int, string>
     */
    public const PILOT_CRITICAL_TABLES = [
        'clients', 'firm_users', 'documents', 'deadlines', 'tasks', 'matters', 'invoices', 'payments',
    ];

    /**
     * Substrings that would identify a public legal-document route if
     * one existed (none should exist yet — this section does not add
     * any).
     *
     * @var array<int, string>
     */
    private const LEGAL_DOCUMENT_ROUTE_MARKERS = ['terms', 'privacy', 'legal', 'tos', 'dpa', 'subprocessor'];

    /**
     * @return array<int, string>
     */
    public function pilotCriticalTables(): array
    {
        return self::PILOT_CRITICAL_TABLES;
    }

    /**
     * Direct pg_class query — never trusts a cached/declarative flag —
     * for each pilot-critical table's current FORCE ROW LEVEL SECURITY
     * state.
     *
     * @return array<string, bool>
     */
    public function pilotCriticalForceRlsStatus(): array
    {
        $status = [];

        foreach (self::PILOT_CRITICAL_TABLES as $table) {
            $status[$table] = $this->isTableForced($table);
        }

        return $status;
    }

    public function isPilotCriticalRlsFullyForced(): bool
    {
        return ! in_array(false, $this->pilotCriticalForceRlsStatus(), true);
    }

    /**
     * Every RLS-prepared table (per the same live coverage service the
     * existing RLS firewall tests use) that is not currently FORCE
     * enabled — includes pilot-critical tables too if any were somehow
     * un-forced, so this list is always a true reflection of live
     * database state, not an assumption.
     *
     * @return array<int, string>
     */
    public function remainingPreparedUnforcedTables(): array
    {
        $remaining = [];

        foreach ((new RowLevelSecurityCoverageMappingService)->preparedTables() as $table) {
            if (! $this->isTableForced($table)) {
                $remaining[] = $table;
            }
        }

        return $remaining;
    }

    /**
     * Tenant-owned tables with no RLS preparation at all — the full
     * scope of the still-outstanding Section 39A-4 classification work.
     *
     * @return array<int, string>
     */
    public function uncoveredTenantTables(): array
    {
        return (new RowLevelSecurityCoverageMappingService)->missingPreparedTables();
    }

    public function isLoginPolicyWrapperReady(): bool
    {
        return class_exists(LoginPolicyService::class)
            && method_exists(LoginPolicyService::class, 'canAttemptFirmLogin')
            && method_exists(LoginPolicyService::class, 'shouldThrottleAttempt')
            && method_exists(LoginPolicyService::class, 'shouldExpireSession')
            && method_exists(LoginPolicyService::class, 'passwordMeetsPolicy');
    }

    public function isFirmUser2faPolicyReady(): bool
    {
        return class_exists(FirmUser2faPolicyService::class)
            && method_exists(FirmUser2faPolicyService::class, 'isRequiredForFirm')
            && method_exists(FirmUser2faPolicyService::class, 'isCompliant')
            && method_exists(FirmUser2faPolicyService::class, 'firmIsReadyForPilotData');
    }

    public function isEmergencySupportApprovalReady(): bool
    {
        return (new EmergencyAccessGovernanceGapService)->isHighRiskApprovalWired();
    }

    public function isSeedDataAuditClean(): bool
    {
        return (new SeedDataSecurityAuditService)->isClean();
    }

    /**
     * Confirms no route anywhere in the live route table serves a
     * legal document (Terms, Privacy, DPA, etc.) publicly — this
     * section adds none, and none should already exist.
     *
     * FIRMSVAULT-ADMIN-CONTROL-CENTER-PHASE-4 UPDATE (Governance):
     * skips routes under the Filament `admin` panel's own `admin/`
     * path prefix (`AdminPanelProvider::path('admin')`) — every route
     * under that prefix requires authenticated PlatformAdmin access
     * (`canAccess()` on every Resource/Page, confirmed throughout this
     * entire mission), so it can never be a PUBLIC legal-document URL
     * regardless of its slug. This phase legitimately adds
     * `admin/legal-holds`, a private, authenticated legal-HOLD
     * case-management resource (placing/releasing litigation holds on
     * client data) — a completely different concept from a public
     * legal DOCUMENT page (Terms of Service, Privacy Policy, DPA),
     * which this check still catches unconditionally for every OTHER
     * route, public or admin-prefixed. Narrows the check from "no
     * marker substring anywhere" to "no marker substring on any
     * non-admin route," never weakens it for a genuinely public route.
     */
    public function hasNoPublicLegalDocumentUrls(): bool
    {
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            if ($uri === 'admin' || str_starts_with($uri, 'admin/')) {
                continue;
            }

            foreach (self::LEGAL_DOCUMENT_ROUTE_MARKERS as $marker) {
                if (str_contains($uri, $marker)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Derived directly from the same live FORCE RLS state the
     * RlsForceRollout/RlsContextRollout/RlsEnforcement proof-test suites
     * (Sections 39A-3A-3H) assert against — this method re-checks that
     * live state, it does not re-run those tests itself.
     */
    public function hasNoKnownCrossFirmDataExposure(): bool
    {
        return $this->isPilotCriticalRlsFullyForced();
    }

    /**
     * The single, narrow go/no-go answer this entire gate exists to
     * compute: nothing currently blocks starting INTERNAL
     * login/panel/domain smoke testing.
     */
    public function hasNoActiveHighRiskBlockerForInternalLoginTesting(): bool
    {
        return $this->isPilotCriticalRlsFullyForced()
            && $this->isLoginPolicyWrapperReady()
            && $this->isFirmUser2faPolicyReady()
            && $this->isEmergencySupportApprovalReady()
            && $this->isSeedDataAuditClean()
            && $this->hasNoPublicLegalDocumentUrls();
    }

    /**
     * Explicit, always-visible limitations that block PUBLIC PRODUCTION
     * launch specifically — deliberately returned even when the
     * internal-pilot gate itself passes, so a passing internal gate can
     * never be mistaken for public launch readiness.
     *
     * @return array<int, string>
     */
    public function publicProductionLaunchLimitations(): array
    {
        $limitations = [];

        $remainingPreparedUnforced = $this->remainingPreparedUnforcedTables();
        $uncoveredTenantTables = $this->uncoveredTenantTables();

        if (count($remainingPreparedUnforced) > 0) {
            $limitations[] = sprintf(
                '%d prepared-but-unforced RLS tables remain (Section 39A-3I+ not complete).',
                count($remainingPreparedUnforced),
            );
        }

        if (count($uncoveredTenantTables) > 0) {
            $limitations[] = sprintf(
                '%d tenant-owned tables have no RLS preparation at all (Section 39A-4 classification not started).',
                count($uncoveredTenantTables),
            );
        }

        $limitations[] = 'No legal documents exist yet (Terms of Service, Privacy Policy, DPA, Subprocessor list, Acceptable Use Policy, AI/no-legal-advice disclaimers) — see PrePilotRemediationBacklogService::legalCommercialLaunchChecklist().';
        $limitations[] = 'No domain or HTTPS is connected — this section explicitly does not connect one.';
        $limitations[] = 'No production hardening checklist item has been completed (real backup runner, restore rehearsal, queue/scheduler supervision, real malware scanning engine, secret rotation, monitoring/alerting) — see PrePilotRemediationBacklogService::productionHardeningChecklist().';
        $limitations[] = 'No login UI, Filament panel wiring, or public route exists yet — this section is inspection/reporting only.';

        return $limitations;
    }

    /**
     * @return array{
     *     pilot_critical_tables: array<int, string>,
     *     pilot_critical_force_rls_status: array<string, bool>,
     *     pilot_critical_rls_fully_forced: bool,
     *     remaining_prepared_unforced_tables: array<int, string>,
     *     remaining_prepared_unforced_count: int,
     *     uncovered_tenant_tables: array<int, string>,
     *     uncovered_tenant_table_count: int,
     *     login_policy_wrapper_ready: bool,
     *     firm_user_2fa_policy_ready: bool,
     *     emergency_support_approval_ready: bool,
     *     seed_data_audit_clean: bool,
     *     no_public_legal_document_urls: bool,
     *     no_known_cross_firm_data_exposure: bool,
     *     no_active_high_risk_blocker_for_internal_login_testing: bool,
     *     public_production_launch_limitations: array<int, string>,
     *     internal_pilot_login_panel_domain_smoke_testing_recommended: bool,
     *     public_production_launch_recommended: bool,
     *     gap_registry_count: int,
     *     notes: string,
     * }
     */
    public function evaluate(): array
    {
        $noBlocker = $this->hasNoActiveHighRiskBlockerForInternalLoginTesting();
        $remainingPreparedUnforced = $this->remainingPreparedUnforcedTables();
        $uncoveredTenantTables = $this->uncoveredTenantTables();
        $limitations = $this->publicProductionLaunchLimitations();

        return [
            'pilot_critical_tables' => $this->pilotCriticalTables(),
            'pilot_critical_force_rls_status' => $this->pilotCriticalForceRlsStatus(),
            'pilot_critical_rls_fully_forced' => $this->isPilotCriticalRlsFullyForced(),
            'remaining_prepared_unforced_tables' => $remainingPreparedUnforced,
            'remaining_prepared_unforced_count' => count($remainingPreparedUnforced),
            'uncovered_tenant_tables' => $uncoveredTenantTables,
            'uncovered_tenant_table_count' => count($uncoveredTenantTables),
            'login_policy_wrapper_ready' => $this->isLoginPolicyWrapperReady(),
            'firm_user_2fa_policy_ready' => $this->isFirmUser2faPolicyReady(),
            'emergency_support_approval_ready' => $this->isEmergencySupportApprovalReady(),
            'seed_data_audit_clean' => $this->isSeedDataAuditClean(),
            'no_public_legal_document_urls' => $this->hasNoPublicLegalDocumentUrls(),
            'no_known_cross_firm_data_exposure' => $this->hasNoKnownCrossFirmDataExposure(),
            'no_active_high_risk_blocker_for_internal_login_testing' => $noBlocker,
            'public_production_launch_limitations' => $limitations,
            'internal_pilot_login_panel_domain_smoke_testing_recommended' => $noBlocker,
            'public_production_launch_recommended' => false,
            'gap_registry_count' => count((new ComplianceGapRegistryService)->all()),
            'notes' => 'This gate permits INTERNAL pilot/login/panel/domain SMOKE TESTING ONLY. It does not permit public production launch under any evaluate() result. Section 39A-4 (uncovered tenant table classification) and any remaining prepared-but-unforced FORCE RLS work are explicitly outstanding and do not block this limited internal gate — they are reported, not hidden. A separate, later gate is required before any public production launch.',
        ];
    }

    private function isTableForced(string $table): bool
    {
        $row = DB::selectOne('select relforcerowsecurity from pg_class where relname = ?', [$table]);

        return $row !== null && (bool) $row->relforcerowsecurity;
    }
}
