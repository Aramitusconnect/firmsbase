<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Task;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\DomainEventClaimService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZeroClickLoopPreventionTest — Zero-Click Core Workflow Automation,
 * test matrix P/AF. Proves the new action handlers respect the
 * existing MAX_CAUSATION_DEPTH loop-prevention guard (never bypassed),
 * and that an automation-created Task can never itself recursively
 * re-trigger the SAME onboarding rule — because TaskService::create()
 * emits no DomainEvent at all today (confirmed by this mission's own
 * audit), so no recursive chain is even structurally possible yet.
 */
class ZeroClickLoopPreventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_past_the_max_causation_depth_is_never_matched(): void
    {
        $firm = Firm::factory()->create();

        $matched = $this->runWithFirmContext($firm, function () use ($firm) {
            AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'X', 'assigned_to' => 'role:firm_owner']]],
            ]);

            $event = DomainEvent::factory()->create([
                'firm_id' => $firm->id,
                'event_type' => DomainEventType::MatterOpened,
                'causation_depth' => AutomationRuleMatchingService::MAX_CAUSATION_DEPTH + 1,
                'payload_json' => ['matter' => ['id' => null, 'client_id' => null, 'assigned_attorney_id' => null, 'status' => 'open']],
            ]);

            return app(AutomationRuleMatchingService::class)->match($firm, $event);
        });

        $this->assertSame(0, $matched['matched_rules']);
        $this->assertTrue($matched['loop_prevented']);
    }

    public function test_a_task_created_by_automation_emits_no_further_domain_event(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        [$owner, $eventCountBefore] = $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);

            return [$owner, DomainEvent::query()->count()];
        });

        $this->runWithFirmContext($firm, fn () => app(TaskService::class)->create(
            firm: $firm,
            title: 'Manually created task',
            assignedTo: $owner->user,
        ));

        $eventCountAfter = $this->runWithFirmContext($firm, fn () => DomainEvent::query()->count());

        // TaskService::create() itself never records a DomainEvent — a
        // Task created BY an automation action therefore cannot
        // recursively re-trigger any rule, structurally, not merely by
        // convention.
        $this->assertSame($eventCountBefore, $eventCountAfter);
    }

    public function test_the_real_pipeline_never_creates_a_second_task_for_a_retried_action_execution(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);

            AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->create([
                'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Onboarding', 'assigned_to' => 'role:firm_owner']]],
            ]);

            DomainEvent::factory()->create([
                'firm_id' => $firm->id,
                'event_type' => DomainEventType::MatterOpened,
                'payload_json' => ['matter' => ['id' => null, 'client_id' => null, 'assigned_attorney_id' => null, 'status' => 'open']],
            ]);
        });

        (new AutomationEventDispatchJob($firm->id))->handle(app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class));
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );
        // A second dispatch tick for the same (already-completed) executions must never re-run them.
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $taskCount = $this->runWithFirmContext($firm, fn () => Task::query()->where('title', 'Onboarding')->count());

        $this->assertSame(1, $taskCount);
    }
}
