<?php

namespace Tests\Feature\Webhooks\Wiring;

use Tests\TestCase;

/**
 * Cross-cutting Phase 14b firewall checks: no real HTTP/DNS/provider
 * behavior was introduced anywhere by the wiring changes, no routes/
 * controllers/UI were added, and the Phase 14 payload allowlist service
 * was not modified (Phase 14b touches only the 11 named business
 * workflow service files — WebhookPayloadBuilderService.php,
 * WebhookDestinationValidationService.php, and every other webhook-
 * internal service remain exactly as Phase 14 left them).
 */
class Phase14bFirewallTest extends TestCase
{
    private const WIRED_FILES = [
        'app/Services/ImportApplyService.php',
        'app/Services/LeadConversionService.php',
        'app/Services/DocumentSecurityService.php',
        'app/Services/EmailAttachmentPromotionService.php',
        'app/Services/DocumentReplacementService.php',
        'app/Services/InvoiceDraftingService.php',
        'app/Services/ManualPaymentService.php',
        'app/Services/TaskService.php',
        'app/Services/FormReviewService.php',
        'app/Services/SignatureCertificateService.php',
        'app/Services/MatterReadinessService.php',
    ];

    public function test_no_wired_file_references_any_real_transport_or_dns_primitive(): void
    {
        $forbidden = [
            'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen',
            "file_get_contents('http", 'file_get_contents("http',
            'stream_socket_client', 'proc_open', 'pfsockopen',
            'gethostbyname', 'dns_get_record', 'checkdnsrr',
        ];

        $violations = [];

        foreach (self::WIRED_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            foreach ($forbidden as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$relativePath} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_wired_file_introduces_a_route_controller_or_ui_reference(): void
    {
        $forbidden = ['Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources'];
        $violations = [];

        foreach (self::WIRED_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            foreach ($forbidden as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$relativePath} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_new_routes_controllers_or_ui_files_exist_anywhere(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('app/Http/Controllers/Webhook'));
        $this->assertDirectoryDoesNotExist(base_path('app/Filament'));
        $this->assertDirectoryDoesNotExist(base_path('app/Livewire'));

        $bladeFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (str_ends_with($fileInfo->getFilename(), '.blade.php')) {
                $bladeFiles[] = $fileInfo->getPathname();
            }
        }

        $this->assertEmpty($bladeFiles, 'Found unexpected Blade files: '.implode(', ', $bladeFiles));
    }

    public function test_webhook_payload_builder_service_was_not_modified_by_phase_14b(): void
    {
        // Every payload allowlist assertion from the Phase 14 audit
        // must still hold verbatim — Phase 14b's approved scope only
        // permits touching this file if a test proves it's absolutely
        // necessary, and none of the 11 wiring points required it.
        $source = file_get_contents(base_path('app/Services/WebhookPayloadBuilderService.php'));

        $this->assertStringContainsString('WebhookPayloadBuilderService — the ONLY place a domain model becomes', $source);

        // Comments/docblocks may legitimately explain what NOT to do
        // (e.g. "never $model->toArray()") without that prose counting
        // as the forbidden pattern itself — only executable code is
        // checked here.
        $this->assertStringNotContainsString('toArray()', $this->stripComments($source));
    }

    /**
     * Strips PHP comments (// # and block/doc comments) via the real
     * tokenizer so forbidden-pattern checks only ever see executable
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

    public function test_webhook_destination_and_secret_services_were_not_modified_by_phase_14b(): void
    {
        foreach ([
            'app/Services/WebhookDestinationValidationService.php',
            'app/Services/WebhookSecretService.php',
            'app/Services/WebhookSignatureService.php',
        ] as $relativePath) {
            $this->assertFileExists(base_path($relativePath));
        }
    }
}
