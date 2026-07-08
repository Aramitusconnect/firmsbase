<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use Tests\TestCase;

/**
 * EdgeCaseRiskFirewallTest — proves Section 35 stayed within its
 * declared implementation boundary: no migrations, no schema changes,
 * no model modifications, no domain behavior service modified, no
 * payment/trust/AI/deployment/support/import/deletion/fleet behavior
 * files modified, no UI/routes/controllers/Filament/Blade/Livewire
 * changes, and the new mapping service contains no Schema::/DB::
 * writes or network/provider/process/shell execution.
 */
class EdgeCaseRiskFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'EdgeCaseRiskCatalogMappingService.php',
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
     * Domain behavior services this section may only read/reference in
     * notes — none may be modified. ComplianceGapRegistryService is
     * deliberately EXCLUDED: it is the one file every section is
     * allowed to conditionally modify when new AWS evidence confirms a
     * real gap.
     */
    private const PROTECTED_FILES = [
        'app/Services/SeatEnforcementService.php',
        'app/Services/DowngradeEvaluationService.php',
        'app/Services/ConflictCheckService.php',
        'app/Services/PaymentPlanDunningService.php',
        'app/Services/ConsentService.php',
        'app/Services/DocumentChaseService.php',
        'app/Services/TrustConcurrencyLockService.php',
        'app/Services/TrustBalanceService.php',
        'app/Services/LegalHoldService.php',
        'app/Services/DeletionGovernanceService.php',
        'app/Services/KeyDestructionRequestService.php',
        'app/Services/KeyDestructionApprovalService.php',
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/LicenseFileValidationService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/PaymentApplicationService.php',
        'app/Services/PaymentPlanService.php',
        'app/Services/TrustTransferRequestService.php',
        'app/Services/TrustRefundRequestService.php',
        'app/Services/AiApprovalWorkflowService.php',
        'app/Services/AiModeResolutionService.php',
        'app/Services/PromptInjectionResistanceService.php',
        'app/Services/ImportApplyService.php',
        'app/Services/ImportPreviewService.php',
        'app/Services/ImportBatchService.php',
        'app/Services/ImportRollbackService.php',
        'app/Services/TemplatePackInstallationService.php',
        'app/Services/TemplateUpgradePreviewService.php',
        'app/Services/FormTemplateService.php',
        'app/Services/FormEditionWatchService.php',
        'app/Services/LegalDataAccessPolicyService.php',
        'app/Services/OffboardingRequestService.php',
        'app/Services/PermissionMatrixMappingService.php',
        'app/Services/DeploymentModeCoverageMappingService.php',
        'app/Services/OperationalReadinessMappingService.php',
        'app/Services/FirmCommandCenterAggregationService.php',
        'app/Services/TemplatePackCoverageMappingService.php',
        'app/Services/EntityFieldCatalogMappingService.php',
        'app/Services/WorkflowStateCatalogMappingService.php',
        'app/Services/WorkflowTransitionRuleMappingService.php',
        'app/Services/AdminControlCatalogMappingService.php',
        'app/Services/AdminPermissionAuditCoverageMappingService.php',
        'app/Models/Firm.php',
        'app/Services/EntitlementService.php',
        'app/Services/TenantContextResolver.php',
        'app/Services/TrustPilotExitCriteriaService.php',
        'app/Services/TrustEligibilityService.php',
        'composer.json',
    ];

    /**
     * Scoped to prefixes rather than exact filenames so this test
     * keeps working as later sections add their own new mapping
     * services and sibling test directories (matches the fix already
     * applied to firewall tests in Sections 31/32/33/34, itself
     * following the QualityGateFirewallTest precedent from Section 29).
     */
    private const ALLOWED_NEW_FILE_PREFIXES = [
        'app/Services/',
        'tests/Feature/Governance/',
        'tests/Feature/Security/',
        'tests/Feature/SupportAccess/',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 35 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
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
            'Section 35 must not modify any model, but found changes to: '.implode(', ', $changed)
        );
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_changes(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 35 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_domain_behavior_services_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 35 must not modify protected domain behavior/mapping service files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_payment_trust_ai_deployment_support_import_deletion_or_fleet_behavior_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $behaviorFilePatterns = [
            'PaymentApplicationService', 'PaymentPlanService', 'PaymentPlanInstallmentService',
            'TrustDepositService', 'TrustLedgerEntryReversalService', 'TrustChargebackService',
            'AiProviderKeyService', 'AiUsageRecorderService', 'AiToolActionRecorderService',
            'LicenseFileSigningService', 'FleetMigrationOrchestrationService', 'DeploymentHealthEnvelopeService',
            'ImportApplyService', 'ImportRollbackService',
            'DeletionGovernanceService', 'KeyDestructionRequestService', 'LegalHoldService',
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

        $this->assertEmpty($touched, 'Section 35 must not modify payment/trust/AI/deployment/support/import/deletion/fleet behavior files, but found: '.implode(', ', $touched));
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

        $this->assertEmpty($unexpected, 'Section 35 must only add/modify files under the allowed locations, but found: '.implode(', ', $unexpected));
    }

    public function test_no_unexpected_functional_test_file_was_modified(): void
    {
        $changedTestFiles = array_filter(
            $this->changedOrUntrackedPaths('tests'),
            fn (string $path) => ! str_starts_with($path, 'tests/Feature/Governance/')
                && ! str_starts_with($path, 'tests/Feature/Security/')
                && ! str_starts_with($path, 'tests/Feature/SupportAccess/'),
        );

        $this->assertEmpty(
            array_values($changedTestFiles),
            'Section 35 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_new_service_contains_no_db_writes_schema_calls_or_network_provider_process_shell_execution(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 35 service file missing: {$filename}");

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

        $this->assertEmpty($changed, 'No migration file may be touched by Section 35, but found: '.implode(', ', $changed));
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
