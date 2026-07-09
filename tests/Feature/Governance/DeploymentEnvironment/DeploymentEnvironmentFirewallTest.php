<?php

namespace Tests\Feature\Governance\DeploymentEnvironment;

use App\Enums\IntegrationType;
use Tests\TestCase;

/**
 * DeploymentEnvironmentFirewallTest — proves Section 29 stayed within
 * its declared implementation boundary: no migrations, no new tables,
 * no UI/routes/controllers, no CI/workflow files, no protected Phase
 * 16 file was modified, no real infrastructure/provider execution in
 * any new mapping service, and no new IntegrationType case was added.
 */
class DeploymentEnvironmentFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'DeploymentModeCoverageMappingService.php',
        'OperationalReadinessMappingService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Terraform', 'Kubernetes', 'kubectl',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send', 'PagerDuty', 'Opsgenie', 'Datadog', 'NewRelic',
    ];

    private const PROTECTED_PHASE_16_FILES = [
        'app/Services/DeploymentModeResolutionService.php',
        'app/Services/DeploymentHealthEnvelopeService.php',
        'app/Services/FleetMigrationOrchestrationService.php',
        'app/Services/VersionSkewPolicyService.php',
        'app/Services/LicenseFileSigningService.php',
        'app/Services/LicenseFileValidationService.php',
        'app/Services/IntegrationDegradationRegistryService.php',
        'app/Services/DedicatedCustomerTypeApprovalService.php',
        'app/Services/TrustIoltaDisableAcknowledgmentService.php',
        'app/Services/DeploymentFeatureFlagAuditService.php',
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'app/Models/DeploymentConfig.php',
        'app/Models/DeploymentHealthCheck.php',
        'app/Models/FleetMigrationRun.php',
        'app/Models/FleetMigrationInstanceStatus.php',
        'app/Models/LicenseFile.php',
        'app/Models/LicenseValidationEvent.php',
        'app/Models/PrivateEnterpriseSettings.php',
        'app/Enums/IntegrationType.php',
        'app/Models/IntegrationDegradationMode.php',
        'app/Services/BackupRestoreTestService.php',
        'app/Models/BackupRestoreTest.php',
        'app/Models/FirmAiProviderKey.php',
        'app/Services/AiProviderKeyService.php',
        'app/Models/TenantEncryptionKey.php',
        'app/Services/EncryptionKeyService.php',
        'app/Services/HighRiskPlatformChangePolicyService.php',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 29 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_new_tables_or_schema_files_were_added(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_were_added(): void
    {
        $markers = ['DeploymentModeCoverageMappingService', 'OperationalReadinessMappingService'];

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
                        "Section 29 must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
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
            'Section 29 must add no CI/workflow files, but found changed/untracked .github files: '.implode(', ', $changed)
        );
    }

    public function test_protected_phase_16_files_were_not_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_PHASE_16_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 29 must not modify protected Phase 16/secret/backup files, but found changes to: '.implode(', ', $touched));
    }

    public function test_no_forbidden_infrastructure_or_network_token_appears_in_any_new_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 29 service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_new_integration_type_enum_case_was_added_by_this_section(): void
    {
        $values = array_map(fn ($case) => $case->value, IntegrationType::cases());
        sort($values);

        $this->assertSame(['email_provider', 'stripe', 'telemetry', 'virus_scanning'], $values);

        $changed = $this->changedOrUntrackedPaths('.');
        $this->assertNotContains('app/Enums/IntegrationType.php', $changed, 'Section 29 must not modify IntegrationType.php.');
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
