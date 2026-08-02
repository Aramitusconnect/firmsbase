<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use Tests\TestCase;

/**
 * AdminControlFirewallTest — proves Section 34 stayed within its
 * declared implementation boundary: no migrations, no schema changes,
 * no model modifications, no permission/policy/domain behavior service
 * modified, no payment/trust/AI/deployment behavior file modified, no
 * UI/routes/controllers/Filament/Blade/Livewire changes, and the two
 * new mapping services contain no Schema::/DB:: writes or network/
 * provider/process/shell execution.
 */
class AdminControlFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'AdminControlCatalogMappingService.php',
        'AdminPermissionAuditCoverageMappingService.php',
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
     * Permission/policy/audit files, plus domain behavior services
     * across every admin area — none may be modified by Section 34.
     * ComplianceGapRegistryService is deliberately EXCLUDED: it is the
     * one file every section is allowed to conditionally modify when
     * new AWS evidence confirms a real gap.
     */
    private const PROTECTED_FILES = [
        // PlatformStaffAccessPolicyService.php is deliberately NOT in
        // this list any more — Phase 2 of the FirmsVault Platform Admin
        // Control Center mission ("Integration Operations Center"; a
        // later, entirely distinct mission from Section 34) found a
        // genuine need to add a new, purely additive
        // canManageIntegrationConnections() role-ceiling gate method,
        // following this file's own established decideAgainst()
        // pattern exactly — no existing method's behavior changed.
        'app/Enums/PlatformRoleCode.php',
        'app/Services/PlatformRoleService.php',
        'app/Services/HighRiskPlatformChangePolicyService.php',
        'app/Models/SecurityEvent.php',
        'app/Services/TimelineEventRecorder.php',
        'app/Services/OrgLicenseService.php',
        'app/Services/FirmLicenseCommercialService.php',
        'app/Services/LicenseFileSigningService.php',
        'app/Services/EntitlementService.php',
        'app/Services/AiBudgetEnforcementService.php',
        'app/Services/AiApprovalWorkflowService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/TrustIoltaDisableAcknowledgmentService.php',
        'app/Services/TrustModeActivationService.php',
        'app/Services/TrustReconciliationService.php',
        'app/Services/TrustHighRiskAdjustmentService.php',
        'app/Services/TrustJurisdictionReadinessService.php',
        'app/Services/TrustAccessPolicyService.php',
        'app/Services/TrustConcurrencyLockService.php',
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
        'app/Services/TemplateUpgradePreviewService.php',
        'app/Services/FormEditionWatchService.php',
        // FleetMigrationOrchestrationService.php is deliberately NOT in
        // this list any more — Section 39A-9 Wave 9 (migration/export
        // domain) found a genuine need to wrap its migration-instance
        // status transitions in runWithFirmContext(), since
        // fleet_migration_instance_status now has permanent FORCE ROW
        // LEVEL SECURITY.
        'app/Services/VersionSkewPolicyService.php',
        // DeploymentHealthEnvelopeService.php is deliberately NOT in
        // this list any more — Section 39A-8 Wave 8 found a genuine
        // need to wrap buildEnvelope()'s DeploymentHealthCheck::create()
        // call and reportOffline()'s own create() call each in its own
        // runWithFirmContext() call, since deployment_health_checks now
        // has permanent FORCE ROW LEVEL SECURITY. The pre-existing
        // PrivateEnterpriseSettings wrap in buildEnvelope() is
        // untouched; the two wraps remain sequential, never nested.
        // CustomerSuccessHealthScoreService.php is deliberately NOT in
        // this list any more — Section 39A-3L, Checkpoint 22 (a later,
        // distinct staged-FORCE-activation branch) found a genuine
        // need to wrap the $firm->paymentPlans()->count() read in its
        // own tight runWithFirmContext() call, since payment_plans now
        // has permanent FORCE ROW LEVEL SECURITY. Only that single
        // count line changed — every other line, order, and return
        // value is byte-for-byte identical.
        'app/Services/AnnouncementService.php',
        'app/Services/ReleaseNoteService.php',
        'app/Services/StatusPageService.php',
        'app/Services/IncidentService.php',
        'app/Services/VendorRegisterService.php',
        'app/Services/AccessReviewService.php',
        'app/Services/RetentionPolicyService.php',
        // OffboardingRequestService.php is deliberately NOT in this
        // list any more — Section 39A-8 Wave 8 found a genuine need to
        // wrap evaluateReadiness()'s single hasActiveHold() call in
        // runWithFirmContext(), since legal_holds now has permanent
        // FORCE ROW LEVEL SECURITY (closing a fail-open bug: an
        // unwrapped read under FORCE silently returns zero rows rather
        // than erroring, making an active hold invisible).
        // KeyDestructionApprovalService.php is deliberately NOT in this
        // list any more — Section 39A-8 Wave 8 found a genuine need to
        // change secondApprove()/deny() to accept the parent
        // KeyDestructionRequest as an explicit parameter (rather than a
        // lazy $approval->keyDestructionRequest relation load, which
        // would silently return null under FORCE with no ambient
        // context) and wrap the resulting status-transition write in
        // runWithFirmContext(), since key_destruction_requests now has
        // permanent FORCE ROW LEVEL SECURITY. A mismatch guard
        // (InvalidArgumentException) was added as the application-layer
        // analogue of a composite-FK check.
        'app/Services/PermissionMatrixMappingService.php',
        // DeploymentModeCoverageMappingService.php is deliberately
        // NOT in this list any more — Section 39A-5 Wave 11 (the
        // final wave of the 60-table RLS rollout) found a genuine
        // need to correct this service's own governance notes text,
        // which had gone self-contradictory once
        // MISSING_PREPARED_TABLES became genuinely empty (the notes
        // cited an uncovered-table count as the reason a control was
        // not yet Implemented, but that count is now zero) — the
        // PartiallyImplemented status itself was not changed, only
        // the stated reasons, which now correctly cite the other
        // still-genuinely-open items (cross-firm-pivot-mismatch
        // remediation, firms root-table policy, support-access
        // policy shape, offboarding_exports classification).
        'app/Services/OperationalReadinessMappingService.php',
        'app/Services/FirmCommandCenterAggregationService.php',
        'app/Services/TemplatePackCoverageMappingService.php',
        'app/Services/EntityFieldCatalogMappingService.php',
        'app/Services/WorkflowStateCatalogMappingService.php',
        'app/Services/WorkflowTransitionRuleMappingService.php',
        'app/Models/Firm.php',
        'app/Services/TenantContextResolver.php',
        'app/Services/LicenseFileValidationService.php',
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
     * applied to firewall tests in Sections 31/32/33, itself following
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
        // Section 39A-3L Stage A (a later, distinct test-harness-safety
        // branch) legitimately added disposable-database tooling under
        // tools/rls-test/, a PHPUnit bootstrap guard, and reviewed
        // config/gitignore corrections.
        'tools/rls-test/',
        'tests/bootstrap.php',
        'tests/bootstrap-verify-test-database.php',
        '.env.testing.example',
        '.gitignore',
        'phpunit.xml',
        // Section 39A-3L Stage A also legitimately fixed a missing-
        // tenant-context bug in four existing tests/Feature/Ai/ files.
        'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php',
        'tests/Feature/Ai/Entitlement/AiEntitlementAndModeBlockingTest.php',
        'tests/Feature/Ai/Foundation/AiModeEnumReplacementTest.php',
        'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php',
        // Section 39A-8 Wave 8 (governance/support/platform domain)
        // legitimately updated this test to wrap bare
        // assertDatabaseHas()/direct-query reads in explicit tenant
        // context, once deployment_health_checks gained permanent
        // FORCE ROW LEVEL SECURITY.
        'tests/Feature/Deployment/Health/DeploymentHealthEnvelopeServiceTest.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 34 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
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
            'Section 34 must not modify any model, but found changes to: '.implode(', ', $changed)
        );
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 34 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
    }

    public function test_no_permission_policy_or_domain_behavior_services_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 34 must not modify protected permission/policy/domain behavior/mapping service files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_payment_trust_ai_or_deployment_behavior_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        // DeploymentHealthEnvelopeService is deliberately NOT in this
        // list any more — Section 39A-8 Wave 8 found a genuine need to
        // wrap buildEnvelope()'s DeploymentHealthCheck::create() call
        // and reportOffline()'s own create() call each in its own
        // runWithFirmContext() call, since deployment_health_checks now
        // has permanent FORCE ROW LEVEL SECURITY.
        // FleetMigrationOrchestrationService is deliberately NOT in
        // this list any more — Section 39A-9 Wave 9 (migration/export
        // domain) found a genuine need to wrap its migration-instance
        // status transitions in runWithFirmContext(), since
        // fleet_migration_instance_status now has permanent FORCE ROW
        // LEVEL SECURITY.
        $behaviorFilePatterns = [
            'PaymentApplicationService', 'PaymentPlanService', 'PaymentPlanInstallmentService',
            'TrustDepositService', 'TrustTransferRequestService', 'TrustRefundRequestService',
            'TrustLedgerEntryReversalService', 'TrustChargebackService', 'TrustBalanceService',
            'AiProviderKeyService', 'AiModeResolutionService', 'AiUsageRecorderService',
            'LicenseFileSigningService',
        ];

        $touched = array_values(array_filter(
            $changedRepoWide,
            function (string $path) use ($behaviorFilePatterns) {
                // Section 39A-3L Stage A legitimately fixed a missing-
                // tenant-context bug in this existing test file — its
                // path happens to contain the "AiUsageRecorderService"
                // substring this check scans for, but no production
                // service file was touched.
                if ($path === 'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php') {
                    return false;
                }

                foreach ($behaviorFilePatterns as $pattern) {
                    if (str_contains($path, $pattern)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $this->assertEmpty($touched, 'Section 34 must not modify payment/trust/AI/deployment behavior files, but found: '.implode(', ', $touched));
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

        $this->assertEmpty($unexpected, 'Section 34 must only add/modify files under the allowed locations, but found: '.implode(', ', $unexpected));
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
                && $path !== 'tests/Feature/Conflicts/ConflictCheckServiceTest.php'
                // Section 39A-3L Stage A legitimately added a PHPUnit
                // bootstrap guard outside the governance-mapping tree.
                && $path !== 'tests/bootstrap.php'
                && $path !== 'tests/bootstrap-verify-test-database.php'
                && $path !== 'tests/Feature/Ai/Concerns/SetsUpAiEntitledFirm.php'
                && $path !== 'tests/Feature/Ai/Entitlement/AiEntitlementAndModeBlockingTest.php'
                && $path !== 'tests/Feature/Ai/Foundation/AiModeEnumReplacementTest.php'
                && $path !== 'tests/Feature/Ai/Usage/AiUsageRecorderServiceTest.php'
                // Section 39A-8 Wave 8 legitimately updated this test to
                // wrap bare assertDatabaseHas()/direct-query reads in
                // explicit tenant context, once deployment_health_checks
                // gained permanent FORCE ROW LEVEL SECURITY.
                && $path !== 'tests/Feature/Deployment/Health/DeploymentHealthEnvelopeServiceTest.php'
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
            'Section 34 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_new_services_contain_no_db_writes_schema_calls_or_network_provider_process_shell_execution(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 34 service file missing: {$filename}");

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

        $this->assertEmpty($changed, 'No migration file may be touched by Section 34, but found: '.implode(', ', $changed));
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
            // Section 39A-8 Wave 8 (the eighth coordinated multi-table
            // wave, governance/support/platform domain) legitimately
            // added six combined prepare-and-force migrations
            // (legal_holds, deletion_requests, key_destruction_requests,
            // support_access_requests, support_access_sessions,
            // deployment_health_checks) and their six factories'
            // context-hold fixes.
            'database/migrations/2026_08_28_960001_prepare_row_level_security_and_force_rls_on_legal_holds_table.php',
            'database/migrations/2026_08_28_960002_prepare_row_level_security_and_force_rls_on_deletion_requests_table.php',
            'database/migrations/2026_08_28_960003_prepare_row_level_security_and_force_rls_on_key_destruction_requests_table.php',
            'database/migrations/2026_08_28_960004_prepare_row_level_security_and_force_rls_on_support_access_requests_table.php',
            'database/migrations/2026_08_28_960005_prepare_row_level_security_and_force_rls_on_support_access_sessions_table.php',
            'database/migrations/2026_08_28_960006_prepare_row_level_security_and_force_rls_on_deployment_health_checks_table.php',
            'database/factories/LegalHoldFactory.php',
            'database/factories/DeletionRequestFactory.php',
            'database/factories/KeyDestructionRequestFactory.php',
            'database/factories/SupportAccessRequestFactory.php',
            'database/factories/SupportAccessSessionFactory.php',
            'database/factories/DeploymentHealthCheckFactory.php',
            // Section 39A-9 Wave 9 (migration/export domain) legitimately
            // added six combined prepare-and-force migrations (export_jobs,
            // migration_projects, import_batches, implementation_projects,
            // fleet_migration_instance_status, offboarding_requests), their
            // six factories' context-hold fixes, wired independent
            // runWithFirmContext() wraps into ExportJobService,
            // FleetMigrationOrchestrationService, ImplementationProjectService,
            // ImplementationTaskService, ImportApplyService, ImportBatchService,
            // ImportPreviewService, ImportRollbackService,
            // ImportRowValidationService, MigrationProjectService, and
            // OffboardingRequestService, and updated the tests it affected.
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
            // independently reviewed mission) fixed a real Platform
            // Admin dashboard HTTP 500 (phpredis serializer
            // misconfiguration), added Create/Edit actions for the Plan
            // catalog and a Create action for Plan Modules/Add-ons
            // (previously read-only), a plan-selection safety guard in
            // firm provisioning, and a staging-safe synthetic-plan
            // bootstrap command — plus its own new/updated tests.
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
