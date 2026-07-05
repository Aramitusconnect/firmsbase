<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms no AI/LLM call exists anywhere in the Phase 10 core workflow
 * (project rule: the core form-draft/document-generation/review
 * workflow must function entirely without AI; field resolution is a
 * fixed, deterministic match statement, never a model call, eval, or
 * reflection-based generic resolver).
 */
class Phase10NoAiInCoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'OpenAI', 'Anthropic', 'Claude', 'GPT', 'ChatCompletion',
        'anthropic-ai', 'openai-php', 'llm', 'Llm', 'LLM',
        'eval(', 'call_user_func(', '->{$', '$$',
    ];

    private const CORE_SERVICE_FILES = [
        'FormTemplateService.php',
        'FormFieldService.php',
        'FormMappingRuleService.php',
        'DeterministicFieldResolutionService.php',
        'FormDraftGenerationService.php',
        'FormMissingDataDetectionService.php',
        'FormReviewChecklistService.php',
        'FormReviewService.php',
        'FormEditionWatchService.php',
        'FormAccessibilityReadinessService.php',
        'DocumentTemplateService.php',
        'DocumentGenerationService.php',
        'DocumentReviewService.php',
        'ReviewWorkflowTransitionService.php',
        'FormAndDocumentAccessPolicyService.php',
        'TenantSafeFormAndDocumentPolicyService.php',
    ];

    public function test_no_core_service_references_ai_sdk_or_dynamic_code_execution(): void
    {
        foreach (self::CORE_SERVICE_FILES as $filename) {
            $path = app_path("Services/{$filename}");
            $this->assertFileExists($path);

            $source = file_get_contents($path);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$filename} must not reference: {$needle}");
            }
        }
    }

    public function test_deterministic_field_resolution_uses_fixed_match_statements_only(): void
    {
        $source = file_get_contents(app_path('Services/DeterministicFieldResolutionService.php'));

        $this->assertStringContainsString('match (', $source);
        $this->assertStringNotContainsString('call_user_func', $source);
        $this->assertStringNotContainsString('->get(', $source, 'Resolver must not perform generic dynamic property access.');
    }

    public function test_no_ai_actor_type_exists_on_any_form_or_document_approval_column(): void
    {
        $reviewSource = file_get_contents(app_path('Services/FormReviewService.php'));
        $docReviewSource = file_get_contents(app_path('Services/DocumentReviewService.php'));

        foreach (['ai_approved', 'AiActor', 'approved_by_ai', 'auto_approve'] as $needle) {
            $this->assertStringNotContainsString($needle, $reviewSource);
            $this->assertStringNotContainsString($needle, $docReviewSource);
        }
    }
}
