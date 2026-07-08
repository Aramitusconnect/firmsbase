<?php

namespace Tests\Feature\Governance\MarketReadyValueMultipliers;

use Tests\TestCase;

/**
 * MarketReadyFirewallTest — proves Section 30 stayed within its
 * declared implementation boundary: no migrations, no new tables, no
 * UI/routes/controllers, no CI/workflow files, composer.json
 * untouched, no protected file was modified, and no real OCR/image-
 * processing/PDF-conversion/SMS/WhatsApp/payment-provider/trust-
 * workflow code was introduced by any new Section 30 service.
 */
class MarketReadyFirewallTest extends TestCase
{
    private const NEW_SERVICE_FILES = [
        'MobilePortalCoverageMappingService.php',
        'FirmCommandCenterAggregationService.php',
        'TemplatePackCoverageMappingService.php',
        'TrustDependentPackGatingMappingService.php',
    ];

    private const FORBIDDEN_TOKENS = [
        'DB::statement', 'DB::unprepared', 'Schema::create', 'Schema::table', 'Schema::drop',
        'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'proc_open(', 'popen(', 'passthru(', 'exec(', 'shell_exec(', 'system(',
        'Symfony\\Component\\Process', 'Process::', 'mkdir(', 'Aws\\', 'Docker', 'ssh2_connect', 'phpseclib',
        'Tesseract', 'Imagick', 'imagecrop', 'imagecreatefrom', 'Ghostscript', 'TCPDF', 'Dompdf',
        'Twilio', 'Vonage', 'MessageBird', 'WhatsApp SDK',
        'Stripe\\', 'STRIPE_', 'dns_get_record', 'gethostbyname', 'checkdnsrr',
        'Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources',
        'Mail::', 'Notification::send',
    ];

    private const PROTECTED_FILES = [
        'app/Services/MobilePortalReadinessService.php',
        'app/Services/TrustPilotExitCriteriaService.php',
        'app/Services/TrustEligibilityService.php',
        'app/Services/DocumentSecurityService.php',
        'app/Services/DocumentUploadPolicyService.php',
        'app/Services/TemplatePackCommercialService.php',
        'app/Services/TemplatePackInstallationService.php',
        'app/Services/MatterReadinessService.php',
        'app/Services/ReadinessScorecardRegistry.php',
        'app/Services/LegalSpecialistBoundaryPolicyService.php',
        'composer.json',
    ];

    public function test_no_new_migration_files_were_added(): void
    {
        $changed = $this->changedOrUntrackedPaths('database/migrations');

        $this->assertEmpty(
            $changed,
            'Section 30 must add no migrations, but found changed/untracked migration files: '.implode(', ', $changed)
        );
    }

    public function test_no_new_tables_or_schema_files_were_added(): void
    {
        $this->assertEmpty(glob(database_path('schema/*.sql')) ?: []);
        $this->assertEmpty($this->changedOrUntrackedPaths('database/migrations'));
    }

    public function test_no_ui_routes_controllers_filament_blade_or_livewire_were_added(): void
    {
        $markers = self::NEW_SERVICE_FILES;

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
                    $marker = str_replace('.php', '', $marker);
                    $this->assertStringNotContainsString(
                        $marker,
                        $contents,
                        "Section 30 must introduce no UI/route surface, but found '{$marker}' referenced in {$file->getPathname()}"
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
            'Section 30 must add no CI/workflow files, but found changed/untracked .github files: '.implode(', ', $changed)
        );
    }

    public function test_composer_json_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('.');

        $this->assertNotContains('composer.json', $changed, 'Section 30 must not modify composer.json.');
    }

    public function test_protected_files_were_not_modified(): void
    {
        $changedRepoWide = $this->changedOrUntrackedPaths('.');

        $touched = array_values(array_intersect(self::PROTECTED_FILES, $changedRepoWide));

        $this->assertEmpty($touched, 'Section 30 must not modify protected files, but found changes to: '.implode(', ', $touched));
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
            'Section 30 must not modify existing functional test files outside the governance-mapping test tree, but found: '.implode(', ', $changedTestFiles)
        );
    }

    public function test_no_forbidden_scanner_ocr_pdf_sms_whatsapp_payment_or_process_token_appears_in_any_new_service(): void
    {
        $violations = [];

        foreach (self::NEW_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path, "Expected Section 30 service file missing: {$filename}");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$filename} contains forbidden token: {$token}";
                }
            }
        }

        $valueObjectPath = app_path('ValueObjects/CommandCenterSnapshot.php');
        $this->assertFileExists($valueObjectPath, 'Expected Section 30 value object missing: CommandCenterSnapshot.php');
        $source = $this->stripComments(file_get_contents($valueObjectPath));

        foreach (self::FORBIDDEN_TOKENS as $token) {
            if (str_contains($source, $token)) {
                $violations[] = "CommandCenterSnapshot.php contains forbidden token: {$token}";
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_command_center_aggregation_service_performs_no_write_operations(): void
    {
        $source = $this->stripComments(file_get_contents(app_path('Services/FirmCommandCenterAggregationService.php')));

        foreach (['::create(', '::update(', '::delete(', '->save(', '->update(', '->delete('] as $writeToken) {
            $this->assertStringNotContainsString(
                $writeToken,
                $source,
                "FirmCommandCenterAggregationService must be read-only, but found write token: {$writeToken}"
            );
        }
    }

    public function test_no_template_pack_seed_data_or_trust_workflow_was_created(): void
    {
        // Section 39E (a later, distinct security-remediation branch)
        // guarded DatabaseSeeder.php's existing default user to
        // local/testing only — it created no template-pack seed data.
        $changed = array_values(array_filter(
            $this->changedOrUntrackedPaths('database/seeders'),
            fn (string $path) => $path !== 'database/seeders/DatabaseSeeder.php',
        ));

        $this->assertEmpty(
            $changed,
            'Section 30 must not create template-pack seed data, but found changed/untracked seeder files: '.implode(', ', $changed)
        );
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
