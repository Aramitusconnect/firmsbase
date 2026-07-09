<?php

namespace Tests\Feature\Security\SeedData;

use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * SeedDataAuditFirewallTest — Section 39E. Proves the fix stayed
 * inside its declared boundary: no migrations/schema/new tables, no
 * UI/routes/controllers/Filament/Blade/Livewire, no domain behavior
 * modified outside the seeder itself, no demo/pilot/production account
 * creation, and ComplianceGapRegistryService was not deleted/rewritten
 * to hide the historical gap.
 *
 * The new SeedDataSecurityAuditService's job is to scan OTHER files for
 * write/credential patterns — it legitimately contains literal strings
 * such as "User::factory()", "'{$marker}' =>", and "STRIPE_SECRET"
 * (part of its checklist vocabulary constant) as detection data, not
 * as executable code. It never performs a write, network, or process
 * call itself. Its own forbidden-token check below deliberately omits
 * "STRIPE_" (present only as vocabulary data, not a real Stripe call)
 * and the generic Eloquent write tokens (::create(/->save(/->update(/
 * ->delete() that every prior section's firewall test used, because
 * this service's legitimate job is to reference such patterns as
 * detection strings, not to execute them — real writes
 * (DB::insert/update/delete, Schema::*) and all network/process/shell
 * tokens remain forbidden.
 */
class SeedDataAuditFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'SeedDataSecurityAuditService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::table(', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send', 'file_put_contents(', 'fopen(', 'unlink(',
    ];

    private const PROTECTED_FILES = [
        'app/Services/ComplianceGapRegistryService.php',
        'app/ValueObjects/GapRegisterItem.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/SupportAccessPolicyService.php',
        'app/Services/SupportAccessRequestService.php',
        'app/Services/EmergencyAccessGovernanceGapService.php',
        'app/Services/HighRiskPlatformChangePolicyService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/TrustEligibilityService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/ConsentService.php',
        'app/Services/PlatformStaffAccessPolicyService.php',
        'app/Models/User.php',
        'app/Models/Firm.php',
        'config/auth.php',
        'config/session.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'Section 39E must add no migrations, but found: '.implode(', ', $changed));
    }

    public function test_no_new_tables_were_created(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 39E must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_protected_domain_behavior_files_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changed));

        $this->assertEmpty($touched, 'Section 39E must not modify protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_only_the_expected_files_were_modified_or_created(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $allowedExactFiles = [
            'database/seeders/DatabaseSeeder.php',
            // Ten prior sections' firewall tests hardcoded
            // "database/seeders must never change" (correct for
            // purely-declarative mapping sections, but this section's
            // entire purpose is to fix DatabaseSeeder.php) — narrowly
            // widened to allow exactly this file, matching the
            // precedent already established for Section 39C's
            // equivalent SupportAccessPolicyService/SupportAccessRequestService
            // fixes.
            'tests/Feature/Governance/AcceptanceTestMatrix/AcceptanceTestMatrixFirewallTest.php',
            'tests/Feature/Governance/AdminControlCatalog/AdminControlFirewallTest.php',
            'tests/Feature/Governance/AdminControlCatalog/AdminControlUiBoundaryTest.php',
            'tests/Feature/Governance/EdgeCaseRiskHandling/EdgeCaseRiskFirewallTest.php',
            'tests/Feature/Governance/EntityFieldCatalog/EntityFieldCatalogFirewallTest.php',
            'tests/Feature/Governance/FinalExecutiveRecommendation/FinalExecutiveRecommendationFirewallTest.php',
            'tests/Feature/Governance/MarketReadyValueMultipliers/MarketReadyFirewallTest.php',
            'tests/Feature/Governance/PrePilotRemediationBacklog/PrePilotRemediationFirewallTest.php',
            'tests/Feature/Governance/ProfessionalReviewGate/ProfessionalReviewFirewallTest.php',
            'tests/Feature/Governance/WorkflowStateMachines/WorkflowStateMachineFirewallTest.php',
            'tests/Feature/Security/SupportAccess/EmergencySupportApprovalFirewallTest.php',
            // Section 39B (a later, distinct backend-policy branch)
            // legitimately added its own migration/model/service and
            // needed to narrowly widen these same brittle firewall
            // tests' scope conditions again, for the same reason.
            'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php',
            'app/Models/FirmSettings.php',
            'tests/Feature/Governance/CrossCutting/CrossCuttingFirewallTest.php',
            'tests/Feature/Governance/DataModelContract/DataModelContractFirewallTest.php',
            'tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php',
            'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryFirewallTest.php',
            'tests/Feature/Governance/QualityGates/QualityGateFirewallTest.php',
            // Section 39D (a later, distinct backend-policy branch)
            // legitimately added its own new app/Services file and
            // fixed a stale git-diff assumption in Section 39B's own
            // firewall test.
            'tests/Feature/Security/FirmUser2fa/FirmUser2faFirewallTest.php',
        ];

        $allowedPrefixes = [
            'app/Services/SeedDataSecurityAuditService.php',
            'tests/Feature/Security/SeedData/',
            'app/Services/FirmUser2faPolicyService.php',
            'tests/Feature/Security/FirmUser2fa/',
            'app/Services/LoginPolicyService.php',
            'tests/Feature/Security/LoginPolicy/',
            // Section 39A (a later, distinct RLS-activation branch)
            // legitimately added its own new app/Services file, a
            // route-independent middleware file, a queue-job
            // tenant-context trait, and its own test directory.
            'app/Services/TenantContextService.php',
            'app/Http/Middleware/',
            'app/Support/',
            'tests/Feature/Security/RlsEnforcement/',
            // Section 39A-2 (a later, distinct RLS-context-rollout
            // branch) legitimately added its own test directory and
            // test helper methods to tests/TestCase.php.
            'tests/Feature/Security/RlsContextRollout/',
            'tests/TestCase.php',
            // Section 39A-3A (a later, distinct staged-FORCE-activation
            // branch) legitimately added a clients-only FORCE RLS
            // migration, a ClientFactory context fix, explicit
            // tenant-context wiring in several real services, and its
            // own test directory.
            'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php',
            'database/factories/ClientFactory.php',
            'app/Services/ClientPortalService.php',
            'app/Services/ConflictCheckService.php',
            'app/Services/FirmCommandCenterAggregationService.php',
            'app/Services/ImportApplyService.php',
            'app/Services/ImportDuplicateDetectionService.php',
            'app/Services/ImportRollbackService.php',
            'app/Services/LeadConversionService.php',
            'tests/Feature/Imports/ImportApplyServiceTest.php',
            'tests/Feature/Imports/ImportRollbackServiceTest.php',
            'tests/Feature/Webhooks/Wiring/ClientCreatedWiringTest.php',
            'tests/Feature/Security/RlsForceActivation/',
            // Section 39A-3B (a later, distinct staged-FORCE-activation
            // branch) legitimately added a firm_users-only FORCE RLS
            // migration, a FirmUserFactory context fix, explicit
            // tenant-context wiring in real services that read
            // firm_users directly, updated the legitimately cross-firm
            // relationship tests it affected, and its own test
            // directory.
            'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php',
            'database/factories/FirmUserFactory.php',
            'app/Services/MatterAccessPolicyService.php',
            'app/Services/AccessReviewService.php',
            'tests/Feature/Identity/FirmUserTest.php',
            'tests/Feature/Identity/UserFirmRelationshipsTest.php',
            'tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php',
            'tests/Feature/Security/RlsForceRollout/',
            // Section 39A-3C/39A-3D/39A-3E/39A-3F (later, distinct
            // staged-FORCE-activation branches) legitimately added
            // documents-only, deadlines-only, tasks-only, and
            // matters-only FORCE RLS migrations, factory context
            // fixes, explicit tenant-context wiring in real services,
            // and updated the tests each affected.
            'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php',
            'database/factories/DocumentFactory.php',
            'app/Services/DocumentReplacementService.php',
            'app/Services/DocumentSecurityService.php',
            'app/Services/EmailAttachmentPromotionService.php',
            'app/Services/SignatureCertificateService.php',
            'tests/Feature/Documents/DocumentReplacementServiceTest.php',
            'tests/Feature/Webhooks/Wiring/DocumentUploadedWiringTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateOnePerRequestTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateRequiresHashAndEventTrailTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php',
            'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php',
            'database/factories/DeadlineFactory.php',
            'app/Services/DeadlineService.php',
            'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php',
            'database/factories/TaskFactory.php',
            'app/Services/TaskService.php',
            'app/Services/TaskDependencyService.php',
            'app/Services/ReadinessScorecardRegistry.php',
            'tests/Feature/Tasks/TaskDependencyServiceTest.php',
            'tests/Feature/Webhooks/Wiring/TaskCompletedWiringTest.php',
            'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php',
            'database/factories/MatterFactory.php',
            'app/Services/MatterOpeningService.php',
            'app/Services/MatterReadinessService.php',
            'app/Services/ProductionPilotWorkflowService.php',
            'app/Services/WebhookEventRecorderService.php',
            'tests/Feature/Matters/MatterOpeningServiceTest.php',
            'tests/Feature/MobilePortal/MobilePortalReadinessServiceTest.php',
            'tests/Feature/PilotWorkflow/ProductionPilotWorkflowServiceTest.php',
            'tests/Feature/Webhooks/Wiring/MatterCreatedWiringTest.php',
            'tests/Feature/Webhooks/Wiring/MatterReadinessChangedWiringTest.php',
        ];

        $unexpected = array_values(array_filter(
            $changed,
            function (string $path) use ($allowedExactFiles, $allowedPrefixes) {
                if (in_array($path, $allowedExactFiles, true)) {
                    return false;
                }

                foreach ($allowedPrefixes as $prefix) {
                    if ($path === $prefix || str_starts_with($path, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertEmpty($unexpected, 'Section 39E must only modify/create the expected files, but found: '.implode(', ', $unexpected));
    }

    public function test_new_service_contains_no_forbidden_network_process_or_schema_write_tokens(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 39E service file missing: {$filename}");

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

    public function test_gap_registry_still_tracks_the_seed_data_gap_and_count_remains_twenty_one(): void
    {
        $registry = new ComplianceGapRegistryService();

        $this->assertTrue($registry->isTracked('seed_data_defaults_and_test_secrets_not_audited'));
        $this->assertCount(21, $registry->all());
    }

    public function test_no_demo_pilot_or_production_account_creation_service_was_introduced(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/DemoDataSeederService.php'));
        $this->assertFileDoesNotExist(app_path('Services/PilotAccountService.php'));
        $this->assertFileDoesNotExist(app_path('Services/ProductionSeedAccountService.php'));

        $newSeeders = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/seeders'),
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php',
        ));

        $this->assertEmpty($newSeeders, 'Section 39E must not create new seeders, but found: '.implode(', ', $newSeeders));
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

        // Section 39B (a later, distinct backend-policy branch)
        // legitimately added exactly one migration and modified
        // FirmSettings.php — excluded here (by exact path, regardless
        // of scope) so this section's own declarative-only guarantee
        // still holds without touching every individual check.
        $section39bAllowed = [
            'database/migrations/2026_07_29_900001_add_firm_user_2fa_mode_to_firm_settings_table.php',
            'app/Models/FirmSettings.php',
            // Section 39A-3A (a later, distinct staged-FORCE-
            // activation branch) legitimately added a clients-only
            // FORCE RLS migration, a ClientFactory context fix, and
            // explicit tenant-context wiring in several real services
            // that write/read clients directly.
            'database/migrations/2026_07_30_900001_force_rls_on_clients_table.php',
            'database/factories/ClientFactory.php',
            'app/Services/ClientPortalService.php',
            'app/Services/ConflictCheckService.php',
            'app/Services/FirmCommandCenterAggregationService.php',
            'app/Services/ImportApplyService.php',
            'app/Services/ImportDuplicateDetectionService.php',
            'app/Services/ImportRollbackService.php',
            'app/Services/LeadConversionService.php',
            'tests/Feature/Imports/ImportApplyServiceTest.php',
            'tests/Feature/Imports/ImportRollbackServiceTest.php',
            'tests/Feature/Webhooks/Wiring/ClientCreatedWiringTest.php',
            // Section 39A-3B (a later, distinct staged-FORCE-
            // activation branch) legitimately added a firm_users-only
            // FORCE RLS migration, a FirmUserFactory context fix, and
            // explicit tenant-context wiring in real services that
            // read firm_users directly, plus updated the legitimately
            // cross-firm relationship tests it affected.
            'database/migrations/2026_07_31_900001_force_rls_on_firm_users_table.php',
            'database/factories/FirmUserFactory.php',
            'app/Services/LoginPolicyService.php',
            'app/Services/MatterAccessPolicyService.php',
            'app/Services/AccessReviewService.php',
            'tests/Feature/Identity/FirmUserTest.php',
            'tests/Feature/Identity/UserFirmRelationshipsTest.php',
            'tests/Feature/Tenancy/RowLevelSecurityPreparationTest.php',
            // Section 39A-3C (a later, distinct staged-FORCE-
            // activation branch) legitimately added a documents-only
            // FORCE RLS migration, a DocumentFactory context fix, and
            // explicit tenant-context wiring in several real services
            // that write/read documents directly, plus updated the
            // tests it affected.
            'database/migrations/2026_08_01_900001_force_rls_on_documents_table.php',
            'database/factories/DocumentFactory.php',
            'app/Services/DocumentReplacementService.php',
            'app/Services/DocumentSecurityService.php',
            'app/Services/EmailAttachmentPromotionService.php',
            'app/Services/SignatureCertificateService.php',
            'tests/Feature/Documents/DocumentReplacementServiceTest.php',
            'tests/Feature/Webhooks/Wiring/DocumentUploadedWiringTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateOnePerRequestTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateRequiresHashAndEventTrailTest.php',
            'tests/Feature/Signature/Certificates/SignatureCertificateServiceTest.php',
            // Section 39A-3D (a later, distinct staged-FORCE-
            // activation branch) legitimately added a deadlines-only
            // FORCE RLS migration, a DeadlineFactory context fix, and
            // explicit tenant-context wiring in DeadlineService.
            'database/migrations/2026_08_02_900001_force_rls_on_deadlines_table.php',
            'database/factories/DeadlineFactory.php',
            'app/Services/DeadlineService.php',
            // Section 39A-3E (a later, distinct staged-FORCE-
            // activation branch) legitimately added a tasks-only
            // FORCE RLS migration, a TaskFactory context fix, and
            // explicit tenant-context wiring in TaskService,
            // TaskDependencyService, and MatterReadinessService.
            'database/migrations/2026_08_03_900001_force_rls_on_tasks_table.php',
            'database/factories/TaskFactory.php',
            'app/Services/TaskService.php',
            'app/Services/TaskDependencyService.php',
            // Section 39A-3F (a later, distinct staged-FORCE-
            // activation branch) legitimately added a matters-only
            // FORCE RLS migration, a MatterFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in MatterOpeningService, MatterReadinessService,
            // ProductionPilotWorkflowService, and
            // WebhookEventRecorderService, plus updated the tests it
            // affected.
            'database/migrations/2026_08_04_900001_force_rls_on_matters_table.php',
            'database/factories/MatterFactory.php',
            'app/Services/MatterOpeningService.php',
            'app/Services/MatterReadinessService.php',
            'app/Services/ProductionPilotWorkflowService.php',
            'app/Services/WebhookEventRecorderService.php',
            'tests/Feature/Matters/MatterOpeningServiceTest.php',
            'tests/Feature/MobilePortal/MobilePortalReadinessServiceTest.php',
            'tests/Feature/PilotWorkflow/ProductionPilotWorkflowServiceTest.php',
            'tests/Feature/Webhooks/Wiring/MatterCreatedWiringTest.php',
            'tests/Feature/Webhooks/Wiring/MatterReadinessChangedWiringTest.php',
            'app/Services/ReadinessScorecardRegistry.php',
            'tests/Feature/Tasks/TaskDependencyServiceTest.php',
            'tests/Feature/Webhooks/Wiring/TaskCompletedWiringTest.php',
        ];

        return array_values(array_filter(
            $paths,
            fn (string $path) => ! in_array($path, $section39bAllowed, true),
        ));
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
