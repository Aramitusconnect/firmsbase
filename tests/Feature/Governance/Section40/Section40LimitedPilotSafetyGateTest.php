<?php

namespace Tests\Feature\Governance\Section40;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\Section40LimitedPilotSafetyGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * Section40LimitedPilotSafetyGateTest — proves the Section 40 limited
 * pilot safety gate correctly synthesizes the narrow internal-pilot
 * go/no-go question from live database state and existing, already-
 * tested governance/policy services, without ever hiding a gap, forcing
 * a table itself, or introducing any UI/route surface.
 */
class Section40LimitedPilotSafetyGateTest extends TestCase
{
    /**
     * FIRMSVAULT — STAGING ADMIN STABILIZATION (a later, independently
     * reviewed mission) legitimately touches files under this
     * checkpoint's own protected scope, by construction — see that
     * mission's own commit history for full context.
     */
    private const FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES = [
        'config/database.php',
        'app/Models/Plan.php',
        'app/Services/PlanService.php',
        'app/Services/PlanModuleService.php',
        'app/Services/FirmProvisioningService.php',
        'app/Exceptions/InactivePlanSelectedException.php',
        'app/Console/Commands/BootstrapStagingSandboxPlanCommand.php',
        'app/Filament/Actions/Platform/CreatePlanAction.php',
        'app/Filament/Actions/Platform/EditPlanAction.php',
        'app/Filament/Actions/Platform/AddPlanModuleAction.php',
        'app/Filament/Resources/PlanResource.php',
        'app/Filament/Resources/PlanResource/Pages/ListPlans.php',
        'app/Filament/Resources/PlanAddOnResource.php',
        'app/Filament/Resources/PlanAddOnResource/Pages/ListPlanAddOns.php',
        'database/migrations/2026_10_10_100001_add_code_and_description_to_plans_table.php',
        'database/factories/PlanFactory.php',
        'tests/Feature/Ecs/RedisTlsConfigurationTest.php',
        'tests/Feature/Plans/PlanServiceTest.php',
        'tests/Feature/Services/FirmProvisioningServiceTest.php',
        'tests/Feature/Console/BootstrapStagingSandboxPlanCommandTest.php',
        'tests/Feature/PlatformAdmin/PlanCatalogCreateActionsTest.php',
        'tests/Feature/Security/RlsContextRollout/QueueConsoleContextRolloutTest.php',
        'tests/Feature/Security/RlsEnforcement/QueueConsoleTenantContextTest.php',
        'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
        'tests/Feature/Integrations/Ui/FirmIntegrationSuperAdminBoundaryStructuralTest.php',
        'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
        'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
        'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
        'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
        'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
        'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
        'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
        'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
        'tests/Feature/Security/SeedData/SeedDataAuditFirewallTest.php',
        'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
    ];

    use EvaluatesHistoricalCheckpointScope;
    use RefreshDatabase;

    public function test_gate_fails_pilot_critical_rls_status_if_a_pilot_critical_table_is_not_forced(): void
    {
        DB::statement('ALTER TABLE "payments" NO FORCE ROW LEVEL SECURITY');

        try {
            $gate = new Section40LimitedPilotSafetyGateService;

            $this->assertFalse($gate->isPilotCriticalRlsFullyForced());
            $this->assertFalse($gate->pilotCriticalForceRlsStatus()['payments']);
            $this->assertFalse($gate->hasNoActiveHighRiskBlockerForInternalLoginTesting());
            $this->assertFalse($gate->evaluate()['internal_pilot_login_panel_domain_smoke_testing_recommended']);
        } finally {
            DB::statement('ALTER TABLE "payments" FORCE ROW LEVEL SECURITY');
        }
    }

    public function test_gate_passes_pilot_critical_rls_status_when_all_required_tables_are_forced(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->isPilotCriticalRlsFullyForced());

        foreach ($gate->pilotCriticalTables() as $table) {
            $this->assertTrue($gate->pilotCriticalForceRlsStatus()[$table], "{$table} must be reported as forced.");
        }
    }

    public function test_gate_reports_remaining_prepared_but_unforced_tables(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;
        $coverage = new RowLevelSecurityCoverageMappingService;

        $remaining = $gate->remainingPreparedUnforcedTables();

        // Deliberately NOT asserted as preparedTables() count minus
        // pilotCriticalTables() count: Section 39A-3I (a later,
        // distinct staged-FORCE-activation branch) forced
        // conflict_check_runs too, which is a real, live FORCE table
        // but was never one of Section 40's hardcoded 8
        // "pilot-critical" tables — that static formula would silently
        // drift stale every time a non-pilot-critical table is forced.
        // remainingPreparedUnforcedTables() queries live pg_class
        // state directly, so the correct expectation is derived the
        // same way here: every prepared table minus however many are
        // ACTUALLY forced right now.
        $actuallyForcedCount = count(array_filter(
            $coverage->preparedTables(),
            fn (string $table) => DB::selectOne(
                'select relforcerowsecurity from pg_class where relname = ?',
                [$table]
            )->relforcerowsecurity,
        ));

        $this->assertSame(count($coverage->preparedTables()) - $actuallyForcedCount, count($remaining));

        foreach ($gate->pilotCriticalTables() as $table) {
            $this->assertNotContains($table, $remaining, "{$table} is forced and must not appear in the remaining-unforced list.");
        }
    }

    /**
     * Updated by Section 39A-5 Wave 11 (webhooks domain, the final
     * wave of the 60-table RLS rollout): 39A-4 (uncovered tenant table
     * classification) was, at every point before this wave, genuinely
     * still outstanding — this test's name and original assertion
     * reflected that real, historical state. Wave 11 closed the last 5
     * remaining uncovered tables, so uncoveredTenantTables() is now
     * genuinely, honestly empty — a real, positive end state computed
     * live from RowLevelSecurityCoverageMappingService, not a hidden/
     * suppressed gap. The method name is left unchanged (it names the
     * historical concern this test guards, not a permanently-fixed
     * count), but the assertion below is updated to check the true,
     * current state so a FUTURE regression that reintroduces an
     * uncovered table fails this test loudly rather than being masked
     * by a stale "greater than zero" expectation.
     */
    public function test_gate_reports_uncovered_tenant_tables_still_outstanding_for_39a4(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;
        $coverage = new RowLevelSecurityCoverageMappingService;

        $uncovered = $gate->uncoveredTenantTables();

        $this->assertSame($coverage->missingPreparedTables(), $uncovered);
        $this->assertCount(0, $uncovered, '39A-4 (uncovered tenant table classification) is fully complete as of Section 39A-5 Wave 11 — zero tenant-owned tables remain without RLS preparation.');
    }

    public function test_gate_confirms_firm_user_2fa_policy_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->isFirmUser2faPolicyReady());
    }

    public function test_gate_confirms_login_policy_wrapper_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->isLoginPolicyWrapperReady());
    }

    public function test_gate_confirms_emergency_support_approval_wiring_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->isEmergencySupportApprovalReady());
    }

    public function test_gate_confirms_seed_default_secret_audit_exists_and_is_clean(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->isSeedDataAuditClean());
    }

    public function test_gate_does_not_hide_existing_compliance_gaps(): void
    {
        $registry = new ComplianceGapRegistryService;
        $gate = new Section40LimitedPilotSafetyGateService;

        $result = $gate->evaluate();

        $this->assertSame(count($registry->all()), $result['gap_registry_count']);
        $this->assertCount(21, $registry->all());
        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));

        // Section 39A-3L, Phase B6, Checkpoint 34 (security_events) is
        // the fifty-second and FINAL of the originally-"prepared" tables
        // to reach permanent FORCE ROW LEVEL SECURITY — a real, positive
        // end state computed live from RowLevelSecurityCoverageMappingService
        // (see Section40LimitedPilotSafetyGateService::remainingPreparedUnforcedTables()),
        // not a hidden/suppressed gap. This assertion is deliberately
        // exact (not "greater than zero") so that if a FUTURE regression
        // ever un-forces one of these 52 tables, this test fails loudly
        // rather than silently tolerating it.
        $this->assertSame(0, $result['remaining_prepared_unforced_count']);

        // Updated by Section 39A-5 Wave 11 (webhooks domain, the final
        // wave of the 60-table RLS rollout): uncovered_tenant_table_count
        // was, at every point before this wave, genuinely greater than
        // zero — this assertion reflected that real, historical state.
        // Wave 11 closed the last 5 remaining uncovered tables, so this
        // count is now genuinely, honestly zero — a real, positive end
        // state, not a hidden/suppressed gap. Deliberately exact (not
        // "greater than zero") so a FUTURE regression that reintroduces
        // an uncovered tenant-owned table fails this test loudly.
        $this->assertSame(0, $result['uncovered_tenant_table_count']);
        $this->assertNotEmpty($result['public_production_launch_limitations']);
    }

    public function test_gate_distinguishes_internal_pilot_readiness_from_public_production_readiness(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;
        $result = $gate->evaluate();

        $this->assertTrue($result['internal_pilot_login_panel_domain_smoke_testing_recommended']);
        $this->assertFalse($result['public_production_launch_recommended']);
        $this->assertNotEmpty($result['public_production_launch_limitations']);
        $this->assertStringContainsString('SMOKE TESTING ONLY', $result['notes']);
        $this->assertStringContainsString('does not permit public production launch', $result['notes']);
    }

    public function test_gate_recommends_smoke_testing_only_when_required_security_checks_pass(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->hasNoActiveHighRiskBlockerForInternalLoginTesting());
        $this->assertTrue($gate->evaluate()['internal_pilot_login_panel_domain_smoke_testing_recommended']);

        DB::statement('ALTER TABLE "clients" NO FORCE ROW LEVEL SECURITY');

        try {
            $degraded = new Section40LimitedPilotSafetyGateService;

            $this->assertFalse($degraded->hasNoActiveHighRiskBlockerForInternalLoginTesting());
            $this->assertFalse($degraded->evaluate()['internal_pilot_login_panel_domain_smoke_testing_recommended']);
        } finally {
            DB::statement('ALTER TABLE "clients" FORCE ROW LEVEL SECURITY');
        }
    }

    public function test_gate_reports_no_known_cross_firm_data_exposure_when_rls_is_forced(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->hasNoKnownCrossFirmDataExposure());
    }

    public function test_gate_reports_no_public_legal_document_urls(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService;

        $this->assertTrue($gate->hasNoPublicLegalDocumentUrls());
    }

    public function test_gate_service_itself_created_no_routes_controllers_or_ui(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            // Phase 2 of the FirmsVault Platform Admin Control Center
            // mission ("Integration Operations Center"; a later,
            // entirely distinct mission from Section 40) legitimately
            // modified PlatformFirmIntegrationsPage.php (query
            // determinism + genuine DB-level pagination fixes) — real
            // UI work belonging to that later mission, not this
            // inspection-only gate service.
            $changed = array_values(array_filter(
                $this->changedOrUntrackedPaths($relativeDir),
                fn (string $path) => $path !== 'app/Filament/Pages/PlatformFirmIntegrationsPage.php',
            ));

            $this->assertEmpty($changed, "Section 40 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched by this inspection-only section.');
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = $this->changedOrUntrackedPathsRaw($scope);

        if ($changed === '') {
            return [];
        }

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }
}
