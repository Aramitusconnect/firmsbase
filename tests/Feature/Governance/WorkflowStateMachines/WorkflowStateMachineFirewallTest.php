<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use Tests\TestCase;

/**
 * WorkflowStateMachineFirewallTest — proves Section 33 stayed within
 * its declared implementation boundary: no migrations, no schema
 * changes, no UI/routes/controllers, no workflow enum/model/behavior
 * service modified, no payment/trust/AI/deployment behavior file
 * modified, and the two new mapping services contain no Schema::/DB::
 * writes or network/provider/process/shell execution.
 */
class WorkflowStateMachineFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'WorkflowStateCatalogMappingService.php',
        'WorkflowTransitionRuleMappingService.php',
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
     * The 14 catalog workflow status enums — none may be modified.
     */
    private const PROTECTED_ENUMS = [
        'app/Enums/LicenseStatus.php',
        'app/Enums/FirmLeadStatus.php',
        'app/Enums/MatterStatus.php',
        'app/Enums/DocumentRequestItemStatus.php',
        'app/Enums/TaskStatus.php',
        'app/Enums/InvoiceStatus.php',
        'app/Enums/PaymentPlanStatus.php',
        'app/Enums/PaymentPlanInstallmentStatus.php',
        'app/Enums/PaymentStatus.php',
        'app/Enums/TrustTransferRequestStatus.php',
        'app/Enums/TrustRefundRequestStatus.php',
        'app/Enums/AiApprovalRequestStatus.php',
        'app/Enums/ImportBatchStatus.php',
        'app/Enums/SignatureRequestStatus.php',
        'app/Enums/FleetMigrationRunStatus.php',
    ];

    /**
     * Workflow behavior services and every Section 25-32 mapping/
     * readiness service — none may be modified by Section 33.
     * ComplianceGapRegistryService is deliberately EXCLUDED: it is the
     * one file every section is allowed to conditionally modify when
     * new AWS evidence confirms a real gap.
     */
    private const PROTECTED_FILES = [
        'app/Models/FirmLicense.php',
        'app/Models/FirmLead.php',
        'app/Models/Matter.php',
        'app/Models/DocumentRequestItem.php',
        'app/Models/Task.php',
        'app/Models/Invoice.php',
        'app/Models/PaymentPlan.php',
        'app/Models/PaymentPlanInstallment.php',
        'app/Models/Payment.php',
        'app/Models/TrustTransferRequest.php',
        'app/Models/TrustRefundRequest.php',
        'app/Models/AiApprovalRequest.php',
        'app/Models/ImportBatch.php',
        'app/Models/SignatureRequest.php',
        'app/Models/FleetMigrationRun.php',
        'app/Services/MatterOpeningService.php',
        'app/Services/TemplatePackInstallationService.php',
        'app/Services/PaymentPlanDunningService.php',
        'app/Services/ConsentService.php',
        'app/Services/SignatureCertificateService.php',
        'app/Services/TrustTransferRequestService.php',
        'app/Services/TrustRefundRequestService.php',
        'app/Services/TrustAccessPolicyService.php',
        'app/Services/TrustConcurrencyLockService.php',
        'app/Services/TrustBalanceService.php',
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/PaymentClassificationService.php',
        'app/Services/SecurityBaselineMappingService.php',
        'app/Services/ComplianceReviewGateMappingService.php',
        'app/Services/AccessibilityCoverageMappingService.php',
        'app/Services/ClientPortalAccessibilityReadinessService.php',
        'app/Services/DataModelContractMappingService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'app/Services/IdempotencyKeyCoverageMappingService.php',
        'app/Services/PermissionMatrixMappingService.php',
        'app/Services/EmergencyAccessGovernanceGapService.php',
        'app/Services/LegalSpecialistConsistencyMappingService.php',
        'app/Services/TestCoverageMappingService.php',
        'app/Services/ReleaseChecklistReadinessService.php',
        'app/Services/DefinitionOfDoneReadinessService.php',
        'app/Services/DeploymentModeCoverageMappingService.php',
        'app/Services/OperationalReadinessMappingService.php',
        'app/Services/MobilePortalCoverageMappingService.php',
        'app/Services/FirmCommandCenterAggregationService.php',
        'app/Services/TemplatePackCoverageMappingService.php',
        'app/Services/TrustDependentPackGatingMappingService.php',
        'app/Services/FinalExecutiveReadinessMappingService.php',
        'app/Services/EntityFieldCatalogMappingService.php',
        'app/Models/Firm.php',
        'app/Services/EntitlementService.php',
        'app/Services/TenantContextResolver.php',
        'app/Services/LicenseFileValidationService.php',
        'app/Services/TrustPilotExitCriteriaService.php',
        'app/Services/TrustEligibilityService.php',
        'composer.json',
    ];

    /**
     * Scoped to prefixes rather than exact filenames so this test
     * keeps working as later sections add their own new mapping
     * services and sibling test directories (matches the fix already
     * applied to FinalExecutiveRecommendationFirewallTest/
     * EntityFieldCatalogFirewallTest in Sections 31/32, itself
     * following the QualityGateFirewallTest precedent from Section 29).
     */
    private const ALLOWED_NEW_FILE_PREFIXES = [
        'app/Services/',
        'tests/Feature/Governance/',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 33 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_schema_files_changed(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_were_added(): void
    {
        foreach (['routes', 'app/Http/Controllers', 'app/Filament', 'resources/views', 'app/Livewire'] as $relativeDir) {
            $changed = $this->changedOrUntrackedPaths($relativeDir);

            $this->assertEmpty($changed, "Section 33 must introduce no UI/route surface, but found changes under {$relativeDir}: ".implode(', ', $changed));
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_workflow_enum_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_ENUMS, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 33 must not modify any workflow status enum, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_workflow_or_section_25_to_32_service_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 33 must not modify protected workflow/behavior/mapping service files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_payment_trust_ai_or_deployment_behavior_files_were_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $behaviorFilePatterns = [
            'PaymentApplicationService', 'PaymentPlanService', 'PaymentPlanInstallmentService',
            'TrustDepositService', 'TrustReconciliationService', 'TrustTransferRequestService', 'TrustRefundRequestService', 'TrustHighRiskAdjustmentService', 'TrustLedgerEntryReversalService', 'TrustChargebackService',
            'AiProviderKeyService', 'AiApprovalWorkflowService', 'AiModeResolutionService',
            'DeploymentHealthEnvelopeService', 'FleetMigrationOrchestrationService', 'LicenseFileSigningService',
            'InvoiceDraftingService', 'DocumentRequestService', 'TaskDependencyService', 'TaskService',
            'LeadConversionService', 'MatterOpeningService', 'ImportApplyService', 'ImportPreviewService',
            'ImportBatchService', 'ImportRollbackService', 'SignatureCertificateService',
            'SignatureRecipientWorkflowService', 'SignatureRequestWorkflowService', 'SignatureRequestAggregationService',
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

        $this->assertEmpty($touched, 'Section 33 must not modify payment/trust/AI/deployment/workflow behavior files, but found: '.implode(', ', $touched));
    }

    public function test_no_files_were_added_outside_the_allowed_locations(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $unexpected = array_values(array_filter(
            $changedRepoWide,
            function (string $path) {
                if ($path === 'app/Services/ComplianceGapRegistryService.php') {
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

        $this->assertEmpty($unexpected, 'Section 33 must only add/modify files under the allowed locations, but found: '.implode(', ', $unexpected));
    }

    public function test_no_unexpected_functional_test_file_was_modified(): void
    {
        $changedTestFiles = array_filter(
            $this->changedOrUntrackedPaths('tests'),
            fn (string $path) => ! str_starts_with($path, 'tests/Feature/Governance/'),
        );

        $this->assertEmpty(
            array_values($changedTestFiles),
            'Section 33 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_new_services_contain_no_db_writes_schema_calls_or_network_provider_process_shell_execution(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 33 service file missing: {$filename}");

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

        $this->assertEmpty($changed, 'No migration file may be touched by Section 33, but found: '.implode(', ', $changed));
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
