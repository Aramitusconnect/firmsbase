<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * FirmIntegrationSuperAdminBoundaryStructuralTest — Checkpoint 10
 * (frozen-design-post-security-review.md §11 "SuperAdmin boundary" hard
 * constraint; §9's credential-DTO-only-binding structural rule). Two
 * independent, purely structural (find/reflection-based, never a
 * runtime request) checks:
 *
 * 1. No file under `app/Filament/Resources`, `app/Filament/Pages`, or
 *    `app/Filament/Widgets` (the `admin`-panel namespace) may exist or
 *    reference any Integration-domain class — the hard SuperAdmin
 *    boundary this checkpoint's diff-review.md confirmed via
 *    `find ... -> "No such file or directory"`.
 * 2. No file under `app/Filament/Firm/**` may contain the literal
 *    string `IntegrationCredential::class` outside
 *    `getMaskedMetadata()`-adjacent DTO-construction code — mirrors the
 *    proven `str_contains()`-scan convention from
 *    `IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest`.
 */
final class FirmIntegrationSuperAdminBoundaryStructuralTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // 1. Hard SuperAdmin boundary: zero Integration-domain classes
    //    under the admin-panel namespace directories.
    // ------------------------------------------------------------

    public function test_app_filament_resources_directory_does_not_exist_or_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Resources');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1); // the directory not existing at all is itself compliant

            return;
        }

        $this->assertNoIntegrationDomainReferenceUnder($dir);
    }

    public function test_app_filament_pages_directory_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Pages');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNoIntegrationDomainReferenceUnder($dir);
    }

    public function test_app_filament_widgets_directory_contains_no_integration_domain_class(): void
    {
        $dir = base_path('app/Filament/Widgets');

        if (! is_dir($dir)) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertNoIntegrationDomainReferenceUnder($dir);
    }

    public function test_no_platform_integration_health_access_policy_service_shaped_class_exists_anywhere(): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            if (str_contains(basename($file), 'PlatformIntegrationHealthAccessPolicyService')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, 'No PlatformIntegrationHealthAccessPolicyService-shaped class may exist yet (Checkpoint 11 scope): '.implode(', ', $violations));
    }

    public function test_every_new_checkpoint_10_filament_class_lives_exclusively_under_app_filament_firm(): void
    {
        $filamentDir = base_path('app/Filament');
        $this->assertTrue(is_dir($filamentDir), 'app/Filament must exist.');

        $nonFirmFilamentFiles = [];

        foreach ($this->phpFilesUnder($filamentDir) as $file) {
            $relative = str_replace($filamentDir.DIRECTORY_SEPARATOR, '', $file);

            if (! str_starts_with($relative, 'Firm'.DIRECTORY_SEPARATOR)) {
                $nonFirmFilamentFiles[] = $relative;
            }
        }

        $this->assertEmpty(
            $nonFirmFilamentFiles,
            'Every file under app/Filament must live under app/Filament/Firm — found outside it: '.implode(', ', $nonFirmFilamentFiles)
        );
    }

    private function assertNoIntegrationDomainReferenceUnder(string $dir): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder($dir) as $file) {
            $basename = basename($file);

            if (str_contains($basename, 'Integration') || str_contains($basename, 'FirmIntegration')) {
                $violations[] = $file;

                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && (str_contains($source, 'App\\Integrations\\') || str_contains($source, 'FirmIntegrationResource'))) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty($violations, "No Integration-domain class/reference may exist under {$dir}: ".implode(', ', $violations));
    }

    // ------------------------------------------------------------
    // 2. Credential-DTO-only-binding structural check (frozen design
    //    §9) — mirrors IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest's
    //    str_contains() scan convention.
    // ------------------------------------------------------------

    public function test_no_file_under_app_filament_firm_contains_integration_credential_class_outside_the_one_allowed_dto_construction_site(): void
    {
        // The ONE allowed reference: ViewFirmIntegration.php's
        // credential_summary TextEntry closure, which uses
        // IntegrationCredential ONLY to query rows and immediately map
        // each one through IntegrationCredentialService::getMaskedMetadata()
        // -> FirmIntegrationCredentialSummary::fromMaskedMetadata() — never
        // binding the raw model to any Field/Column/Entry directly.
        $allowedFiles = [
            'ViewFirmIntegration.php',
        ];

        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app/Filament/Firm')) as $file) {
            if (in_array(basename($file), $allowedFiles, true)) {
                continue;
            }

            $source = file_get_contents($file);

            if ($source !== false && str_contains($source, 'IntegrationCredential::class')) {
                $violations[] = $file;
            }
        }

        $this->assertEmpty(
            $violations,
            'IntegrationCredential::class must never appear under app/Filament/Firm outside ViewFirmIntegration.php\'s masked-metadata DTO construction: '.implode(', ', $violations)
        );
    }

    public function test_no_file_under_app_filament_firm_binds_an_integration_credential_query_result_to_a_field_column_or_entry_directly(): void
    {
        // Defense-in-depth beyond the class-reference scan above: even
        // in the one allowed file, IntegrationCredential must only ever
        // flow through ::query()/->get()/->map() into the masked-DTO
        // pipeline (getMaskedMetadata()/fromMaskedMetadata()) — never
        // handed directly to a Field/Column/Entry constructor or
        // returned bare from a ->state() closure.
        $viewFirmIntegrationPath = app_path('Filament/Firm/Resources/FirmIntegrationResource/Pages/ViewFirmIntegration.php');

        $this->assertFileExists($viewFirmIntegrationPath);

        $source = file_get_contents($viewFirmIntegrationPath);
        $this->assertIsString($source);

        // The masked-metadata pipeline is the ONLY legitimate use.
        $this->assertStringContainsString('getMaskedMetadata(', $source);
        $this->assertStringContainsString('FirmIntegrationCredentialSummary::fromMaskedMetadata(', $source);

        // No naive direct binding: an IntegrationCredential instance
        // handed straight to make()'s own argument list, e.g.
        // `TextEntry::make($credential)`.
        $this->assertDoesNotMatchRegularExpression(
            '/(TextEntry|TextColumn)::make\(\s*\$credential/',
            $source,
            'No Field/Column/Entry may bind directly to an IntegrationCredential instance.'
        );

        // Every ->map() over the IntegrationCredential query result must
        // route through fromMaskedMetadata() on the same line/statement
        // — confirms the query result is never returned/mapped bare.
        $this->assertMatchesRegularExpression(
            '/->map\(fn \(IntegrationCredential \$credential\) => FirmIntegrationCredentialSummary::fromMaskedMetadata\(/',
            $source,
            'The IntegrationCredential query result must be mapped through FirmIntegrationCredentialSummary::fromMaskedMetadata() on the same map() call.'
        );
    }

    public function test_no_file_under_app_filament_firm_ever_references_the_encrypted_payload_ciphertext_or_webhook_routing_token_columns(): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder(base_path('app/Filament/Firm')) as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            if (str_contains($source, 'encrypted_payload_ciphertext')) {
                $violations[] = "{$file} (encrypted_payload_ciphertext)";
            }

            // webhook_routing_token: the ONE narrow, disclosed exception is
            // ViewFirmIntegration.php's own docblock discussion and its
            // display of enableWebhookRouting()'s plaintext RETURN VALUE
            // (never a re-read of the model attribute) — grep the literal
            // attribute-access shape `->webhook_routing_token` specifically,
            // never merely the substring, so the docblock prose itself
            // (which legitimately discusses the column by name) does not
            // trip this check.
            if (preg_match('/->webhook_routing_token\b/', $source)) {
                $violations[] = "{$file} (->webhook_routing_token direct attribute access)";
            }
        }

        $this->assertEmpty($violations, 'No file under app/Filament/Firm may read these HIDDEN-ONLY/NEVER columns directly: '.implode(', ', $violations));
    }

    /**
     * @return string[]
     */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }
}
