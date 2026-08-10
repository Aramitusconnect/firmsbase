<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\AutomationRule;
use App\Models\DocumentRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\DomainEventClaimService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\ConflictCheckService;
use App\Services\MatterOpeningService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MatterOnboardingWorkflowTest — Zero-Click Core Workflow Automation,
 * test matrix J. End-to-end proof (item 8): a real Matter opening
 * (through the real, unmodified MatterOpeningService — conflict check
 * included) flows through the REAL Automation Engine to a real
 * DocumentRequest, never a bespoke onboarding path.
 */
class MatterOnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function openMatter(Firm $firm, Matter $matter): Matter
    {
        $timeline = new TimelineEventRecorder;
        $conflictCheck = new ConflictCheckService($timeline);
        $service = new MatterOpeningService($conflictCheck, $timeline, app(DomainEventRecorderService::class));

        $run = $service->requestConflictCheck($matter, ['no_such_name_anywhere_xyz']);

        return $service->openMatter($this->runWithFirmContext($firm, fn () => $matter->fresh()), $run);
    }

    public function test_matter_opened_creates_a_document_request_through_the_real_automation_engine(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $matter = $this->runWithFirmContext($firm, function () use ($firm) {
            AutomationRule::factory()->forFirm($firm)->create([
                'event_type' => DomainEventType::MatterOpened,
                'conditions_json' => [],
                'actions_json' => [
                    ['action_type' => AutomationActionType::CreateDocumentRequest->value, 'config' => [
                        'title' => 'Onboarding documents',
                        'items' => [
                            ['label' => 'Government ID', 'is_required' => true],
                            ['label' => 'Proof of address', 'is_required' => false],
                        ],
                    ]],
                ],
            ]);

            return Matter::factory()->forFirm($firm)->create();
        });

        $this->openMatter($firm, $matter);

        (new AutomationEventDispatchJob($firm->id))->handle(app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class));
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $documentRequest = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->where('matter_id', $matter->id)->first());

        $this->assertNotNull($documentRequest);
        $this->assertSame('Onboarding documents', $documentRequest->title);

        $itemCount = $this->runWithFirmContext($firm, fn () => $documentRequest->items()->count());
        $this->assertSame(2, $itemCount);
    }
}
