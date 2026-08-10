<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionType;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
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
use App\Services\Automation\DomainEventRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationExecutionEngineTest — Event-Driven Automation Engine, items
 * 2/8/9/11/12 + item 17 (security). End-to-end proof of DOMAIN EVENT ->
 * AUTOMATION RULE -> CONDITIONS -> ACTION -> EXECUTION -> AUDIT via the
 * real dispatch jobs (never calling a handler directly), plus
 * idempotency (redelivery never double-executes), loop prevention, and
 * that a disabled rule or a non-matching condition never runs an
 * action.
 */
class AutomationExecutionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function billingStaffPayload(): array
    {
        return ['pending_allocation' => ['id' => 1, 'payment_id' => 1, 'invoice_id' => null, 'amount_cents' => 15000]];
    }

    private function makeBillingStaffRule(Firm $firm, array $overrides = []): AutomationRule
    {
        return $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create(array_merge([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'conditions_json' => [],
            'actions_json' => [
                ['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'Resolve pending allocation', 'assigned_to' => 'role:billing_staff']],
            ],
        ], $overrides)));
    }

    private function runDispatchPipeline(Firm $firm): void
    {
        (new AutomationEventDispatchJob($firm->id))->handle(
            app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class),
        );
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );
    }

    public function test_end_to_end_matched_rule_creates_a_task_via_the_canonical_service(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));
        $this->makeBillingStaffRule($firm);

        $event = $this->runWithFirmContext($firm, fn () => app(DomainEventRecorderService::class)->record(
            $firm, DomainEventType::PaymentAllocationPending, $this->billingStaffPayload(),
        ));

        $this->runDispatchPipeline($firm);

        [$freshEvent, $execution, $actionExecution, $taskCount] = $this->runWithFirmContext($firm, fn () => [
            $event->fresh(),
            AutomationExecution::query()->where('domain_event_id', $event->id)->first(),
            AutomationActionExecution::query()->whereHas('execution', fn ($q) => $q->where('domain_event_id', $event->id))->first(),
            Task::query()->count(),
        ]);

        $this->assertSame('processed', $freshEvent->processing_status->value);
        $this->assertTrue($execution->matched);
        $this->assertSame(AutomationExecutionStatus::Completed, $execution->status);
        $this->assertSame(AutomationActionExecutionStatus::Succeeded, $actionExecution->status);
        $this->assertNotNull($actionExecution->result_reference_id);
        $this->assertSame(1, $taskCount);
    }

    public function test_a_non_matching_condition_records_the_execution_but_creates_no_action(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));
        $this->makeBillingStaffRule($firm, [
            'conditions_json' => [['field' => 'pending_allocation.amount_cents', 'operator' => 'greater_than', 'value' => 1_000_000]],
        ]);

        $event = $this->runWithFirmContext($firm, fn () => app(DomainEventRecorderService::class)->record(
            $firm, DomainEventType::PaymentAllocationPending, $this->billingStaffPayload(),
        ));

        $this->runDispatchPipeline($firm);

        [$execution, $actionCount, $taskCount] = $this->runWithFirmContext($firm, fn () => [
            AutomationExecution::query()->where('domain_event_id', $event->id)->first(),
            AutomationActionExecution::query()->whereHas('execution', fn ($q) => $q->where('domain_event_id', $event->id))->count(),
            Task::query()->count(),
        ]);

        $this->assertFalse($execution->matched);
        $this->assertSame(AutomationExecutionStatus::Completed, $execution->status);
        $this->assertSame(0, $actionCount);
        $this->assertSame(0, $taskCount);
    }

    public function test_a_disabled_rule_never_executes(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));
        $this->makeBillingStaffRule($firm, ['enabled' => false]);

        $event = $this->runWithFirmContext($firm, fn () => app(DomainEventRecorderService::class)->record(
            $firm, DomainEventType::PaymentAllocationPending, $this->billingStaffPayload(),
        ));

        $this->runDispatchPipeline($firm);

        $executionCount = $this->runWithFirmContext($firm, fn () => AutomationExecution::query()->where('domain_event_id', $event->id)->count());

        $this->assertSame(0, $executionCount);
    }

    public function test_redelivering_the_same_event_to_the_matcher_never_double_executes(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));
        $rule = $this->makeBillingStaffRule($firm);

        $event = $this->runWithFirmContext($firm, fn () => app(DomainEventRecorderService::class)->record(
            $firm, DomainEventType::PaymentAllocationPending, $this->billingStaffPayload(),
        ));

        // Simulate a stale-lock reclaim / redelivered queue message: the
        // matcher runs twice against the identical event, as could happen
        // under worker restart or SKIP LOCKED stale recovery.
        $this->runWithFirmContext($firm, function () use ($firm, $event) {
            $matcher = app(AutomationRuleMatchingService::class);
            $matcher->match($firm, $event->fresh());
            $matcher->match($firm, $event->fresh());
        });

        [$executionCount, $actionCount] = $this->runWithFirmContext($firm, fn () => [
            AutomationExecution::query()->where('domain_event_id', $event->id)->where('automation_rule_id', $rule->id)->count(),
            AutomationActionExecution::query()->whereHas('execution', fn ($q) => $q->where('domain_event_id', $event->id))->count(),
        ]);

        $this->assertSame(1, $executionCount);
        $this->assertSame(1, $actionCount);
    }

    public function test_an_event_beyond_the_max_causation_depth_matches_no_rules(): void
    {
        $firm = Firm::factory()->create();
        $this->makeBillingStaffRule($firm);

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'payload_json' => $this->billingStaffPayload(),
            'causation_depth' => AutomationRuleMatchingService::MAX_CAUSATION_DEPTH + 1,
        ]));

        $result = $this->runWithFirmContext($firm, fn () => app(AutomationRuleMatchingService::class)->match($firm, $event));

        $this->assertTrue($result['loop_prevented']);
        $this->assertSame(0, $result['matched_rules']);

        $executionCount = $this->runWithFirmContext($firm, fn () => AutomationExecution::query()->where('domain_event_id', $event->id)->count());
        $this->assertSame(0, $executionCount);
    }

    public function test_a_rule_at_exactly_the_max_causation_depth_still_matches(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));
        $this->makeBillingStaffRule($firm);

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'payload_json' => $this->billingStaffPayload(),
            'causation_depth' => AutomationRuleMatchingService::MAX_CAUSATION_DEPTH,
        ]));

        $result = $this->runWithFirmContext($firm, fn () => app(AutomationRuleMatchingService::class)->match($firm, $event));

        $this->assertFalse($result['loop_prevented']);
        $this->assertSame(1, $result['matched_rules']);
    }

    public function test_a_tampered_rule_with_an_unrecognized_action_type_fails_that_rule_without_blocking_others(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]));

        // A well-formed rule that should still fire...
        $goodRule = $this->makeBillingStaffRule($firm);
        // ...alongside one only reachable via direct DB tampering, since
        // AutomationRuleService::validate() would reject this action_type
        // at save time. Constructed directly against the model/factory
        // (bypassing the service layer), mirroring the established
        // drift-simulation technique used elsewhere in this codebase.
        $badRule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create([
            'event_type' => DomainEventType::PaymentAllocationPending,
            'conditions_json' => [],
            'actions_json' => [['action_type' => 'create_trust_ledger_entry', 'config' => []]],
        ]));

        $event = $this->runWithFirmContext($firm, fn () => app(DomainEventRecorderService::class)->record(
            $firm, DomainEventType::PaymentAllocationPending, $this->billingStaffPayload(),
        ));

        $result = $this->runWithFirmContext($firm, fn () => app(AutomationRuleMatchingService::class)->match($firm, $event->fresh()));

        // Both rules matched their (empty) conditions — the tampered
        // rule's corruption surfaces only once action-execution creation
        // is attempted, and does not abort matching for the event as a
        // whole (the good rule still gets its own execution recorded).
        $this->assertSame(2, $result['matched_rules']);

        [$goodExecution, $badExecution] = $this->runWithFirmContext($firm, fn () => [
            AutomationExecution::query()->where('domain_event_id', $event->id)->where('automation_rule_id', $goodRule->id)->first(),
            AutomationExecution::query()->where('domain_event_id', $event->id)->where('automation_rule_id', $badRule->id)->first(),
        ]);

        $this->assertSame(AutomationExecutionStatus::Failed, $badExecution->status);
        $this->assertNotNull($badExecution->failure_reason);
        $this->assertSame(AutomationExecutionStatus::Pending, $goodExecution->status);
    }
}
