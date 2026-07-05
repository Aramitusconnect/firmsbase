<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms no AI/LLM reference and no legal-conclusion engine exists
 * anywhere in the Phase 11 core signature workflow (project rule: no
 * AI is required for signature execution; the readiness checklist
 * services are fixed reference documentation, never a scored or
 * auto-decided legal conclusion).
 */
class Phase11NoAiInCoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'OpenAI', 'Anthropic', 'Claude', 'GPT', 'ChatCompletion', 'llm', 'Llm', 'LLM',
        'eval(', 'call_user_func(', '->{$', '$$',
        'is_enforceable', 'isEnforceable', 'legal_conclusion', 'LegalConclusion',
    ];

    private const CORE_SERVICE_FILES = [
        'SignatureRequestWorkflowService.php',
        'SignatureRecipientWorkflowService.php',
        'SignatureRequestAggregationService.php',
        'DocumentHashService.php',
        'SignatureCertificateService.php',
        'SignatureEventLogger.php',
        'SignatureWorkflowTransitionService.php',
        'SignatureEsignLegalReadinessService.php',
        'SignatureAccessibilityReadinessService.php',
        'SignatureAndPdfAccessPolicyService.php',
        'PdfViewEventService.php',
        'PdfDownloadPolicyService.php',
        'PdfAnnotationService.php',
        'TenantSafeSignatureAndPdfPolicyService.php',
    ];

    public function test_no_core_service_references_ai_sdk_dynamic_code_execution_or_a_legal_conclusion_engine(): void
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

    public function test_full_signature_workflow_completes_without_any_ai_actor_type(): void
    {
        $source = file_get_contents(app_path('Enums/SignatureEventActorType.php'));

        $this->assertStringNotContainsString('Ai', $source);
        $this->assertStringNotContainsString('AI', $source);
    }
}
