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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZeroClickIdempotencyTest — Zero-Click Core Workflow Automation, test
 * matrix AD. A retried delivery of the SAME already-recorded
 * MatterOpened domain event/action execution must never create a
 * second onboarding DocumentRequest — proves the existing engine-level
 * idempotency (unique automation_rule_id+domain_event_id, action
 * idempotency_key) holds for the new CreateDocumentRequest action
 * exactly as it does for every other registered action.
 */
class ZeroClickIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_the_action_dispatch_for_the_same_execution_never_creates_a_second_document_request(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $matter = $this->runWithFirmContext($firm, function () use ($firm) {
            AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'actions_json' => [['action_type' => AutomationActionType::CreateDocumentRequest->value, 'config' => [
                    'title' => 'Onboarding documents',
                    'items' => [['label' => 'ID']],
                ]]],
            ]);
            $matter = Matter::factory()->forFirm($firm)->create();

            app(DomainEventRecorderService::class)->record($firm, DomainEventType::MatterOpened, [
                'matter' => ['id' => $matter->id, 'client_id' => $matter->client_id, 'assigned_attorney_id' => null, 'status' => 'open'],
            ], subject: $matter);

            return $matter;
        });

        (new AutomationEventDispatchJob($firm->id))->handle(app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class));

        // The SAME already-completed action execution dispatched twice in a row.
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $count = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->where('matter_id', $matter->id)->count());

        $this->assertSame(1, $count);
    }
}
