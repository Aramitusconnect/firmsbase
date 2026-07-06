<?php

namespace Tests\Feature\Governance\CrossCutting;

use Tests\TestCase;

/**
 * CrossCuttingFirewallTest — proves the cross-cutting security/
 * compliance/governance/accessibility mapping package stayed within
 * its declared implementation boundary: no migrations, no UI/routes/
 * controllers, no real network/process/storage/provider execution in
 * any new mapping service.
 */
class CrossCuttingFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'SecurityBaselineMappingService.php',
        'ComplianceReviewGateMappingService.php',
        'AccessibilityCoverageMappingService.php',
        'ClientPortalAccessibilityReadinessService.php',
        'ComplianceGapRegistryService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        "file_get_contents('http", 'file_get_contents("http',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Process::', 'CREATE DATABASE', 'mkdir(',
        'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib', 'Terraform', 'Kubernetes', 'kubectl',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'ClamAV', 'Segment::', 'Mixpanel',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedMigrationPaths();

        $this->assertEmpty(
            $changed,
            'The cross-cutting package must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_forbidden_execution_or_network_token_appears_in_any_new_mapping_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected cross-cutting service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_route_controller_filament_blade_or_livewire_file_was_added(): void
    {
        $markers = [
            'SecurityBaselineMappingService', 'ComplianceReviewGateMappingService',
            'AccessibilityCoverageMappingService', 'ClientPortalAccessibilityReadinessService',
            'ComplianceGapRegistryService', 'GovernanceMappingResult', 'GapRegisterItem',
        ];

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
                        "The cross-cutting package must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
                    );
                }
            }
        }

        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));
    }

    public function test_no_real_scanner_or_provider_call_in_any_new_mapping_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $source = $this->stripComments(file_get_contents(app_path("Services/{$filename}")));

            foreach (['new FakeVirusScanner()', 'implements VirusScanner', 'app(FakeAiProviderAdapter', 'new FakeAiProviderAdapter'] as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} unexpectedly instantiates/implements a scanner or provider adapter directly: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    /**
     * Mirrors the established convention (WebhookNoBusinessWorkflowWiringTest)
     * of proving a directory was untouched via git's changed/untracked
     * file list, scoped to database/migrations.
     *
     * @return array<int, string>
     */
    private function changedOrUntrackedMigrationPaths(): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- database/migrations'
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
