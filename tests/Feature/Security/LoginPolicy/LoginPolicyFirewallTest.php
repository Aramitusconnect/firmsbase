<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * LoginPolicyFirewallTest — Section 39D. Proves the fix stayed inside
 * its declared boundary: no routes/controllers/auth scaffolding were
 * introduced, no Fortify/Breeze was installed, no migrations/schema
 * changes were made, and ComplianceGapRegistryService was not deleted/
 * rewritten to hide the historical login_policy_wrappers_missing gap.
 */
class LoginPolicyFirewallTest extends TestCase
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

    private const NEW_SERVICE_FILES = [
        'LoginPolicyService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::update', 'DB::delete', 'DB::table(',
        'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send', 'file_put_contents(', 'fopen(', 'unlink(',
        '::create(', '::update(', '::delete(', '->save(',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        // Section 39A-3A/39A-3B (later, distinct staged-FORCE-
        // activation branches) legitimately added clients-only and
        // firm_users-only FORCE RLS migrations.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/migrations'),
            fn (string $path) => $path !== 'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php'
                && $path !== 'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php'
                && $path !== 'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php'
                && $path !== 'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php'
                && $path !== 'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php'
                && $path !== 'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php'
                && $path !== 'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php'
                && $path !== 'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added a migration extending
                // firm_users' RLS policy with a narrow self-lookup
                // clause needed to bootstrap-resolve an authenticated
                // user's own firm from firm_users itself.
                && $path !== 'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // conflict_check_runs-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php'
                // Section 39A-3J (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for lead_sources,
                // consultation_outcomes, firm_leads, and consultations
                // together.
                && $path !== 'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php'
                && $path !== 'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php'
                && $path !== 'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php'
                && $path !== 'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php'
                // Section 39A-3K (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together.
                && $path !== 'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php'
                && $path !== 'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php'
                && $path !== 'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php'
                && $path !== 'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php'
                && $path !== 'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php'
                // Section 39A-3L, Checkpoint 10, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a document_requests-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php'
                // Section 39A-3L, Checkpoint 11, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a communication_consents-
                // only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php'
                // Section 39A-3L, Checkpoint 22, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plans-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php'
                // Section 39A-3L, Checkpoint 23, Table Phase C (this
                // batch, a later, distinct staged-FORCE-activation
                // branch) legitimately added a payment_plan_events-only
                // FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php'
                // Section 39A-3L, Checkpoint 24 (this batch, a later,
                // distinct staged-FORCE-activation branch) legitimately
                // added a notification_events-only FORCE RLS migration.
                && $path !== 'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php'
                // Section 39A-8 Wave 8 (the eighth coordinated
                // multi-table wave, governance/support/platform domain)
                // legitimately added six combined prepare-and-force
                // migrations together: legal_holds, deletion_requests,
                // key_destruction_requests, support_access_requests,
                // support_access_sessions, deployment_health_checks.
                && $path !== 'database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php'
                && $path !== 'database/migrations/2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php'
                && $path !== 'database/migrations/2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php'
                && $path !== 'database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php'
                // Section 39A-9 Wave 9 (migration/export domain)
                // legitimately added six combined prepare-and-force
                // migrations together: export_jobs, migration_projects,
                // import_batches, implementation_projects,
                // fleet_migration_instance_status, offboarding_requests.
                && $path !== 'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php'
                && $path !== 'database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php'
                && $path !== 'database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php'
                && $path !== 'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php'
                && $path !== 'database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php',
        ));

        $this->assertEmpty($changed, 'Section 39D must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_models_were_modified(): void
    {
        // Internal login/panel access wiring (a later, distinct
        // section) legitimately added FilamentUser::canAccessPanel()
        // to both User.php and PlatformAdmin.php — the real Filament
        // panel access gate, deliberately routed through
        // LoginPolicyService::canAttemptFirmLogin() itself.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('app/Models'),
            fn (string $path) => $path !== 'app/Models/User.php'
                && $path !== 'app/Models/PlatformAdmin.php',
        ));

        $this->assertEmpty($changed, 'Section 39D must not modify any model, but found changes to: '.implode(', ', $changed));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39D must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        // Section 39A (a later, distinct RLS-activation branch)
        // legitimately added one route-independent middleware file
        // (App\Http\Middleware\ApplyTenantDatabaseContext, not wired to
        // any route or bootstrap/app.php) — narrowly excluded here so
        // Section 39D's own "no middleware" guarantee still holds for
        // everything else.
        $middlewareChanges = array_values(array_filter(
            $this->changedOrUntrackedPaths('app/Http/Middleware'),
            fn (string $path) => $path !== 'app/Http/Middleware/ApplyTenantDatabaseContext.php'
                // Internal login/panel access wiring (a later, distinct
                // section) legitimately added EstablishFirmTenantContext,
                // the resolution point ApplyTenantDatabaseContext's own
                // docblock always deferred to.
                && $path !== 'app/Http/Middleware/EstablishFirmTenantContext.php',
        ));
        $this->assertEmpty($middlewareChanges, 'Section 39D must introduce no middleware surface, but found changes under app/Http/Middleware: '.implode(', ', $middlewareChanges));
    }

    public function test_no_fortify_or_breeze_was_installed(): void
    {
        $composerSource = file_get_contents(base_path('composer.json'));

        $this->assertStringNotContainsStringIgnoringCase('fortify', $composerSource);
        $this->assertStringNotContainsStringIgnoringCase('breeze', $composerSource);

        $this->assertEmpty($this->changedOrUntrackedPaths('composer.json'));
        $this->assertEmpty($this->changedOrUntrackedPaths('bootstrap/app.php'));

        // bootstrap/providers.php is deliberately NOT asserted
        // byte-identical any more — internal login/panel access wiring
        // (a later, distinct section) legitimately registered a new
        // FirmPanelProvider there. This test's real concern (no
        // Fortify/Breeze provider was silently installed) is checked
        // directly against its content instead.
        $providersSource = file_get_contents(base_path('bootstrap/providers.php'));
        $this->assertStringNotContainsStringIgnoringCase('fortify', $providersSource);
        $this->assertStringNotContainsStringIgnoringCase('breeze', $providersSource);
    }

    public function test_no_login_route_or_auth_controller_was_introduced(): void
    {
        $webRoutesSource = file_get_contents(base_path('routes/web.php'));

        $this->assertStringNotContainsStringIgnoringCase('login', $webRoutesSource);
        $this->assertFileDoesNotExist(base_path('routes/api.php'));
        $this->assertDirectoryDoesNotExist(app_path('Http/Controllers/Auth'));

        // ReadinessController.php (ECS readiness foundation) is a reviewed,
        // narrow exception: a pure infra health-check endpoint with no
        // login/session/auth logic of any kind — orthogonal to this
        // test's actual concern (no auth controller was introduced).
        $controllerFiles = glob(app_path('Http/Controllers/*.php')) ?: [];
        $this->assertSame(['Controller.php', 'ReadinessController.php'], array_map('basename', $controllerFiles));
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
            // FirmUser2faPolicyService.php is deliberately NOT in this
            // list any more — Section 39A-3L, Checkpoint 18 (a later,
            // distinct staged-FORCE-activation branch) found a genuine
            // need to correct a stale docblock claim ("no login route/
            // UI surface yet") once User::canAccessPanel() became a
            // live consumer of this service, wrapped in tenant context
            // because firm_settings gained permanent FORCE ROW LEVEL
            // SECURITY in that checkpoint. Only the docblock changed —
            // no method logic in this file was touched.
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
            // list any more — Section 39A-3L, Checkpoint 18 (a later,
            // distinct staged-FORCE-activation branch) found a genuine
            // need to wrap evaluate()'s $firm->firmSettings read in
            // runWithFirmContext(), since firm_settings now has
            // permanent FORCE ROW LEVEL SECURITY. Only the single
            // $settings read line changed — decision logic, order, and
            // return values are byte-for-byte identical.
            'app/Services/AiRetrievalIsolationService.php',
            // ConsentService.php is deliberately NOT in this list any
            // more — Section 39A-3L, Checkpoint 11 (a later, distinct
            // staged-FORCE-activation branch) found a genuine need to
            // wrap capture()/revoke()'s bodies in runWithFirmContext(),
            // since communication_consents now has permanent FORCE ROW
            // LEVEL SECURITY.
            // User.php is deliberately NOT in this list any more —
            // internal login/panel access wiring (a later, distinct
            // section) found a genuine need to add
            // FilamentUser::canAccessPanel() to it, the real Filament
            // panel access gate.
            'app/Models/FirmUser.php',
            'app/Models/FirmSettings.php',
        ];

        $touched = array_values(array_intersect($protected, $changed));

        $this->assertEmpty($touched, 'Section 39D must not modify unrelated protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_new_service_contains_no_forbidden_tokens_or_writes(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 39D service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_compliance_gap_registry_service_was_not_deleted_or_rewritten(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must remain untouched — no resolved-state lifecycle exists to safely mark the gap resolved.');
    }

    public function test_gap_registry_still_tracks_the_login_policy_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService;

        $this->assertTrue($registry->isTracked('login_policy_wrappers_missing'));
        $this->assertCount(21, $registry->all());
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

        $paths = preg_split('/\R/', $changed) ?: [];

        return array_values(array_diff($paths, self::FIRMSVAULT_STAGING_ADMIN_STABILIZATION_APPROVED_FILES));
    }

    /**
     * Strips PHP comments so forbidden-token checks only ever see
     * executable code — a token merely mentioned in prose must never
     * fail a firewall test.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
