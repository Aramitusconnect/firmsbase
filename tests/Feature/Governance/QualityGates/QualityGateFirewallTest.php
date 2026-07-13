<?php

namespace Tests\Feature\Governance\QualityGates;

use App\Enums\GovernanceMappingStatus;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TestCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QualityGateFirewallTest — proves Section 28 stayed within its
 * declared implementation boundary: no migrations, no new tables, no
 * UI/routes/controllers, no CI/workflow files, no unexpected
 * functional test file was modified, no real execution in any new
 * mapping service, and the RLS broken-scope test group is never
 * claimed Implemented while RLS enforcement is inactive.
 */
class QualityGateFirewallTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_SERVICE_FILES = [
        'TestCoverageMappingService.php',
        'ReleaseChecklistReadinessService.php',
        'DefinitionOfDoneReadinessService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send',
    ];

    /**
     * Test files this Section legitimately modified (allowed per the
     * approved scope: "tests that assert ComplianceGapRegistryService
     * exact count/content"). Any OTHER changed/untracked test file
     * outside tests/Feature/Governance/QualityGates would be a
     * violation of the "existing functional test files not modified"
     * rule.
     */
    private const ALLOWED_MODIFIED_TEST_FILES = [
        'tests/Feature/Governance/CrossCutting/ComplianceGapRegistryServiceTest.php',
        'tests/Feature/Governance/DataModelContract/DataModelContractGapRegistryTest.php',
        'tests/Feature/Governance/PermissionBoundaries/PermissionBoundaryGapRegistryTest.php',
        // Section 39A-3I (a later, distinct staged-FORCE-activation
        // branch) legitimately updated this test to wrap post-call
        // reads in explicit tenant context, once conflict_check_runs
        // gained permanent FORCE ROW LEVEL SECURITY.
        'tests/Feature/Conflicts/ConflictCheckServiceTest.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 28 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_new_tables_or_schema_files_were_added(): void
    {
        // No migration-created table can exist without a migration
        // file, and no migration file was added (see the preceding
        // test) — this additionally confirms no raw schema dump exists.
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_were_added(): void
    {
        $markers = [
            'TestCoverageMappingService', 'ReleaseChecklistReadinessService',
            'DefinitionOfDoneReadinessService',
        ];

        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $dir = base_path($relativeDir);

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                foreach ($markers as $marker) {
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "Section 28 must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_github_workflows_or_ci_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('.github');

        $this->assertEmpty(
            $changed,
            'Section 28 must add no CI/workflow files, but found changed/untracked .github files: '.implode(', ', $changed)
        );
    }

    public function test_no_unexpected_functional_test_file_was_modified(): void
    {
        // Every tests/Feature/Governance/* subdirectory (CrossCutting,
        // DataModelContract, PermissionBoundaries, QualityGates,
        // DeploymentEnvironment, and any future section's own
        // directory) is a governance-mapping test tree, expected to
        // keep growing as later sections add their own new test
        // files — only a change OUTSIDE that tree is a genuinely
        // unexpected functional-test modification.
        $changedTestFiles = array_filter(
            $this->changedOrUntrackedPaths('tests'),
            fn (string $path) => ! str_starts_with($path, 'tests/Feature/Governance/')
                && ! str_starts_with($path, 'tests/Feature/Security/')
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/')
                // Section 39A-2 legitimately added test helper methods
                // to tests/TestCase.php.
                && $path !== 'tests/TestCase.php'
                // Section 39A-3L Stage A legitimately added a PHPUnit
                // bootstrap guard outside the governance-mapping tree.
                && $path !== 'tests/bootstrap.php'
                && $path !== 'tests/bootstrap-verify-test-database.php'
                && $path !== 'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php'
                && $path !== 'tests/Feature/Ai/Entitlement/AiEntitlementAndModeBlockingTest.php'
                && $path !== 'tests/Feature/Ai/Foundation/AiModeEnumReplacementTest.php'
                && $path !== 'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php',
        );

        $unexpected = array_values(array_diff($changedTestFiles, self::ALLOWED_MODIFIED_TEST_FILES));

        $this->assertEmpty(
            $unexpected,
            'Section 28 must not modify existing functional test files beyond the allowed gap-registry count tests, but found: '.implode(', ', $unexpected)
        );
    }

    public function test_no_forbidden_execution_or_network_token_appears_in_any_new_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 28 service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    /**
     * Section 39A-3L Stage B (Checkpoints 22-34) activated permanent
     * FORCE ROW LEVEL SECURITY on all originally-prepared tables —
     * $enforcementActive below is therefore now genuinely TRUE, unlike
     * when this test was first written. That does NOT make this
     * control fully Implemented: firm_settings being forced was always
     * a proxy for "is ANY enforcement active," not the actual gating
     * condition — the real reason this control remains
     * PartiallyImplemented is that additional tenant-owned tables
     * discovered by inventory sweeps still have zero RLS preparation
     * at all (see the rls_prepared_not_enforced gap's own still-open
     * component, and RowLevelSecurityCoverageMappingService::
     * missingPreparedTables() for the live, non-hardcoded count). This
     * assertion is therefore unconditional now, rather than gated
     * behind $enforcementActive — gating it there made this test
     * silently perform zero assertions the instant enforcement
     * activated, exactly the failure mode this rewrite closes.
     */
    public function test_test_coverage_mapping_service_does_not_claim_rls_broken_scope_is_implemented_while_enforcement_is_inactive(): void
    {
        $row = DB::selectOne(
            'select relforcerowsecurity from pg_class where relname = ?',
            ['firm_settings']
        );
        $enforcementActive = (bool) $row->relforcerowsecurity;

        $this->assertTrue(
            $enforcementActive,
            'firm_settings must have permanent FORCE ROW LEVEL SECURITY active — Section 39A-3L Stage B is complete.'
        );

        $uncoveredCount = count((new RowLevelSecurityCoverageMappingService())->missingPreparedTables());
        $this->assertGreaterThan(0, $uncoveredCount, 'This test\'s own premise requires at least one currently-uncovered tenant-owned table to exist.');

        $item = (new TestCoverageMappingService())->byKey('tenant_isolation_broken_scope_caught_by_rls');

        $this->assertNotSame(
            GovernanceMappingStatus::Implemented,
            $item->status,
            "This control remains PartiallyImplemented — not because enforcement is inactive (it now is), but because {$uncoveredCount} uncovered tenant-owned tables still lack any RLS preparation at all."
        );

        $this->assertStringContainsString(
            (string) $uncoveredCount,
            $item->notes,
            'The generated notes must contain the current, dynamically-derived uncovered-table count, not a hard-coded literal.'
        );
        $this->assertStringNotContainsString(
            'enforcement is inactive',
            strtolower($item->notes),
            'The notes must not claim enforcement is inactive — it is now genuinely active for the covered tables.'
        );
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
            // Section 39A-3G (a later, distinct staged-FORCE-
            // activation branch) legitimately added an invoices-only
            // FORCE RLS migration, an InvoiceFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in InvoiceDraftingService, ImportApplyService,
            // ManualPaymentService, PaymentApplicationService,
            // TrustTransferRequestService,
            // AccountingExportLineBuilderService, and
            // FirmCommandCenterAggregationService, plus updated the
            // tests it affected.
            'database/migrations/2026_08_05_900001_force_rls_on_invoices_table.php',
            'database/factories/InvoiceFactory.php',
            'app/Services/InvoiceDraftingService.php',
            'app/Services/ManualPaymentService.php',
            'app/Services/PaymentApplicationService.php',
            'app/Services/TrustTransferRequestService.php',
            'app/Services/AccountingExportLineBuilderService.php',
            'tests/Feature/Invoicing/InvoiceDraftingServiceTest.php',
            'tests/Feature/Payments/PaymentApplicationServiceTest.php',
            'tests/Feature/Trust/Transfers/TrustTransferRequestServiceTest.php',
            // Section 39A-3H (a later, distinct staged-FORCE-
            // activation branch) legitimately added a payments-only
            // FORCE RLS migration, a PaymentFactory root-cause
            // firm/client consistency fix, explicit tenant-context
            // wiring in ManualPaymentService, PaymentClassificationService,
            // TrustTransferRequestService,
            // AccountingExportLineBuilderService, and
            // FirmCommandCenterAggregationService, plus updated the
            // tests it affected.
            'database/migrations/2026_08_06_900001_force_rls_on_payments_table.php',
            'database/factories/PaymentFactory.php',
            'app/Services/PaymentClassificationService.php',
            'tests/Feature/Payments/ManualPaymentServiceTest.php',
            'tests/Feature/Webhooks/Wiring/PaymentRecordedWiringTest.php',
            // Internal login/panel access wiring (a later, distinct
            // section) legitimately added a migration extending
            // firm_users' RLS policy with a narrow self-lookup
            // clause needed to bootstrap-resolve an authenticated
            // user's own firm from firm_users itself, real
            // platform_admin/web guard + Filament panel wiring, and
            // its own test files.
            'database/migrations/2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php',
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately added a
                // conflict_check_runs-only FORCE RLS migration.
                'database/migrations/2026_08_11_900001_force_rls_on_conflict_check_runs_table.php',
                // Section 39A-3J (a later, distinct staged-FORCE-
                // activation branch) legitimately added FORCE RLS
                // migrations for lead_sources, consultation_outcomes,
                // firm_leads, and consultations together, their
                // factory context-hold fixes, and updated the tests
                // it affected.
                'database/migrations/2026_08_12_900001_force_rls_on_lead_sources_table.php',
                'database/migrations/2026_08_13_900001_force_rls_on_consultation_outcomes_table.php',
                'database/migrations/2026_08_14_900001_force_rls_on_firm_leads_table.php',
                'database/migrations/2026_08_15_900001_force_rls_on_consultations_table.php',
                'database/factories/LeadSourceFactory.php',
                'database/factories/ConsultationOutcomeFactory.php',
                'database/factories/FirmLeadFactory.php',
                'database/factories/ConsultationFactory.php',
                'tests/Feature/Leads/LeadConversionServiceTest.php',
                'tests/Feature/Webhooks/Wiring/LeadCreatedWiringTest.php',
                // Section 39A-3K (this batch, a later, distinct
                // staged-FORCE-activation branch) legitimately added
                // FORCE RLS migrations for firm_practice_areas,
                // document_chase_rules, employee_rates, calendar_events,
                // and client_communication_preferences together, their
                // factory context-hold fixes, and updated the tests it
                // affected.
                'database/migrations/2026_08_20_920001_force_rls_on_firm_practice_areas_table.php',
                'database/migrations/2026_08_20_920002_force_rls_on_document_chase_rules_table.php',
                'database/migrations/2026_08_20_920003_force_rls_on_employee_rates_table.php',
                'database/migrations/2026_08_20_920004_force_rls_on_calendar_events_table.php',
                'database/migrations/2026_08_20_920005_force_rls_on_client_communication_preferences_table.php',
                'database/factories/CalendarEventFactory.php',
                'database/factories/ClientCommunicationPreferenceFactory.php',
                'database/factories/DocumentChaseRuleFactory.php',
                'database/factories/EmployeeRateFactory.php',
                'database/factories/FirmPracticeAreaFactory.php',
                'tests/Feature/Deadlines/CalendarEventServiceTest.php',
                'tests/Feature/Deadlines/DeadlineServiceTest.php',
                'tests/Feature/DocumentChase/DocumentChaseSchedulerServiceTest.php',
                'tests/Feature/Rates/EmployeeRateServiceTest.php',
            'config/auth.php',
            'app/Models/User.php',
            'app/Models/PlatformAdmin.php',
            'app/Http/Middleware/EstablishFirmTenantContext.php',
            'app/Providers/Filament/AdminPanelProvider.php',
            'app/Providers/Filament/FirmPanelProvider.php',
            'app/Providers/AppServiceProvider.php',
            'bootstrap/providers.php',
            'tests/Feature/Security/Login/PlatformAdminLoginPanelAccessTest.php',
            'tests/Feature/Security/Login/FirmUserLoginPanelAccessTest.php',
            'tests/Feature/Security/Login/CrossPanelAuthGuardTest.php',
            'tests/Feature/Security/Login/TenantContextMiddlewareTest.php',
            'tests/Feature/Security/FirmUser2fa/FirmUser2faLoginEnforcementTest.php',
            'tests/Feature/Security/LoginPolicy/LoginPolicyEnforcementTest.php',
            'app/Services/ReadinessScorecardRegistry.php',
            'tests/Feature/Tasks/TaskDependencyServiceTest.php',
            'tests/Feature/Webhooks/Wiring/TaskCompletedWiringTest.php',
            // Section 39A-3L, Checkpoint 10, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a document_requests-only
            // FORCE RLS migration, a DocumentRequestFactory
            // firm/client consistency + context-hold fix, wrapped
            // DocumentRequestService's create() and its 7
            // single-item mutators and DocumentChaseService's
            // checkAndLog()/escalate()/pause()/resume() each in
            // their own runWithFirmContext() call, and updated the
            // tests it affected.
            'database/migrations/2026_08_25_930010_force_rls_on_document_requests_table.php',
            'database/factories/DocumentRequestFactory.php',
            'app/Services/DocumentRequestService.php',
            'app/Services/DocumentChaseService.php',
            'app/Services/MobilePortalReadinessService.php',
            'tests/Feature/Documents/DocumentRequestServiceTest.php',
            'tests/Feature/DocumentChase/DocumentChaseServiceTest.php',
            'tests/Feature/Readiness/MatterReadinessServiceTest.php',
            'tests/Feature/Governance/MarketReadyValueMultipliers/FirmCommandCenterAggregationServiceTest.php',
            // Section 39A-3L, Checkpoint 11, Table Phase C (this batch,
            // a later, distinct staged-FORCE-activation branch)
            // legitimately added a communication_consents-only FORCE
            // RLS migration, wrapped ConsentService's capture()/
            // revoke() in their own runWithFirmContext() call, moved
            // ClientPortalService::invite()'s isGranted() precondition
            // inside its existing runWithFirmContext() wrap, added a
            // CommunicationConsentFactory context-hold fix, and updated
            // the tests it affected.
            'database/migrations/2026_08_25_930011_force_rls_on_communication_consents_table.php',
            'database/factories/CommunicationConsentFactory.php',
            'app/Services/ConsentService.php',
            'tests/Feature/Activation/ConsentServiceTest.php',
            'tests/Feature/PaymentPlans/PaymentPlanDunningServiceTest.php',
            // Section 39A-3L, Checkpoint 22, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a payment_plans-only FORCE
            // RLS migration, wrapped PaymentPlanService's create()/
            // edit()/activate()/renegotiate()/cancel()/
            // markDefaulted() each in their own runWithFirmContext()
            // call, added a PaymentPlanFactory context-hold +
            // firm/client consistency fix, and updated the one
            // existing test that genuinely needed explicit tenant
            // context after this activation.
            'database/migrations/2026_08_25_930022_force_rls_on_payment_plans_table.php',
            'database/factories/PaymentPlanFactory.php',
            'app/Services/PaymentPlanService.php',
            'tests/Feature/PaymentPlans/PaymentPlanServiceTest.php',
            // Section 39A-3L, Checkpoint 23, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added a payment_plan_events-only
            // FORCE RLS migration and a PaymentPlanEventFactory
            // context-hold + firm/plan consistency fix — no
            // production service file required any wiring change
            // this checkpoint. The same PaymentPlanServiceTest.php
            // (already allowed above) was updated again to wrap two
            // assertDatabaseHas() calls in tenant context.
            'database/migrations/2026_08_25_930023_force_rls_on_payment_plan_events_table.php',
            'database/factories/PaymentPlanEventFactory.php',
            // Section 39A-3L, Checkpoint 24 (this batch, a later,
            // distinct staged-FORCE-activation branch) legitimately
            // added a notification_events-only FORCE RLS migration,
            // wrapped NotificationDispatchService::dispatch()'s
            // entire body in one runWithFirmContext() call (its
            // recordSent()/recordFailed() methods each keep their own
            // independent tight wrap), and wrapped SuppressionService's
            // recordBounce()/recordComplaint() methods each in their
            // own runWithFirmContext() call, and
            // added a NotificationEventFactory context-hold fix — the
            // entire write pathway remains dormant in production today
            // (no live caller of dispatch()/recordFailed()/
            // recordBounce()/recordComplaint() exists yet). Also
            // updated tests/Feature/Notifications/
            // NotificationDispatchServiceTest.php and
            // tests/Feature/Notifications/SuppressionServiceTest.php
            // to wrap reads that legitimately need explicit tenant
            // context after this activation.
            'database/migrations/2026_08_25_930024_force_rls_on_notification_events_table.php',
            'database/factories/NotificationEventFactory.php',
            'app/Services/NotificationDispatchService.php',
            'app/Services/SuppressionService.php',
            'tests/Feature/Notifications/NotificationDispatchServiceTest.php',
            'tests/Feature/Notifications/SuppressionServiceTest.php',
            // Section 39A-3L Phase B5 (this batch, a later, distinct
            // contacts/parties FORCE-RLS-prerequisite branch — contacts
            // and parties are NOT yet FORCE-enabled by this batch, only
            // prepared for it) legitimately added ContactFactory/
            // PartyFactory context-hold fixes (app/Services/
            // ConflictCheckService.php, ImportApplyService.php, and
            // ImportDuplicateDetectionService.php were already allowed
            // above from Section 39A-3A and needed no new entry here),
            // and extended tests/Feature/Imports/
            // ImportDuplicateDetectionServiceTest.php with Contact/
            // Party duplicate-detection coverage that did not exist
            // before this batch.
            'database/factories/ContactFactory.php',
            'database/factories/PartyFactory.php',
            'tests/Feature/Imports/ImportDuplicateDetectionServiceTest.php',
        ];

        return array_values(array_filter(
            $paths,
            fn (string $path) => ! in_array($path, $section39bAllowed, true),
        ));
    }

    /**
     * Strips PHP comments (// # and block/doc comments) via the real
     * tokenizer so forbidden-token checks only ever see executable
     * code — a token merely mentioned in prose must never fail a
     * firewall test.
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
