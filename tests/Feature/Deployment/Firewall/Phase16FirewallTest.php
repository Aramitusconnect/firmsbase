<?php

namespace Tests\Feature\Deployment\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Implementation-boundary firewall: proves Phase 16's simulated-only
 * scope was respected across every new service file — no real AWS/
 * Docker/SSH/Terraform/Kubernetes/database-provisioning/storage-
 * provisioning/DNS/Stripe/email/virus-scanner/telemetry/provider SDK
 * call, no shell/process execution, no outbound network call — and
 * that no UI surface (route/controller/Filament/Blade/Livewire) was
 * introduced anywhere under the Phase 16 paths.
 */
class Phase16FirewallTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_TOKENS = [
        'Artisan::call',
        'exec(',
        'shell_exec(',
        'proc_open(',
        'popen(',
        'passthru(',
        'system(',
        'Process::',
        'Http::',
        'curl_init',
        'fsockopen',
        'GuzzleHttp',
        'CREATE DATABASE',
        'mkdir(',
        'Aws\\',
        'Docker',
        'ssh2_connect',
        'phpseclib',
        'Terraform',
        'Kubernetes',
        'kubectl',
        'Stripe\\',
        'STRIPE_',
        'dns_get_record',
        'gethostbyname',
        'ClamAV',
        'Segment::',
        'Mixpanel',
    ];

    private const SERVICE_FILES = [
        'DeploymentModeResolutionService.php',
        'LicenseFileSigningService.php',
        'LicenseFileValidationService.php',
        'FleetMigrationOrchestrationService.php',
        'VersionSkewPolicyService.php',
        'DeploymentHealthEnvelopeService.php',
        'IntegrationDegradationRegistryService.php',
        'DedicatedCustomerTypeApprovalService.php',
        'TrustIoltaDisableAcknowledgmentService.php',
        'DeploymentFeatureFlagAuditService.php',
        'LegalSpecialistBoundaryPolicyService.php',
    ];

    private const VALUE_OBJECT_FILES = [
        'SignedLicensePayload.php',
        'LicenseValidationOutcome.php',
        'FleetMigrationRunSummary.php',
        'VersionSkewCheckResult.php',
        'DeploymentHealthEnvelope.php',
    ];

    public function test_no_forbidden_infrastructure_or_network_token_appears_in_any_phase_16_service(): void
    {
        foreach (self::SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Phase 16 service file missing: {$filename}");

            // Comments/docblocks may legitimately explain what NOT to
            // do (e.g. "never calls Artisan::call()") without that
            // prose counting as the forbidden pattern itself — only
            // executable code is checked here.
            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, "{$filename} must not contain forbidden token: {$token}");
            }
        }
    }

    public function test_no_forbidden_infrastructure_or_network_token_appears_in_any_phase_16_value_object(): void
    {
        foreach (self::VALUE_OBJECT_FILES as $filename) {
            $path = app_path("ValueObjects/{$filename}");
            $this->assertFileExists($path, "Expected Phase 16 value object file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, "{$filename} must not contain forbidden token: {$token}");
            }
        }
    }

    /**
     * FIRMSVAULT-ADMIN-CONTROL-CENTER-PHASE-4 UPDATE (Operations): this
     * phase legitimately, deliberately builds a real, reviewed
     * Deployments admin surface over `DeploymentConfig`/
     * `FleetMigrationRun`/`FleetMigrationInstanceStatus` — the exact
     * "fleet of Dedicated/PrivateEnterprise firm deployments" backend
     * Phase 16 itself built (simulated-only, no real infrastructure
     * action, per that phase's own project rule, which this admin
     * surface does not change). This does not weaken Phase 16's actual
     * invariant (no real AWS/Docker/SSH/Terraform/Kubernetes/database-
     * or storage-provisioning/DNS call, no shell/process execution —
     * still enforced unconditionally by every other test in this class)
     * — it narrows the separate "no UI surface" check from "zero
     * Phase-16-domain references anywhere" to "zero EXCEPT this
     * phase's own reviewed, allowlisted files," mirroring every prior
     * cascade-allowlist precedent in this codebase (see e.g.
     * FirmIntegrationSuperAdminBoundaryStructuralTest's own six-cascade
     * history).
     */
    private const PHASE_4_OPERATIONS_ALLOWED_BASENAMES = [
        'PlatformDeploymentConfigsPage.php',
        'PlatformFleetMigrationRunDetailPage.php',
        'PlatformFleetMigrationRunResource.php',
        'ListPlatformFleetMigrationRuns.php',
        'ApplyFleetMigrationInstanceAction.php',
        'BeginFleetMigrationRunAction.php',
        'CompleteFleetMigrationRunAction.php',
        'CreateFleetMigrationRunAction.php',
        'RollbackFleetMigrationRunAction.php',
        'RunHealthChecksNowAction.php',
    ];

    public function test_no_route_controller_filament_blade_or_livewire_file_was_added_for_phase_16(): void
    {
        $phase16Markers = ['DeploymentConfig', 'FleetMigration', 'LicenseFile', 'IntegrationDegradation', 'PrivateEnterpriseSettings'];

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

                if (in_array(basename($file->getPathname()), self::PHASE_4_OPERATIONS_ALLOWED_BASENAMES, true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                foreach ($phase16Markers as $marker) {
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "Phase 16 must not introduce any UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }
    }

    public function test_no_migration_uses_raw_shell_or_process_execution(): void
    {
        $migrationsDir = database_path('migrations');
        $phase16Migrations = glob($migrationsDir.'/2026_07_25_9000*.php');

        $this->assertNotEmpty($phase16Migrations, 'Expected Phase 16 migrations to exist.');

        foreach ($phase16Migrations as $path) {
            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, basename($path)." must not contain forbidden token: {$token}");
            }
        }
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
