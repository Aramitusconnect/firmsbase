<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use Tests\TestCase;

/**
 * AcceptanceTestMatrixFirewallTest — proves Section 36 stayed within
 * its declared implementation boundary: no migrations, no schema
 * changes, no model modifications, no domain behavior service
 * modified, no UI/routes/controllers/Filament/Blade/Livewire changes,
 * no browser/mobile test harness introduced, no payment/trust/AI/
 * email/SMS/provider behavior file modified, and the new mapping
 * service contains no Schema::/DB:: writes or network/provider/
 * process/shell execution.
 */
class AcceptanceTestMatrixFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'AcceptanceTestMatrixMappingService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'DB::insert', 'DB::table(', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send',
    ];

    /**
     * Domain behavior/mapping services this section may only read/
     * reference in notes — none may be modified. ComplianceGapRegistryService
     * is deliberately EXCLUDED: it is the one file every section is
     * allowed to conditionally modify when new AWS evidence confirms a
     * real gap (not needed this section, per AWS findings).
     */
    private const PROTECTED_FILES = [
        'app/Services/TestCoverageMappingService.php',
        'app/Services/ReleaseChecklistReadinessService.php',
        'app/Services/DefinitionOfDoneReadinessService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/PermissionMatrixMappingService.php',
        'app/Services/DeploymentModeCoverageMappingService.php',
        'app/Services/OperationalReadinessMappingService.php',
        'app/Services/MobilePortalCoverageMappingService.php',
        'app/Services/TemplatePackCoverageMappingService.php',
        'app/Services/TrustDependentPackGatingMappingService.php',
        'app/Services/EdgeCaseRiskCatalogMappingService.php',
        'app/Services/AdminControlCatalogMappingService.php',
        'app/Services/AdminPermissionAuditCoverageMappingService.php',
        'app/Services/WorkflowStateCatalogMappingService.php',
        'app/Services/WorkflowTransitionRuleMappingService.php',
        'app/Services/EntityFieldCatalogMappingService.php',
        'app/Services/DataModelContractMappingService.php',
        'app/Services/SuppressionService.php',
        'app/Services/DeterministicFieldResolutionService.php',
        'app/Services/CommissionEligibilityService.php',
        'app/Services/ConflictCheckService.php',
        'app/Services/DocumentSecurityService.php',
        'app/Services/DocumentUploadPolicyService.php',
        'app/Services/DocumentReplacementService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/PaymentPlanDunningService.php',
        // ConsentService.php is deliberately NOT in this list any
        // more — Section 39A-3L, Checkpoint 11 (a later, distinct
        // staged-FORCE-activation branch) found a genuine need to
        // wrap capture()/revoke()'s bodies in runWithFirmContext(),
        // since communication_consents now has permanent FORCE ROW
        // LEVEL SECURITY.
        'app/Services/InvoiceDraftingService.php',
        'app/Services/PaymentPlanService.php',
        'app/Services/TrustConcurrencyLockService.php',
        'app/Services/TrustBalanceService.php',
        'app/Services/AiApprovalWorkflowService.php',
        'app/Services/PromptInjectionResistanceService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/AiBudgetEnforcementService.php',
        'app/Services/EncryptionKeyService.php',
        'app/Services/EntitlementService.php',
        'app/Services/FeatureGateService.php',
        'app/Services/SeatEnforcementService.php',
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/LicenseFileValidationService.php',
        'app/Services/FormTemplateService.php',
        'app/Services/FormEditionWatchService.php',
        'app/Models/Firm.php',
        'app/Services/TenantContextResolver.php',
        'app/Services/TrustPilotExitCriteriaService.php',
        // TrustEligibilityService.php is deliberately NOT in this list
        // any more — Section 39A-3L, Checkpoint 18 (a later, distinct
        // staged-FORCE-activation branch) found a genuine need to wrap
        // evaluate()'s $firm->firmSettings read in runWithFirmContext(),
        // since firm_settings now has permanent FORCE ROW LEVEL
        // SECURITY. Only the single $settings read line changed —
        // decision logic, order, and return values are byte-for-byte
        // identical.
        'composer.json',
    ];

    /**
     * Scoped to prefixes rather than exact filenames so this test
     * keeps working as later sections add their own new mapping
     * services and sibling test directories (matches the fix already
     * applied to firewall tests in Sections 31-35, itself following
     * the QualityGateFirewallTest precedent from Section 29).
     */
    private const ALLOWED_NEW_FILE_PREFIXES = [
        'app/Services/',
        'tests/Feature/Governance/',
        'tests/Feature/Security/',
        'tests/Feature/SupportAccess/',
        // Section 39A (a later, distinct RLS-activation branch)
        // legitimately added a route-independent middleware file and a
        // queue-job tenant-context trait.
        'app/Http/Middleware/',
        'app/Support/',
        // Section 39A-2 (a later, distinct RLS-context-rollout branch)
        // legitimately added test helper methods to tests/TestCase.php.
        'tests/TestCase.php',
        // Section 40 (a later, distinct limited-pilot-safety-gate
        // branch) legitimately added its own markdown report under
        // docs/governance/.
        'docs/',
        // Section 39A-3I (a later, distinct staged-FORCE-activation
        // branch) legitimately added a reusable Claude Code subagent
        // team under .claude/agents/ for the RLS backlog effort, a
        // conflict_check_runs-only FORCE RLS migration, a
        // ConflictCheckRunFactory fix, and its own test files.
        '.claude/agents/',
        'database/factories/ConflictCheckRunFactory.php',
        'tests/Feature/Conflicts/ConflictCheckServiceTest.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 36 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_schema_files_changed(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_models_were_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Models');

        $this->assertEmpty(
            $changed,
            'Section 36 must not modify any model, but found changes to: '.implode(', ', $changed)
        );
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 36 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_browser_or_mobile_test_harness_was_introduced(): void
    {
        $this->assertStringNotContainsString('laravel/dusk', file_get_contents(base_path('composer.json')));

        $duskFiles = glob(base_path('tests/Browser/*.php')) ?: [];
        $this->assertEmpty($duskFiles, 'No Dusk/browser test harness should exist.');
    }

    public function test_no_domain_behavior_services_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 36 must not modify protected domain behavior/mapping service files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_payment_trust_ai_email_sms_or_provider_behavior_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $behaviorFilePatterns = [
            'PaymentApplicationService', 'TrustTransferRequestService', 'TrustRefundRequestService',
            'AiProviderKeyService', 'AiUsageRecorderService', 'AiModeResolutionService',
            'WebhookRetryPolicyService', 'WebhookDeliveryService', 'SuppressionService',
            'Stripe/StripeGateway', 'Stripe/FakeStripeGateway',
        ];

        $touched = array_values(array_filter(
            $changedRepoWide,
            function (string $path) use ($behaviorFilePatterns) {
                foreach ($behaviorFilePatterns as $pattern) {
                    if (str_contains($path, $pattern)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $this->assertEmpty($touched, 'Section 36 must not modify payment/trust/AI/email/SMS/provider behavior files, but found: '.implode(', ', $touched));
    }

    public function test_no_files_were_added_outside_the_allowed_locations(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_filter(
            $changedRepoWide,
            function (string $path) {
                if ($path === 'app/Services/ComplianceGapRegistryService.php' || $path === 'database/seeders/DatabaseSeeder.php') {
                    return false;
                }

                foreach (self::ALLOWED_NEW_FILE_PREFIXES as $allowed) {
                    if ($path === $allowed || str_starts_with($path, $allowed)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertEmpty($unexpected, 'Section 36 must only add/modify files under the allowed locations, but found: '.implode(', ', $unexpected));
    }

    public function test_no_unexpected_functional_test_file_was_modified(): void
    {
        $changedTestFiles = array_filter(
            $this->changedOrUntrackedPaths('tests'),
            fn (string $path) => ! str_starts_with($path, 'tests/Feature/Governance/')
                && ! str_starts_with($path, 'tests/Feature/Security/')
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/')
                // Section 39A-2 legitimately added test helper methods
                // to tests/TestCase.php.
                && $path !== 'tests/TestCase.php'
                // Section 39A-3I (a later, distinct staged-FORCE-
                // activation branch) legitimately updated this test to
                // wrap post-call reads in explicit tenant context, once
                // conflict_check_runs gained permanent FORCE ROW LEVEL
                // SECURITY.
                && $path !== 'tests/Feature/Conflicts/ConflictCheckServiceTest.php',
        );

        $this->assertEmpty(
            array_values($changedTestFiles),
            'Section 36 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_new_service_contains_no_db_writes_schema_calls_or_network_provider_process_shell_execution(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 36 service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }

            foreach (['::create(', '::update(', '::delete(', '->save(', '->update(', '->delete('] as $writeToken) {
                if (str_contains($source, $writeToken)) {
                    $violations[] = "{$filename} contains write token: {$writeToken}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_protected_migration_files_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'No migration file may be touched by Section 36, but found: '.implode(', ', $changed));
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
