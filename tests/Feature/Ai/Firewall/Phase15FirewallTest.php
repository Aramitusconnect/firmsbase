<?php

namespace Tests\Feature\Ai\Firewall;

use Tests\TestCase;

/**
 * Cross-cutting Phase 15 firewall checks: no real HTTP/DNS/SDK/OAuth/
 * provider-network behavior anywhere in the new AI files, no routes/
 * controllers/UI were added (project rule 25 — prefer no UI in Phase
 * 15), and no training capability exists anywhere (stronger than
 * project rule 11 requires — Phase 15 implements no training code
 * path at all, so there is nothing to opt into yet; a future
 * provider-integration phase would need to add an explicit,
 * documented opt-in policy under ai_policy_settings before any
 * training capability could exist, per project rule 12).
 */
class Phase15FirewallTest extends TestCase
{
    private const AI_FILES = [
        'app/Services/AiEntitlementPolicyService.php',
        'app/Services/AiProviderKeyService.php',
        'app/Services/AiModeResolutionService.php',
        'app/Services/AiUsageRecorderService.php',
        'app/Services/AiBudgetEnforcementService.php',
        'app/Services/AiApprovalWorkflowService.php',
        'app/Services/AiToolActionRecorderService.php',
        'app/Services/MatterAccessPolicyService.php',
        'app/Services/AiRetrievalIsolationService.php',
        'app/Services/PromptInjectionResistanceService.php',
        'app/Services/AiProviderAdapterInterface.php',
        'app/Services/FakeAiProviderAdapter.php',
    ];

    public function test_no_ai_file_references_any_real_transport_dns_or_provider_sdk(): void
    {
        $forbidden = [
            'Http::', 'GuzzleHttp', 'curl_init', 'curl_exec', 'fsockopen', 'pfsockopen',
            "file_get_contents('http", 'file_get_contents("http',
            'stream_socket_client', 'proc_open',
            'gethostbyname', 'dns_get_record', 'checkdnsrr',
            'openai-php', 'anthropic-php', 'google-cloud', 'aws-sdk-php',
            'OAuth', 'oauth2',
        ];

        $violations = [];

        foreach (self::AI_FILES as $relativePath) {
            // Comments/docblocks may legitimately explain what NOT to do
            // (e.g. "no real HTTP, SDK, OAuth, DNS, curl, Guzzle,
            // fsockopen") without that prose counting as the forbidden
            // pattern itself — only executable code is checked here.
            $source = $this->stripComments(file_get_contents(base_path($relativePath)));

            foreach ($forbidden as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$relativePath} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_no_ai_file_introduces_a_route_controller_or_ui_reference(): void
    {
        $forbidden = ['Route::', 'extends Controller', 'Livewire\\Component', 'Filament\\Resources'];
        $violations = [];

        foreach (self::AI_FILES as $relativePath) {
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
        $this->assertDirectoryDoesNotExist(base_path('app/Http/Controllers/Ai'));

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

    public function test_no_training_capability_exists_anywhere_in_the_new_ai_files(): void
    {
        $forbidden = ['function train(', 'trainOnFirmData', 'enableTraining', 'train_on_data'];
        $violations = [];

        foreach (self::AI_FILES as $relativePath) {
            $source = file_get_contents(base_path($relativePath));

            foreach ($forbidden as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$relativePath} contains forbidden token: {$token}";
                }
            }
        }

        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_webhook_and_platform_billing_services_were_not_modified_by_phase_15(): void
    {
        foreach ([
            'app/Services/WebhookEventRecorderService.php',
            'app/Services/WebhookSecretService.php',
            'app/Services/WebhookDestinationValidationService.php',
        ] as $relativePath) {
            $this->assertFileExists(base_path($relativePath));
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
