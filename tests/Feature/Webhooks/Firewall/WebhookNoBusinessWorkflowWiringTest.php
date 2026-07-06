<?php

namespace Tests\Feature\Webhooks\Firewall;

use Tests\TestCase;

/**
 * Payload-source-model-audit correction #4 established that the webhook
 * foundation (subscription/event-recording/payload-builder/fan-out/
 * dispatch/retry/replay/signing/secret/entitlement machinery) must
 * never itself reference or call into business workflow services. That
 * invariant is one-directional and holds regardless of downstream
 * phases, so it is still checked here.
 *
 * What this file deliberately does NOT check: whether a workflow
 * service references or calls the webhook foundation. Phase 14 was
 * foundation-only and made no such calls, but Phase 14b is an
 * explicitly approved phase that wires webhook triggers into existing
 * workflow services (LeadConversionService, DocumentSecurityService,
 * InvoiceDraftingService, TaskService, FormReviewService,
 * MatterReadinessService, etc.). A git-diff-based "protected workflow
 * file must be absent/unmodified" check, or a content-based "workflow
 * file must never mention WebhookEventRecorderService" check, would
 * incorrectly fail on every commit after Phase 14b lands — that scope
 * is intentional and approved, not a leak. Proving the wiring Phase 14b
 * performs is exactly the approved wiring and nothing more is the job
 * of tests/Feature/Webhooks/Wiring/Phase14bFirewallTest.php and the
 * per-event wiring tests alongside it.
 */
class WebhookNoBusinessWorkflowWiringTest extends TestCase
{
    private const PROTECTED_WORKFLOW_SERVICES = [
        'LeadConversionService.php',
        'MatterOpeningService.php',
        'DocumentSecurityService.php',
        'DocumentUploadPolicyService.php',
        'InvoiceDraftingService.php',
        'PaymentApplicationService.php',
        'PaymentClassificationService.php',
        'TaskService.php',
        'TaskDependencyService.php',
        'FormReviewService.php',
        'SignatureRequestWorkflowService.php',
        'SignatureRequestAggregationService.php',
        'MatterReadinessService.php',
    ];


    public function test_no_phase_14_service_file_references_a_protected_business_workflow_class_name(): void
    {
        $protected = [
            'LeadConversionService',
            'MatterOpeningService',
            'DocumentSecurityService',
            'DocumentUploadPolicyService',
            'InvoiceDraftingService',
            'PaymentApplicationService',
            'PaymentClassificationService',
            'TaskService',
            'TaskDependencyService',
            'FormReviewService',
            'SignatureRequestWorkflowService',
            'SignatureRequestAggregationService',
            'MatterReadinessService',
        ];

        $violations = [];

        foreach ($this->webhookFoundationPaths() as $file) {
            $contents = file_get_contents($file);

            foreach ($protected as $className) {
                if (str_contains($contents, $className)) {
                    $violations[] = basename($file).' references '.$className;
                }
            }
        }

        $this->assertEmpty(
            $violations,
            'Found Phase 14 webhook foundation files referencing protected business workflow classes: '.implode('; ', $violations)
        );
    }



    public function test_webhook_payload_builder_service_builds_matter_readiness_changed_from_matter_readiness_score_not_readiness_score_event(): void
    {
        // WebhookPayloadBuilderService must build matter.readiness_changed
        // from MatterReadinessScore directly, never from ReadinessScoreEvent
        // (which logs every recompute() call unconditionally and would
        // over-fire, and lacks satisfied_count/total_count entirely).
        $builderSource = file_get_contents(base_path('app/Services/WebhookPayloadBuilderService.php'));

        $this->assertStringNotContainsString('ReadinessScoreEvent', $builderSource);
        $this->assertStringContainsString('MatterReadinessScore', $builderSource);
        $this->assertStringContainsString('buildMatterReadinessChanged', $builderSource);
    }

    private function webhookFoundationPaths(): array
    {
        $paths = [];

        foreach (glob(app_path('Services/Webhook*.php')) ?: [] as $file) {
            $paths[] = $file;
        }

        foreach ([
            app_path('Services/FakeWebhookTransport.php'),
            app_path('Services/TenantSafeWebhookPolicyService.php'),
            app_path('Jobs/WebhookDispatchJob.php'),
        ] as $file) {
            if (is_file($file)) {
                $paths[] = $file;
            }
        }

        return array_values(array_unique($paths));
    }

}
