<?php

namespace Tests\Feature\Governance\Section40;

use App\Services\ComplianceGapRegistryService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\Section40LimitedPilotSafetyGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    use RefreshDatabase;

    public function test_gate_fails_pilot_critical_rls_status_if_a_pilot_critical_table_is_not_forced(): void
    {
        DB::statement('ALTER TABLE "payments" NO FORCE ROW LEVEL SECURITY');

        try {
            $gate = new Section40LimitedPilotSafetyGateService();

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
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->isPilotCriticalRlsFullyForced());

        foreach ($gate->pilotCriticalTables() as $table) {
            $this->assertTrue($gate->pilotCriticalForceRlsStatus()[$table], "{$table} must be reported as forced.");
        }
    }

    public function test_gate_reports_remaining_prepared_but_unforced_tables(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();
        $coverage = new RowLevelSecurityCoverageMappingService();

        $remaining = $gate->remainingPreparedUnforcedTables();

        $this->assertSame(
            count($coverage->preparedTables()) - count($gate->pilotCriticalTables()),
            count($remaining),
        );

        foreach ($gate->pilotCriticalTables() as $table) {
            $this->assertNotContains($table, $remaining, "{$table} is forced and must not appear in the remaining-unforced list.");
        }
    }

    public function test_gate_reports_uncovered_tenant_tables_still_outstanding_for_39a4(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();
        $coverage = new RowLevelSecurityCoverageMappingService();

        $uncovered = $gate->uncoveredTenantTables();

        $this->assertSame($coverage->missingPreparedTables(), $uncovered);
        $this->assertGreaterThan(0, count($uncovered), '39A-4 must still be outstanding at this point in the rollout.');
    }

    public function test_gate_confirms_firm_user_2fa_policy_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->isFirmUser2faPolicyReady());
    }

    public function test_gate_confirms_login_policy_wrapper_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->isLoginPolicyWrapperReady());
    }

    public function test_gate_confirms_emergency_support_approval_wiring_exists(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->isEmergencySupportApprovalReady());
    }

    public function test_gate_confirms_seed_default_secret_audit_exists_and_is_clean(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->isSeedDataAuditClean());
    }

    public function test_gate_does_not_hide_existing_compliance_gaps(): void
    {
        $registry = new ComplianceGapRegistryService();
        $gate = new Section40LimitedPilotSafetyGateService();

        $result = $gate->evaluate();

        $this->assertSame(count($registry->all()), $result['gap_registry_count']);
        $this->assertCount(21, $registry->all());
        $this->assertTrue($registry->isTracked('rls_prepared_not_enforced'));
        $this->assertGreaterThan(0, $result['remaining_prepared_unforced_count']);
        $this->assertGreaterThan(0, $result['uncovered_tenant_table_count']);
        $this->assertNotEmpty($result['public_production_launch_limitations']);
    }

    public function test_gate_distinguishes_internal_pilot_readiness_from_public_production_readiness(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();
        $result = $gate->evaluate();

        $this->assertTrue($result['internal_pilot_login_panel_domain_smoke_testing_recommended']);
        $this->assertFalse($result['public_production_launch_recommended']);
        $this->assertNotEmpty($result['public_production_launch_limitations']);
        $this->assertStringContainsString('SMOKE TESTING ONLY', $result['notes']);
        $this->assertStringContainsString('does not permit public production launch', $result['notes']);
    }

    public function test_gate_recommends_smoke_testing_only_when_required_security_checks_pass(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->hasNoActiveHighRiskBlockerForInternalLoginTesting());
        $this->assertTrue($gate->evaluate()['internal_pilot_login_panel_domain_smoke_testing_recommended']);

        DB::statement('ALTER TABLE "clients" NO FORCE ROW LEVEL SECURITY');

        try {
            $degraded = new Section40LimitedPilotSafetyGateService();

            $this->assertFalse($degraded->hasNoActiveHighRiskBlockerForInternalLoginTesting());
            $this->assertFalse($degraded->evaluate()['internal_pilot_login_panel_domain_smoke_testing_recommended']);
        } finally {
            DB::statement('ALTER TABLE "clients" FORCE ROW LEVEL SECURITY');
        }
    }

    public function test_gate_reports_no_known_cross_firm_data_exposure_when_rls_is_forced(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->hasNoKnownCrossFirmDataExposure());
    }

    public function test_gate_reports_no_public_legal_document_urls(): void
    {
        $gate = new Section40LimitedPilotSafetyGateService();

        $this->assertTrue($gate->hasNoPublicLegalDocumentUrls());
    }

    public function test_gate_service_itself_created_no_routes_controllers_or_ui(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 40 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
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
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
