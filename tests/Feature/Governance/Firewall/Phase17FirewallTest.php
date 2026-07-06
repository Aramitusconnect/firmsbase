<?php

namespace Tests\Feature\Governance\Firewall;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Implementation-boundary firewall: proves Phase 17's governance-
 * foundation-only scope was respected across every new service/value-
 * object file — no real AWS/Docker/SSH/Terraform/Kubernetes/DNS/SDK
 * call, no shell/process execution, no outbound network call — and
 * that no UI surface (route/controller/Filament/Blade/Livewire) was
 * introduced anywhere under the Phase 17 paths.
 */
class Phase17FirewallTest extends TestCase
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
        'DROP DATABASE',
        'mkdir(',
        'unlink(',
        'Storage::delete',
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
        'ZipArchive',
        'Segment::',
        'Mixpanel',
    ];

    private const SERVICE_FILES = [
        'RetentionPolicyService.php',
        'LegalHoldService.php',
        'OffboardingRequestService.php',
        'OffboardingExportService.php',
        'KeyDestructionRequestService.php',
        'KeyDestructionApprovalService.php',
        'KeyDestructionExecutionService.php',
        'DeletionRequestService.php',
        'DeletionApprovalService.php',
        'DeletionGovernanceService.php',
        'AccessReviewService.php',
        'VendorRegisterService.php',
        'SubprocessorDisclosureService.php',
        'DataProcessingRecordService.php',
        'AuditPreservationPolicyService.php',
    ];

    private const VALUE_OBJECT_FILES = [
        'RetentionClearanceResult.php',
        'LegalHoldCheckResult.php',
        'OffboardingReadinessResult.php',
        'KeyDestructionClearanceResult.php',
        'DeletionClearanceResult.php',
        'AccessReviewSummary.php',
    ];

    public function test_no_forbidden_infrastructure_or_network_token_appears_in_any_phase_17_service(): void
    {
        foreach (self::SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Phase 17 service file missing: {$filename}");

            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, "{$filename} must not contain forbidden token: {$token}");
            }
        }
    }

    public function test_no_forbidden_infrastructure_or_network_token_appears_in_any_phase_17_value_object(): void
    {
        foreach (self::VALUE_OBJECT_FILES as $filename) {
            $path = app_path("ValueObjects/{$filename}");
            $this->assertFileExists($path, "Expected Phase 17 value object file missing: {$filename}");

            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, "{$filename} must not contain forbidden token: {$token}");
            }
        }
    }

    public function test_encryption_key_service_destroy_method_makes_no_real_kms_or_cloud_call(): void
    {
        $source = file_get_contents(app_path('Services/EncryptionKeyService.php'));

        foreach (['Http::', 'curl_init', 'GuzzleHttp', 'Aws\\', 'kms', 'Kms'] as $token) {
            $this->assertStringNotContainsString($token, $source, "EncryptionKeyService.php must not contain: {$token}");
        }
    }

    public function test_no_route_controller_filament_blade_or_livewire_file_was_added_for_phase_17(): void
    {
        $phase17Markers = ['RetentionPolicy', 'LegalHold', 'OffboardingRequest', 'KeyDestructionRequest',
            'DeletionRequest', 'AccessReview', 'VendorRegister', 'Subprocessor', 'DataProcessingRecord'];

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

                foreach ($phase17Markers as $marker) {
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "Phase 17 must not introduce any UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }
    }

    public function test_no_migration_uses_raw_shell_or_process_execution(): void
    {
        $migrationsDir = database_path('migrations');
        $phase17Migrations = glob($migrationsDir.'/2026_07_28_9000*.php');

        $this->assertNotEmpty($phase17Migrations, 'Expected Phase 17 migrations to exist.');

        foreach ($phase17Migrations as $path) {
            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_TOKENS as $token) {
                $this->assertStringNotContainsString($token, $source, basename($path)." must not contain forbidden token: {$token}");
            }
        }
    }

    public function test_export_offboarding_never_writes_a_real_file(): void
    {
        $source = file_get_contents(app_path('Services/OffboardingExportService.php'));

        foreach (['file_put_contents', 'fopen(', 'Storage::put', 'ZipArchive'] as $token) {
            $this->assertStringNotContainsString($token, $source, "OffboardingExportService.php must not contain: {$token}");
        }
    }
}
