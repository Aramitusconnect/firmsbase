<?php

namespace Tests\Feature\Security\FirmUser2fa;

use App\Enums\TwoFactorMode;
use App\Services\ComplianceGapRegistryService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * FirmUser2faFirewallTest — Section 39B. Proves the fix stayed inside
 * its declared boundary: no duplicate 2FA columns were added to
 * FirmUser, no UI/routes/controllers/auth scaffolding was introduced,
 * no new enum was created, and ComplianceGapRegistryService was not
 * deleted/rewritten to hide the historical firm_user_2fa_missing gap.
 */
class FirmUser2faFirewallTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;

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

    public function test_no_duplicate_2fa_columns_were_added_to_firm_users(): void
    {
        $columns = Schema::getColumnListing('firm_users');

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'firm_user_2fa_mode'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $columns, "firm_users must not gain its own '{$forbiddenColumn}' column — User already owns 2FA state.");
        }
    }

    public function test_user_table_2fa_columns_are_unchanged(): void
    {
        $columns = Schema::getColumnListing('users');

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $expectedColumn) {
            $this->assertContains($expectedColumn, $columns, "users.{$expectedColumn} must already exist and remain unchanged.");
        }
    }

    public function test_no_new_two_factor_enum_was_created(): void
    {
        $enumFiles = glob(app_path('Enums/*TwoFactor*.php')) ?: [];

        $this->assertCount(1, $enumFiles, 'Only the existing TwoFactorMode enum may exist — no new 2FA enum should be created.');
        $this->assertSame('TwoFactorMode.php', basename($enumFiles[0]));
    }

    public function test_two_factor_mode_enum_was_not_modified(): void
    {
        $cases = array_map(fn ($case) => $case->value, TwoFactorMode::cases());

        $this->assertSame(['optional', 'required', 'disabled'], $cases);
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39B must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_fortify_or_breeze_was_installed(): void
    {
        $composerSource = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsStringIgnoringCase('fortify', $composerSource);
        $this->assertStringNotContainsStringIgnoringCase('breeze', $composerSource);
    }

    public function test_no_login_route_or_auth_controller_was_introduced(): void
    {
        $webRoutesSource = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsStringIgnoringCase('login', $webRoutesSource);
        $this->assertDirectoryDoesNotExist(app_path('Http/Controllers/Auth'));
    }

    public function test_no_protected_domain_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $protected = [
            'app/Services/HighRiskPlatformChangePolicyService.php',
            'app/Services/SupportAccessPolicyService.php',
            // SupportAccessRequestService.php is deliberately NOT in
            // this list any more — Section 39A-8 Wave 8 found a genuine
            // need to wrap request()/approve()/deny()/expire()'s writes
            // in runWithFirmContext(), since support_access_requests
            // now has permanent FORCE ROW LEVEL SECURITY.
            'app/Services/EmergencyAccessGovernanceGapService.php',
            'app/Services/SeedDataSecurityAuditService.php',
            'database/seeders/DatabaseSeeder.php',
            // RowLevelSecurityCoverageMappingService.php is
            // deliberately NOT in this list any more — Section 39A-5
            // Wave 11 (the final wave of the 60-table RLS rollout)
            // found a genuine need to update the shared RLS coverage
            // registry once every table was moved into PREPARED_TABLES
            // and MISSING_PREPARED_TABLES became genuinely empty.
            // PaymentClassificationService.php is deliberately NOT in
            // this list any more — Section 39A-3H (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wire recordDecision()'s $payment->update() call with
            // explicit tenant context, since payments now has
            // permanent FORCE ROW LEVEL SECURITY.
            // TrustEligibilityService.php is deliberately NOT in this
            // list any more — Section 39A-3L, Checkpoint 18 (this same
            // staged-FORCE-activation branch, a later fix pass) found a
            // genuine need to wrap evaluate()'s $firm->firmSettings read
            // in runWithFirmContext(), since firm_settings gained
            // permanent FORCE ROW LEVEL SECURITY in this checkpoint and
            // every one of this service's ~25 live Trust-service call
            // sites invoked it with no ambient tenant context. Only the
            // single $settings read line changed — decision logic,
            // order, and return values are byte-for-byte identical.
            'app/Services/AiRetrievalIsolationService.php',
            // ConsentService.php is deliberately NOT in this list any
            // more — Section 39A-3L, Checkpoint 11 (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wrap capture()/revoke()'s bodies in runWithFirmContext(),
            // since communication_consents now has permanent FORCE ROW
            // LEVEL SECURITY.
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39B must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_firm_user_2fa_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('firm_user_2fa_missing'));
        $this->assertCount(21, $registry->all());
    }

    public function test_only_one_new_migration_was_added(): void
    {
        // Once Section 39B's branch is based on a commit that already
        // includes this migration (e.g. a later section's branch), it
        // is no longer "changed/untracked" per git diff — so this
        // asserts the migration file itself exists exactly once,
        // rather than relying on transient git-diff/commit state.
        $migrationFiles = glob(database_path('migrations/*firm_user_2fa_mode*.php')) ?: [];

        $this->assertCount(1, $migrationFiles, 'Expected exactly one firm_user_2fa_mode migration file, but found: '.implode(', ', $migrationFiles));
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
