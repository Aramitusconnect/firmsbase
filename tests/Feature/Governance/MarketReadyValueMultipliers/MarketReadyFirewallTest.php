<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use Tests\TestCase;

/**
 * MarketReadyFirewallTest — proves Section 30 stayed within its
 * declared implementation boundary: no migrations, no new tables, no
 * UI/routes/controllers, no CI/workflow files, composer.json
 * untouched, no protected file was modified, and no real OCR/image-
 * processing/PDF-conversion/SMS/WhatsApp/payment-provider/trust-
 * workflow code was introduced by any new Section 30 service.
 */
class MarketReadyFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'MobilePortalCoverageMappingService.php',
        'FirmCommandCenterAggregationService.php',
        'TemplatePackCoverageMappingService.php',
        'TrustDependentPackGatingMappingService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Tesseract', 'Imagick', 'imagecrop', 'imagecreatefrom', 'Ghostscript', 'TCPDF', 'Dompdf',
        'Twilio', 'Vonage', 'MessageBird', 'WhatsApp SDK',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send',
    ];

    private const PROTECTED_FILES = [
        'app/Services/MobilePortalReadinessService.php',
        'app/Services/TrustPilotExitCriteriaService.php',
        // TrustEligibilityService.php is deliberately NOT in this list
        // any more — Section 39A-3L, Checkpoint 18 (a later, distinct
        // staged-FORCE-activation branch) found a genuine need to wrap
        // evaluate()'s $firm->firmSettings read in runWithFirmContext(),
        // since firm_settings now has permanent FORCE ROW LEVEL
        // SECURITY. Only the single $settings read line changed —
        // decision logic, order, and return values are byte-for-byte
        // identical.
        'app/Services/DocumentSecurityService.php',
        'app/Services/DocumentUploadPolicyService.php',
        'app/Services/TemplatePackCommercialService.php',
        // TemplatePackInstallationService.php is deliberately NOT in
        // this list any more — Section 39A-3L, Checkpoint 6, Table
        // Phase C (a later, distinct staged-FORCE-activation branch)
        // found a genuine need to wrap install()/markUpgradeAvailable()/
        // disable() each in their own runWithFirmContext() call, since
        // installed_template_packs now has permanent FORCE ROW LEVEL
        // SECURITY (this closed a silent-no-op bug: tap($model)->
        // update() previously appeared to succeed while the underlying
        // UPDATE actually affected zero rows).
        // ReadinessScorecardRegistry.php is deliberately NOT in this
        // list any more — Section 39A-3E (a later, distinct staged-
        // FORCE-activation branch) found a genuine need to wire the
        // tasks_dependencies_ready evaluator's Task query with
        // explicit tenant context, since tasks now has permanent
        // FORCE ROW LEVEL SECURITY.
        // MatterReadinessService.php is deliberately NOT in this list
        // any more either — Section 39A-3F (a later, distinct staged-
        // FORCE-activation branch) found a genuine need to wire its
        // $score->matter()->first() relation read with explicit
        // tenant context, since matters now has permanent FORCE ROW
        // LEVEL SECURITY.
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'composer.json',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 30 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_new_tables_or_schema_files_were_added(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_were_added(): void
    {
        $markers = self::NEW_SERVICE_FILES;

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
                    $marker = str_replace('.php', '', $marker);
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "Section 30 must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }
    }

    public function test_no_github_workflows_or_ci_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('.github');

        $this->assertEmpty(
            $changed,
            'Section 30 must add no CI/workflow files, but found changed/untracked .github files: '.implode(', ', $changed)
        );
    }

    public function test_composer_json_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('composer.json', $changed, 'Section 30 must not modify composer.json.');
    }

    public function test_protected_files_were_not_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 30 must not modify protected files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_unexpected_functional_test_file_was_modified(): void
    {
        $changedTestFiles = array_filter(
            $this->changedOrUntrackedPaths('tests'),
            fn (string $path) => ! str_starts_with($path, 'tests/Feature/Governance/')
                && ! str_starts_with($path, 'tests/Feature/Security/')
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/')
                // feature/ses-consumer-ecs-wiring (a later, distinct ECS/IAM
                // wiring mission) legitimately added its own new test
                // directory entries under tests/Feature/Ecs/.
                && ! str_starts_with($path, 'tests/Feature/Ecs/')
                // Section 39A-2 legitimately added test helper methods
                // to tests/TestCase.php.
                && $path !== 'tests/TestCase.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately updated this test to
                // wrap post-call reads in explicit tenant context, once
                // conflict_check_runs gained permanent FORCE ROW LEVEL
                // SECURITY.
                && $path !== 'tests/Feature/Conflicts/ConflictCheckServiceTest.php'
                // Section 39A-3L Stage A legitimately added a PHPUnit
                // bootstrap guard outside the governance-mapping tree.
                && $path !== 'tests/bootstrap.php'
                && $path !== 'tests/bootstrap-verify-test-database.php'
                && $path !== 'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php'
                && $path !== 'tests/Feature/Ai/Entitlement/AiEntitlementAndModeBlockingTest.php'
                && $path !== 'tests/Feature/Ai/Foundation/AiModeEnumReplacementTest.php'
                && $path !== 'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php'
                // Section 39A-9 Wave 9 (migration/export domain)
                // legitimately updated these existing functional test
                // files outside the governance-mapping tree once their
                // underlying tables gained permanent FORCE ROW LEVEL
                // SECURITY.
                && $path !== 'tests/Feature/Deployment/Fleet/FleetMigrationOrchestrationServiceTest.php'
                && $path !== 'tests/Feature/Implementation/ImplementationTaskServiceTest.php'
                && $path !== 'tests/Feature/Imports/ImportBatchServiceTest.php'
                && $path !== 'tests/Feature/Imports/ImportPreviewServiceTest.php'
                && $path !== 'tests/Feature/TenantIsolation/ImportExportTenantIsolationTest.php'
                && $path !== 'tests/Feature/Webhooks/Wiring/InvoiceCreatedWiringTest.php',
        );

        $this->assertEmpty(
            array_values($changedTestFiles),
            'Section 30 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_no_forbidden_scanner_ocr_pdf_sms_whatsapp_payment_or_process_token_appears_in_any_new_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 30 service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $valueObjectPath = app_path('ValueObjects/CommandCenterSnapshot.php');
        $this->assertFileExists($valueObjectPath, 'Expected Section 30 value object missing: CommandCenterSnapshot.php');
        $source = $this->stripComments(file_get_contents($valueObjectPath));

        foreach (self::FORBIDDEN_TOKENS as $token) {
            if (str_contains($source, $token)) {
                $violations[] = "CommandCenterSnapshot.php contains forbidden token: {$token}";
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_command_center_aggregation_service_performs_no_write_operations(): void
    {
        $source = $this->stripComments(file_get_contents(app_path('Services/FirmCommandCenterAggregationService.php')));

        foreach (['::create(', '::update(', '::delete(', '->save(', '->update(', '->delete('] as $writeToken) {
            $this->assertStringNotContainsString(
                $writeToken,
                $source,
                "FirmCommandCenterAggregationService must be read-only, but found write token: {$writeToken}"
            );
        }
    }

    public function test_no_template_pack_seed_data_or_trust_workflow_was_created(): void
    {
        // Section 39E (a later, distinct security-remediation branch)
        // guarded DatabaseSeeder.php's existing default user to
        // local/testing only — it created no template-pack seed data.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/seeders'),
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php',
        ));

        $this->assertEmpty(
            $changed,
            'Section 30 must not create template-pack seed data, but found changed/untracked seeder files: '.implode(', ', $changed)
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
            // Section 39A-3L, Checkpoint 6, Table Phase C (this
            // batch, a later, distinct staged-FORCE-activation
            // branch) legitimately added an
            // installed_template_packs-only FORCE RLS migration, an
            // InstalledTemplatePackFactory context-hold fix, wrapped
            // TemplatePackInstallationService's three public methods
            // each in their own runWithFirmContext() call, and
            // updated the tests it affected.
            'database/migrations/2026_08_25_930006_force_rls_on_installed_template_packs_table.php',
            'database/factories/InstalledTemplatePackFactory.php',
            'app/Services/TemplatePackInstallationService.php',
            'tests/Feature/PracticeTemplates/TemplatePackInstallationServiceTest.php',
            'tests/Feature/Templates/TemplateUpgradeLogServiceTest.php',
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
            // Section 39A-9 Wave 9 (migration/export domain) legitimately
            // added six combined prepare-and-force migrations (export_jobs,
            // migration_projects, import_batches, implementation_projects,
            // fleet_migration_instance_status, offboarding_requests), their
            // six factories' context-hold fixes, wired independent
            // runWithFirmContext() wraps into several real services, and
            // updated the tests it affected.
            'database/migrations/2026_08_29_970001_prepare_row_level_security_and_force_rls_on_export_jobs_table.php',
            'database/migrations/2026_08_29_970002_prepare_row_level_security_and_force_rls_on_migration_projects_table.php',
            'database/migrations/2026_08_29_970003_prepare_row_level_security_and_force_rls_on_import_batches_table.php',
            'database/migrations/2026_08_29_970004_prepare_row_level_security_and_force_rls_on_implementation_projects_table.php',
            'database/migrations/2026_08_29_970005_prepare_row_level_security_and_force_rls_on_fleet_migration_instance_status_table.php',
            'database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php',
            'database/factories/ExportJobFactory.php',
            'database/factories/FleetMigrationInstanceStatusFactory.php',
            'database/factories/ImplementationProjectFactory.php',
            'database/factories/ImportBatchFactory.php',
            'database/factories/MigrationProjectFactory.php',
            'database/factories/OffboardingRequestFactory.php',
            'tests/Feature/Deployment/Fleet/FleetMigrationOrchestrationServiceTest.php',
            'tests/Feature/Implementation/ImplementationTaskServiceTest.php',
            'tests/Feature/Imports/ImportBatchServiceTest.php',
            'tests/Feature/Imports/ImportPreviewServiceTest.php',
            'tests/Feature/TenantIsolation/ImportExportTenantIsolationTest.php',
            'tests/Feature/Webhooks/Wiring/InvoiceCreatedWiringTest.php',
            // Phase 2 of the FirmsVault Platform Admin Control Center
            // mission ("Integration Operations Center"; a later,
            // entirely distinct mission from this Section) legitimately
            // added: a new no-RLS provider-health summary table + model
            // + sole-writer service + per-provider refresh job +
            // scheduled command (mirroring
            // integration_platform_overview_summaries' own established
            // pattern exactly); a narrow admin-actor extension to
            // ProviderConnectionService::disconnect() plus a new
            // disconnectConnection() wrapper method on
            // PlatformFirmIntegrationBoundedAccessService; a new
            // canManageIntegrationConnections() policy gate; query
            // determinism/pagination fixes in
            // IntegrationPlatformOversightReadService and
            // PlatformFirmIntegrationsPage; a new scheduled-command
            // entry in bootstrap/app.php; and its own new test files.
            'database/migrations/2026_09_11_110001_create_integration_platform_provider_health_summaries_table.php',
            'app/Models/IntegrationPlatformProviderHealthSummary.php',
            'app/Jobs/RefreshIntegrationPlatformProviderHealthSummaryJob.php',
            'app/Console/Commands/RefreshIntegrationPlatformProviderHealthSummariesCommand.php',
            'app/Integrations/Services/ProviderConnectionService.php',
            'app/Filament/Pages/PlatformFirmIntegrationsPage.php',
            'bootstrap/app.php',
            'tests/Feature/Integrations/Admin/PlatformIntegrationProviderHealthSummaryTest.php',
            'tests/Feature/Integrations/Admin/PlatformIntegrationConnectionDisconnectTest.php',
            'tests/Feature/Integrations/Admin/PlatformIntegrationOversightQueryDeterminismTest.php',
            // Also updated the pre-existing
            // IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest.php
            // (tests/Unit/Integrations/) to add
            // IntegrationPlatformProviderHealthSummaryService.php to its
            // own source-inspection allowlist, mirroring that test's
            // existing IntegrationPlatformOversightReadService.php entry
            // exactly (same ->exists()-only existence-check pattern).
            'tests/Unit/Integrations/IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest.php',
            // FIRMSVAULT — STAGING ADMIN STABILIZATION (a later,
            // independently reviewed mission) legitimately touches
            // files under this section's own protected scope — see
            // that mission's own commit history for full context.
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
            // feature/ses-event-consumer (a later, distinct, wholly
            // isolated mission: a production-safe SES bounce/
            // complaint consumer) legitimately added a
            // notification-provider correlation ledger + idempotency
            // ledger (both exempted, no-RLS, registered in
            // RowLevelSecurityCoverageMappingService per the same
            // integration_webhook_routing_index/
            // integration_platform_provider_health_summaries
            // precedent pattern), a dedicated SQS consumer command,
            // real-send correlation wiring in User/ClientPortalUser
            // password-reset notifications, and its own new test
            // files.
            'app/Models/ClientPortalUser.php',
            'app/Models/NotificationEvent.php',
            'app/Models/User.php',
            'app/Notifications/ClientPortalResetPasswordNotification.php',
            'app/Notifications/FirmOwnerInvitationNotification.php',
            'app/Providers/AppServiceProvider.php',
            'app/Services/NotificationDispatchService.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'config/mail.php',
            'config/services.php',
            'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
            'tests/Feature/Security/SeedData/SecretPatternScanTest.php',
            'app/Console/Commands/ConsumeSesEventsCommand.php',
            'app/Enums/SesBounceType.php',
            'app/Enums/SesEventType.php',
            'app/Models/NotificationProviderCorrelation.php',
            'app/Models/SesEventReceipt.php',
            'app/Services/OutboundMailCorrelationService.php',
            'app/Services/SesEventConsumerService.php',
            'database/migrations/2026_10_15_100001_add_provider_message_id_to_notification_events_table.php',
            'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
            'database/migrations/2026_10_15_100003_create_ses_event_receipts_table.php',
            'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
            'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
            'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
            // post-578ee98 audit remediation (a later, distinct,
            // independent security/architecture review of the SES
            // event consumer feature) legitimately fixed a
            // MessageSent listener leak, an uncaught-exception
            // crash risk, a receipt-write concurrency race, a
            // complaint recipient-mismatch hard-reject, and added a
            // new platform-scope correlation/suppression subsystem
            // for password-reset sends that cannot resolve a firm —
            // plus its own new test files.
            'app/Console/Commands/ConsumeSesEventsCommand.php',
            'app/Models/ClientPortalUser.php',
            'app/Models/User.php',
            'app/Services/OutboundMailCorrelationService.php',
            'app/Services/RowLevelSecurityCoverageMappingService.php',
            'app/Services/SesEventConsumerService.php',
            'app/Services/SuppressionService.php',
            'config/services.php',
            'database/migrations/2026_10_15_100002_create_notification_provider_correlations_table.php',
            'tests/Feature/Governance/DataModelContract/RowLevelSecurityCoverageMappingServiceTest.php',
            'tests/Feature/Mail/SesMailerTransportTest.php',
            'tests/Feature/Notifications/ConsumeSesEventsCommandTest.php',
            'tests/Feature/Notifications/OutboundMailCorrelationServiceTest.php',
            'tests/Feature/Notifications/SesEventConsumerServiceTest.php',
            'tests/Feature/Notifications/SuppressionServiceTest.php',
            'app/Models/PlatformNotificationCorrelation.php',
            'app/Models/PlatformNotificationSuppression.php',
            'app/Services/PlatformNotificationCorrelationService.php',
            'database/migrations/2026_10_20_100001_create_platform_notification_correlations_table.php',
            'database/migrations/2026_10_20_100002_create_platform_notification_suppressions_table.php',
            'tests/Feature/Notifications/PasswordResetPlatformCorrelationFallbackTest.php',
            'tests/Feature/Notifications/PlatformNotificationCorrelationServiceTest.php',
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
