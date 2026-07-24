<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use Tests\TestCase;

/**
 * PlatformIntegrationAdminNoNetworkCallsTest — Checkpoint 11. Reuses the
 * existing repo-wide network-call scan pattern
 * (tests/Unit/Jobs/NoRealNetworkCallInJobsTest.php /
 * tests/Unit/Integrations/NoRealNetworkCallTest.php's proven forbidden-
 * needle list and comment-stripping discipline, extended here rather
 * than reinvented) against the exact 18 new production files this
 * checkpoint introduces (frozen design §12's "New files" list) —
 * structural confirmation that nothing in the new SuperAdmin oversight
 * surface makes a real HTTP/DNS call. This checkpoint does not even
 * touch provider dispatch directly (§3: disconnect()/webhook-toggle
 * explicitly deferred; only TestProvider-shaped internal logic exists
 * anywhere in this codebase), so this is expected to be a
 * straightforward structural confirmation, not a load-bearing
 * discovery.
 */
final class PlatformIntegrationAdminNoNetworkCallsTest extends TestCase
{
    private const FORBIDDEN_NEEDLES = [
        'Http::',
        'GuzzleHttp',
        'Guzzle',
        'curl_',
        "fopen('http",
        'fopen("http',
        "file_get_contents('http",
        'file_get_contents("http',
        'fsockopen',
        'stream_socket_client',
    ];

    /**
     * The exact 18 new production files from the frozen design §12
     * allowlist — hardcoded rather than directory-walked, so this test
     * is precise about exactly what it covers (a directory walk over
     * e.g. app/Services would also sweep in dozens of pre-existing,
     * already-covered-elsewhere files that are not this checkpoint's
     * own new surface).
     *
     * @var string[]
     */
    private const NEW_PRODUCTION_FILES = [
        'database/migrations/2026_09_09_090001_create_integration_platform_overview_summaries_table.php',
        'app/Models/IntegrationPlatformOverviewSummary.php',
        'app/Services/IntegrationPlatformOverviewSummaryService.php',
        'app/Console/Commands/RefreshIntegrationPlatformOverviewSummariesCommand.php',
        'app/Jobs/RefreshIntegrationPlatformOverviewSummaryJob.php',
        'app/Services/PlatformFirmIntegrationBoundedAccessService.php',
        'app/Services/IntegrationPlatformOversightReadService.php',
        'app/Integrations/Data/PlatformIntegrationConnectionSummary.php',
        'app/Filament/Pages/PlatformIntegrationOverviewPage.php',
        'app/Filament/Pages/PlatformFirmIntegrationsPage.php',
        'app/Filament/Pages/PlatformFirmIntegrationDetailPage.php',
        'app/Filament/Actions/Platform/RequeueOutboxEventAsSupportAction.php',
        'app/Filament/Actions/Platform/RequeueSyncItemAsSupportAction.php',
        'app/Filament/Actions/Platform/NudgeIntegrationQueueAsSupportAction.php',
        'app/Filament/Actions/Platform/RequestSupportAccessAction.php',
        'app/Filament/Actions/Platform/EnterSupportAccessSessionAction.php',
        'app/Filament/Actions/Platform/LeaveSupportAccessSessionAction.php',
        'app/Filament/Actions/Platform/RevokeSupportAccessSessionAction.php',
    ];

    /**
     * The 3 modified files, checked too — additive-only per diff-
     * review.md, but the FULL current file content is what actually
     * ships, so scanning them is the honest check.
     *
     * @var string[]
     */
    private const MODIFIED_PRODUCTION_FILES = [
        'app/Services/PlatformStaffAccessPolicyService.php',
        'app/Services/RowLevelSecurityCoverageMappingService.php',
        'bootstrap/app.php',
    ];

    public function test_every_new_production_file_from_the_frozen_allowlist_exists_on_disk(): void
    {
        foreach (self::NEW_PRODUCTION_FILES as $relativePath) {
            $this->assertFileExists(base_path($relativePath), "{$relativePath} is on the frozen production-file allowlist but does not exist on disk.");
        }
    }

    public function test_no_new_production_file_references_a_real_network_call_primitive(): void
    {
        foreach (self::NEW_PRODUCTION_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertIsString($source);

            $scannable = self::stripCommentsForScanning($source);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $scannable, "{$relativePath} must not reference a real network-call primitive: {$needle}");
            }
        }
    }

    public function test_no_modified_production_file_references_a_real_network_call_primitive(): void
    {
        foreach (self::MODIFIED_PRODUCTION_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertIsString($source);

            $scannable = self::stripCommentsForScanning($source);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $scannable, "{$relativePath} must not reference a real network-call primitive: {$needle}");
            }
        }
    }

    public function test_no_new_production_file_imports_a_real_http_client_class(): void
    {
        foreach (self::NEW_PRODUCTION_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertIsString($source);

            $this->assertDoesNotMatchRegularExpression(
                '/^use\s+(GuzzleHttp|Illuminate\\\\Support\\\\Facades\\\\Http)\\\\?.*;/m',
                $source,
                "{$relativePath} must not import a real HTTP client class."
            );
        }
    }

    public function test_no_new_production_file_references_a_real_provider_vendor_name(): void
    {
        $realProviderNeedles = ['QuickBooks', 'Xero', 'Clio', 'Stripe', 'DocuSign', 'Salesforce', 'HubSpot', 'NetDocuments', 'iManage'];

        foreach (self::NEW_PRODUCTION_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertIsString($source);

            foreach ($realProviderNeedles as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$relativePath} must not reference the real provider vendor name {$needle} — this checkpoint touches only the generic, internal TestProvider vocabulary.");
            }
        }
    }

    public function test_comment_stripping_helper_does_not_mask_a_real_violation(): void
    {
        $maliciousSource = <<<'PHP'
            <?php
            /**
             * This docblock safely mentions Http:: and curl_ in prose
             * without being real code.
             */
            final class NotARealFile
            {
                public function doSomething(): void
                {
                    // This inline comment mentions Http:: too, harmlessly.
                    Http::get('https://example.invalid');
                }
            }
            PHP;

        $scannable = self::stripCommentsForScanning($maliciousSource);

        $this->assertStringContainsString('Http::', $scannable, 'The comment-stripping helper must not remove a forbidden needle that appears in real executable code.');
    }

    public function test_comment_stripping_helper_correctly_removes_a_needle_that_only_appears_in_a_comment(): void
    {
        $harmlessSource = <<<'PHP'
            <?php
            /**
             * Never call Http:: or curl_ here — this is documentation only.
             */
            final class NotARealFile
            {
                // Http:: mentioned again, purely as a line comment.
                public function doSomething(): void
                {
                    return;
                }
            }
            PHP;

        $scannable = self::stripCommentsForScanning($harmlessSource);

        $this->assertStringNotContainsString('Http::', $scannable);
        $this->assertStringNotContainsString('curl_', $scannable);
    }

    /**
     * Verbatim copy of NoRealNetworkCallInJobsTest::stripCommentsForScanning()
     * — same discipline, reused rather than reinvented.
     */
    private static function stripCommentsForScanning(string $source): string
    {
        $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $source);
        $lines = explode("\n", $withoutBlockComments ?? $source);

        $codeLines = array_filter(
            $lines,
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '//')
        );

        return implode("\n", $codeLines);
    }
}
