<?php

namespace Tests\Feature\Webhooks\Firewall;

use Tests\TestCase;

/**
 * Payload-source-model-audit correction #4: Phase 14 builds the webhook
 * subscription/event-recording/payload-builder/fan-out/dispatch/retry/
 * replay/signing/secret/entitlement machinery, but must NOT modify any
 * Phase 1-13 business workflow service to actually call
 * WebhookEventRecorderService::record(). That wiring is deferred to a
 * later, explicitly approved phase/gate. This proves the constraint
 * holds: none of the named workflow services exist inside
 * phase-14-complete (the build-package convention is that only new or
 * modified files are copied into a phase-N-complete package — their
 * absence here IS the proof they were never touched), and none of the
 * Phase 14 files themselves reference these workflow classes.
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


    public function test_no_protected_business_workflow_service_file_exists_in_the_phase_14_package(): void
    {
        $protectedPaths = [
            'app/Services/LeadConversionService.php',
            'app/Services/MatterOpeningService.php',
            'app/Services/DocumentSecurityService.php',
            'app/Services/DocumentUploadPolicyService.php',
            'app/Services/InvoiceDraftingService.php',
            'app/Services/PaymentApplicationService.php',
            'app/Services/PaymentClassificationService.php',
            'app/Services/TaskService.php',
            'app/Services/TaskDependencyService.php',
            'app/Services/FormReviewService.php',
            'app/Services/SignatureRequestWorkflowService.php',
            'app/Services/SignatureRequestAggregationService.php',
            'app/Services/MatterReadinessService.php',
        ];

        $violations = array_values(array_intersect($this->phase14ChangedPaths(), $protectedPaths));

        $this->assertEmpty(
            $violations,
            'Phase 14 must not add or modify protected business workflow services: '.implode(', ', $violations)
        );
    }




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



    public function test_webhook_event_recorder_service_is_never_called_from_a_workflow_context_in_phase_14(): void
    {
        $protectedWorkflowPaths = [
            'app/Services/LeadConversionService.php',
            'app/Services/MatterOpeningService.php',
            'app/Services/DocumentSecurityService.php',
            'app/Services/DocumentUploadPolicyService.php',
            'app/Services/InvoiceDraftingService.php',
            'app/Services/PaymentApplicationService.php',
            'app/Services/PaymentClassificationService.php',
            'app/Services/TaskService.php',
            'app/Services/TaskDependencyService.php',
            'app/Services/FormReviewService.php',
            'app/Services/SignatureRequestWorkflowService.php',
            'app/Services/SignatureRequestAggregationService.php',
            'app/Services/MatterReadinessService.php',
        ];

        $violations = [];

        foreach ($protectedWorkflowPaths as $relativePath) {
            $file = base_path($relativePath);

            if (is_file($file) && str_contains(file_get_contents($file), 'WebhookEventRecorderService')) {
                $violations[] = $relativePath;
            }
        }

        $this->assertEmpty(
            $violations,
            'WebhookEventRecorderService must not be wired into protected business workflows in Phase 14: '.implode(', ', $violations)
        );
    }


    public function test_matter_readiness_service_is_absent_and_readiness_score_event_is_never_used_as_a_payload_source(): void
    {
        // MatterReadinessService.php pre-exists from an earlier phase in
        // the merged repo, so its mere presence is not the signal. Phase
        // 14 proves it did not touch that file (not in the changed/
        // untracked set) and that WebhookPayloadBuilderService builds
        // matter.readiness_changed from MatterReadinessScore directly,
        // never from ReadinessScoreEvent (which logs every recompute()
        // call unconditionally and would over-fire, and lacks
        // satisfied_count/total_count entirely).
        $this->assertNotContains(
            'app/Services/MatterReadinessService.php',
            $this->phase14ChangedPaths(),
            'Phase 14 must not add or modify app/Services/MatterReadinessService.php'
        );

        $builderSource = file_get_contents(base_path('app/Services/WebhookPayloadBuilderService.php'));

        $this->assertStringNotContainsString('ReadinessScoreEvent', $builderSource);
        $this->assertStringContainsString('MatterReadinessScore', $builderSource);
        $this->assertStringContainsString('buildMatterReadinessChanged', $builderSource);
    }

    private function phase14ChangedPaths(): array
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard'
        ));

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
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
