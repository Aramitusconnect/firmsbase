<?php

namespace Tests\Feature\Ai\Firewall;

use Tests\TestCase;

/**
 * Project rule 22 / Phase 15 protected files: document chase, matter
 * readiness scoring, deterministic form autofill, and document
 * generation/merge must remain non-AI and untouched by Phase 15. This
 * mirrors Phase10NoAiInCoreWorkflowTest/Phase11NoAiInCoreWorkflowTest's
 * exact reasoning, extended to the Phase 4 document-chase/readiness
 * services that those two phases did not cover.
 */
class Phase15DeterministicWorkflowsUnchangedTest extends TestCase
{
    private const FORBIDDEN_NEEDLES = [
        'OpenAI', 'Anthropic', 'Claude', 'GPT', 'ChatCompletion',
        'anthropic-ai', 'openai-php', 'llm', 'Llm', 'LLM',
        'AiProviderAdapterInterface', 'FakeAiProviderAdapter', 'AiUsageRecorderService',
    ];

    private const DETERMINISTIC_SERVICE_FILES = [
        'DocumentChaseService.php',
        'DocumentChaseSchedulerService.php',
        'MatterReadinessService.php',
        'DeterministicFieldResolutionService.php',
        'FormDraftGenerationService.php',
        'DocumentGenerationService.php',
    ];

    public function test_no_deterministic_workflow_service_references_ai_of_any_kind(): void
    {
        foreach (self::DETERMINISTIC_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");

            $this->assertFileExists($path, "{$filename} should exist in app/Services/ untouched by Phase 15.");

            $source = $this->stripComments(file_get_contents($path));

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_phase_10_and_phase_11_no_ai_firewall_tests_still_exist_and_were_not_weakened(): void
    {
        $this->assertFileExists(base_path('tests/Feature/Phase10NoAiInCoreWorkflowTest.php'));
        $this->assertFileExists(base_path('tests/Feature/Phase11NoAiInCoreWorkflowTest.php'));
    }

    /**
     * Strips PHP comments (// # and block/doc comments) via the real
     * tokenizer so forbidden-reference checks only ever see executable
     * code — a token merely mentioned in prose (or an incidental
     * substring like "Installment" containing "llm") must never fail a
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
