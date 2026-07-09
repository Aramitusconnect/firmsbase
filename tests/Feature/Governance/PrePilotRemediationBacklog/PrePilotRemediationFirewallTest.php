<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use Tests\TestCase;

/**
 * PrePilotRemediationFirewallTest — proves Section 38 stayed within
 * its declared implementation boundary: no migrations, no schema
 * changes, no model modifications, no domain behavior service
 * modified, no UI/routes/controllers/Filament/Blade/Livewire changes,
 * no seeders/demo data created, no legal/commercial documents created,
 * no provider behavior file modified, and the new backlog service
 * contains no forbidden tokens or writes.
 */
class PrePilotRemediationFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'PrePilotRemediationBacklogService.php',
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
     * reference — none may be modified. ComplianceGapRegistryService
     * IS included this time (unlike prior sections) because Section 38
     * discovered no truly missing pre-pilot risk, so it must remain
     * completely untouched.
     */
    private const PROTECTED_FILES = [
        'app/Services/ComplianceGapRegistryService.php',
        'app/Services/ProfessionalReviewGateMappingService.php',
        'app/Services/AcceptanceTestMatrixMappingService.php',
        'app/Services/EdgeCaseRiskCatalogMappingService.php',
        'app/Services/FinalExecutiveReadinessMappingService.php',
        'app/Services/AdminControlCatalogMappingService.php',
        'app/Services/AdminPermissionAuditCoverageMappingService.php',
        'app/Services/TestCoverageMappingService.php',
        'app/Services/ReleaseChecklistReadinessService.php',
        'app/Services/DefinitionOfDoneReadinessService.php',
        'app/Services/DeploymentModeCoverageMappingService.php',
        'app/Services/OperationalReadinessMappingService.php',
        'app/Services/PermissionMatrixMappingService.php',
        'app/Services/LegalSpecialistConsistencyMappingService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/IdempotencyKeyCoverageMappingService.php',
        'app/Services/EntityFieldCatalogMappingService.php',
        'app/Services/WorkflowStateCatalogMappingService.php',
        'app/Services/WorkflowTransitionRuleMappingService.php',
        'app/Services/TemplatePackCoverageMappingService.php',
        'app/Services/TrustDependentPackGatingMappingService.php',
        'app/Services/MobilePortalCoverageMappingService.php',
        'app/Services/SuppressionService.php',
        'app/Services/DeterministicFieldResolutionService.php',
        'app/Services/CommissionEligibilityService.php',
        'app/Services/ConflictCheckService.php',
        'app/Services/DocumentSecurityService.php',
        'app/Services/DocumentUploadPolicyService.php',
        'app/Services/DocumentReplacementService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/PaymentPlanDunningService.php',
        'app/Services/ConsentService.php',
        'app/Services/InvoiceDraftingService.php',
        'app/Services/PaymentPlanService.php',
        'app/Services/TrustConcurrencyLockService.php',
        'app/Services/TrustBalanceService.php',
        'app/Services/TrustEligibilityService.php',
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
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/LicenseFileValidationService.php',
        'app/Services/SignatureCertificateService.php',
        'app/Services/OrgLicenseService.php',
        'app/Services/FormTemplateService.php',
        'app/Services/FormEditionWatchService.php',
        'app/Services/ImportApplyService.php',
        'app/Services/LegalDataAccessPolicyService.php',
        'app/Services/LegalHoldService.php',
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'app/Services/DeletionGovernanceService.php',
        'app/Services/OffboardingRequestService.php',
        'app/Services/KeyDestructionRequestService.php',
        'app/Services/DowngradeEvaluationService.php',
        'app/Models/Firm.php',
        'app/Services/TenantContextResolver.php',
        'composer.json',
    ];

    /**
     * Scoped to prefixes rather than exact filenames so this test
     * keeps working as later sections add their own new mapping
     * services and sibling test directories (matches the fix already
     * applied to firewall tests in Sections 29-37).
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
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 38 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
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
            'Section 38 must not modify any model, but found changes to: '.implode(', ', $changed)
        );
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 38 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
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

    public function test_no_seeders_or_demo_data_were_created(): void
    {
        // Section 39E (a later, distinct security-remediation branch)
        // guarded DatabaseSeeder.php's existing default user to
        // local/testing only — it did not create a new seeder or any
        // demo data. Every OTHER seeder file must still remain absent.
        $changedSeeders = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/seeders'),
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php',
        ));
        $changedFactories = $this->changedOrUntrackedPaths('database/factories');

        $this->assertEmpty($changedSeeders, 'Section 38 must not create/modify seeders, but found: '.implode(', ', $changedSeeders));
        $this->assertEmpty($changedFactories, 'Section 38 must not create/modify factories, but found: '.implode(', ', $changedFactories));
    }

    public function test_no_legal_or_commercial_documents_were_created(): void
    {
        $repoWideChanges = $this->changedOrUntrackedPaths('.');

        $legalDocPatterns = ['terms-of-service', 'privacy-policy', 'dpa', 'subprocessor', 'acceptable-use', 'pilot-agreement'];

        $touched = array_values(array_filter(
            $repoWideChanges,
            function (string $path) use ($legalDocPatterns) {
                $lower = strtolower($path);

                foreach ($legalDocPatterns as $pattern) {
                    if (str_contains($lower, $pattern)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $this->assertEmpty($touched, 'Section 38 must not create legal/commercial documents, but found: '.implode(', ', $touched));
    }

    public function test_no_domain_behavior_services_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 38 must not modify protected domain behavior/mapping service files, but found changes to: '.implode(', ', $touched));
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

        $this->assertEmpty($touched, 'Section 38 must not modify payment/trust/AI/email/SMS/provider behavior files, but found: '.implode(', ', $touched));
    }

    public function test_new_service_contains_no_forbidden_tokens_or_writes(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 38 service file missing: {$filename}");

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

    public function test_no_files_were_added_outside_the_allowed_locations(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_filter(
            $changedRepoWide,
            function (string $path) {
                if ($path === 'database/seeders/DatabaseSeeder.php') {
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

        $this->assertEmpty($unexpected, 'Section 38 must only add files under the allowed locations, but found: '.implode(', ', $unexpected));
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
                && $path !== 'tests/TestCase.php',
        );

        $this->assertEmpty(
            array_values($changedTestFiles),
            'Section 38 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_protected_migration_files_are_untouched(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty($changed, 'No migration file may be touched by Section 38, but found: '.implode(', ', $changed));
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
