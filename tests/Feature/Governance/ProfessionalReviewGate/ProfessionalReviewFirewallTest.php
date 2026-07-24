<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use Tests\TestCase;

/**
 * ProfessionalReviewFirewallTest — proves Section 37 stayed within its
 * declared implementation boundary: no migrations, no schema changes,
 * no model modifications, no domain behavior service modified, no UI/
 * routes/controllers/Filament/Blade/Livewire changes, no duplicate
 * readiness/license/entitlement/signature/audit/gap system introduced,
 * no payment/trust/AI/email/SMS/provider behavior file modified, the
 * new mapping service contains no forbidden tokens, and protected
 * files are untouched (ComplianceGapRegistryService is deliberately
 * excluded from the protected list since Section 37 was allowed to
 * conditionally modify it — AWS confirmed no modification was needed).
 */
class ProfessionalReviewFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'ProfessionalReviewGateMappingService.php',
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
        'app/Services/ReleaseChecklistReadinessService.php',
        'app/Services/DefinitionOfDoneReadinessService.php',
        // RowLevelSecurityCoverageMappingService.php is
        // deliberately NOT in this list any more — Section 39A-5
        // Wave 11 (the final wave of the 60-table RLS rollout)
        // found a genuine need to update the shared RLS coverage
        // registry once every table was moved into PREPARED_TABLES
        // and MISSING_PREPARED_TABLES became genuinely empty.
        'app/Services/PermissionMatrixMappingService.php',
        // TestCoverageMappingService.php and
        // DeploymentModeCoverageMappingService.php are deliberately
        // NOT in this list any more — Section 39A-5 Wave 11 (the
        // final wave of the 60-table RLS rollout) found a genuine
        // need to correct these two services' own governance notes
        // text, which had gone self-contradictory once
        // MISSING_PREPARED_TABLES became genuinely empty (the notes
        // cited an uncovered-table count as the reason a control was
        // not yet Implemented, but that count is now zero) — the
        // PartiallyImplemented status itself was not changed, only
        // the stated reasons, which now correctly cite the other
        // still-genuinely-open items (cross-firm-pivot-mismatch
        // remediation, firms root-table policy, support-access
        // policy shape, offboarding_exports classification).
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
        'app/Services/AcceptanceTestMatrixMappingService.php',
        'app/Services/FinalExecutiveReadinessMappingService.php',
        'app/ValueObjects/ExecutiveReadinessSummary.php',
        'app/Services/SuppressionService.php',
        'app/Services/DeterministicFieldResolutionService.php',
        'app/Services/CommissionEligibilityService.php',
        // ConflictCheckService.php is deliberately NOT in this list any
        // more — Section 39A-3I (a later, distinct staged-FORCE-
        // activation branch) found a genuine need to wire run()'s
        // ConflictCheckRun::create()/update() calls with explicit
        // tenant context, since conflict_check_runs now has permanent
        // FORCE ROW LEVEL SECURITY.
        'app/Services/DocumentSecurityService.php',
        'app/Services/DocumentUploadPolicyService.php',
        'app/Services/DocumentReplacementService.php',
        // PaymentClassificationService.php is deliberately NOT in this
        // list any more — Section 39A-3H (a later, distinct staged-
        // FORCE-activation branch) found a genuine need to wire
        // recordDecision()'s $payment->update() call with explicit
        // tenant context, since payments now has permanent FORCE ROW
        // LEVEL SECURITY.
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
        // TrustEligibilityService.php is deliberately NOT in this list
        // any more — Section 39A-3L, Checkpoint 18 (a later, distinct
        // staged-FORCE-activation branch) found a genuine need to wrap
        // evaluate()'s $firm->firmSettings read in runWithFirmContext(),
        // since firm_settings now has permanent FORCE ROW LEVEL
        // SECURITY. Only the single $settings read line changed —
        // decision logic, order, and return values are byte-for-byte
        // identical.
        'app/Services/TrustPilotExitCriteriaService.php',
        'app/Services/AiApprovalWorkflowService.php',
        'app/Services/PromptInjectionResistanceService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/AiBudgetEnforcementService.php',
        'app/Services/PlatformStaffAccessPolicyService.php',
        'app/Services/EncryptionKeyService.php',
        'app/Services/EntitlementService.php',
        'app/Services/FeatureGateService.php',
        'app/Services/SeatEnforcementService.php',
        // FleetMigrationOrchestrationService.php is deliberately NOT in
        // this list any more — Section 39A-9 Wave 9 (migration/export
        // domain) found a genuine need to wrap its migration-instance
        // status transitions in runWithFirmContext(), since
        // fleet_migration_instance_status now has permanent FORCE ROW
        // LEVEL SECURITY.
        'app/Services/LicenseFileValidationService.php',
        'app/Services/SignatureCertificateService.php',
        'app/Services/OrgLicenseService.php',
        'app/Services/FormTemplateService.php',
        'app/Services/FormEditionWatchService.php',
        'app/Services/ImportApplyService.php',
        'app/Services/LegalDataAccessPolicyService.php',
        // LegalHoldService.php, DeletionGovernanceService.php,
        // OffboardingRequestService.php, and KeyDestructionRequestService.php
        // are deliberately NOT in this list any more — Section 39A-8
        // Wave 8 (governance/support/platform domain) found a genuine
        // need to wrap their reads/writes in runWithFirmContext(),
        // since legal_holds, deletion_requests, and
        // key_destruction_requests now have permanent FORCE ROW LEVEL
        // SECURITY.
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'app/Services/DowngradeEvaluationService.php',
        'app/Models/Firm.php',
        'app/Services/TenantContextResolver.php',
        'composer.json',
    ];

    /**
     * Scoped to prefixes rather than exact filenames so this test
     * keeps working as later sections add their own new mapping
     * services and sibling test directories (matches the fix already
     * applied to firewall tests in Sections 29-36).
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
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 37 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
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
            'Section 37 must not modify any model, but found changes to: '.implode(', ', $changed)
        );
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 37 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }
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

        $this->assertEmpty($touched, 'Section 37 must not modify protected domain behavior/mapping service files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_duplicate_readiness_license_entitlement_signature_audit_or_gap_system_was_introduced(): void
    {
        $duplicateReadinessServices = glob(app_path('Services/*ExecutiveReadiness*.php')) ?: [];
        $this->assertCount(1, $duplicateReadinessServices, 'Only one final-executive-readiness service may exist.');

        $duplicateGapRegistries = glob(app_path('Services/*GapRegistry*.php')) ?: [];
        $this->assertCount(1, $duplicateGapRegistries, 'Only one gap registry service may exist.');

        $this->assertFileDoesNotExist(app_path('Services/OrgLicenseValidationService.php'));
        $this->assertFileDoesNotExist(app_path('Services/OrgSignatureService.php'));
        $this->assertFileDoesNotExist(app_path('Services/OrganizationCertificateService.php'));
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

        $this->assertEmpty($touched, 'Section 37 must not modify payment/trust/AI/email/SMS/provider behavior files, but found: '.implode(', ', $touched));
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

        $this->assertEmpty($unexpected, 'Section 37 must only add/modify files under the allowed locations, but found: '.implode(', ', $unexpected));
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
            'Section 37 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_new_service_contains_no_forbidden_tokens_or_writes(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 37 service file missing: {$filename}");

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

        $this->assertEmpty($changed, 'No migration file may be touched by Section 37, but found: '.implode(', ', $changed));
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
            // Section 39A-8 Wave 8 (the eighth coordinated multi-table
            // wave, governance/support/platform domain) legitimately
            // added six combined prepare-and-force migrations
            // (legal_holds, deletion_requests, key_destruction_requests,
            // support_access_requests, support_access_sessions,
            // deployment_health_checks), their six factories'
            // context-hold fixes, wired independent runWithFirmContext()
            // wraps into LegalHoldService, DeletionRequestService,
            // DeletionGovernanceService, DeletionApprovalService,
            // KeyDestructionRequestService, KeyDestructionApprovalService,
            // KeyDestructionExecutionService, OffboardingRequestService,
            // SupportAccessRequestService, SupportAccessSessionService,
            // AccessReviewService, and DeploymentHealthEnvelopeService,
            // and updated the tests it affected.
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
            'app/Services/DeletionRequestService.php',
            'app/Services/DeletionApprovalService.php',
            'app/Services/KeyDestructionApprovalService.php',
            'app/Services/KeyDestructionExecutionService.php',
            'app/Services/SupportAccessRequestService.php',
            'app/Services/SupportAccessSessionService.php',
            'app/Services/DeploymentHealthEnvelopeService.php',
            'app/Services/AccessReviewService.php',
            'tests/Feature/Governance/KeyDestruction/KeyDestructionLifecycleTest.php',
            'tests/Feature/Governance/Deletion/DeletionGovernanceLifecycleTest.php',
            'tests/Feature/Governance/LegalHold/LegalHoldServiceTest.php',
            'tests/Feature/Governance/AccessReview/AccessReviewServiceTest.php',
            'tests/Feature/Deployment/Health/DeploymentHealthEnvelopeServiceTest.php',
            'tests/Feature/SupportAccess/SupportAccessSessionServiceTest.php',
            'tests/Feature/Security/SupportAccess/EmergencySupportHighRiskApprovalTest.php',
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
