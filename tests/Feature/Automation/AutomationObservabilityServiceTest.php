<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionType;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Task;
use App\Services\Automation\AutomationObservabilityService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AutomationObservabilityServiceTest — Event-Driven Automation Engine,
 * item 18. Proves the summary metrics reflect real audit-table state
 * (never a payload/config leak) and that a firm-scoped read never sees
 * another firm's rows.
 */
class AutomationObservabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutomationObservabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomationObservabilityService;
    }

    public function test_summary_counts_reflect_real_state(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            AutomationRule::factory()->forFirm($firm)->create(['enabled' => true]);
            AutomationRule::factory()->forFirm($firm)->create(['enabled' => false]);

            $execution = AutomationExecution::factory()->forFirm($firm)->create([
                'matched' => true,
                'status' => AutomationExecutionStatus::Completed,
                'started_at' => now()->subSeconds(10),
                'completed_at' => now(),
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'status' => AutomationActionExecutionStatus::Succeeded,
            ]);

            DomainEvent::factory()->forFirm($firm)->create(['processing_status' => DomainEventProcessingStatus::DeadLettered]);
            DomainEvent::factory()->forFirm($firm)->create(['processing_status' => DomainEventProcessingStatus::Pending]);
        });

        $summary = $this->runWithFirmContext($firm, fn () => $this->service->summary($firm));

        $this->assertSame(1, $summary['executions_total']);
        $this->assertSame(1, $summary['executions_matched']);
        $this->assertSame(1, $summary['actions_succeeded']);
        $this->assertSame(0, $summary['actions_failed']);
        $this->assertSame(1, $summary['rules_enabled']);
        $this->assertSame(1, $summary['rules_disabled']);
        $this->assertSame(1, $summary['events_dead_lettered']);
        $this->assertNotNull($summary['average_execution_duration_seconds']);
        $this->assertGreaterThan(0, $summary['average_execution_duration_seconds']);
        $this->assertNotNull($summary['oldest_queued_event_created_at']);
    }

    public function test_summary_never_leaks_a_different_firms_counts(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmB, fn () => AutomationRule::factory()->forFirm($firmB)->create(['enabled' => true]));

        $summaryA = $this->runWithFirmContext($firmA, fn () => $this->service->summary($firmA));

        $this->assertSame(0, $summaryA['rules_enabled']);
    }

    public function test_a_rule_with_three_or_more_recent_failures_is_flagged(): void
    {
        $firm = Firm::factory()->create();
        $rule = $this->runWithFirmContext($firm, fn () => AutomationRule::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($firm, $rule) {
            for ($i = 0; $i < 3; $i++) {
                $event = DomainEvent::factory()->forFirm($firm)->create();
                AutomationExecution::factory()->forFirm($firm)->create([
                    'automation_rule_id' => $rule->id,
                    'domain_event_id' => $event->id,
                    'status' => AutomationExecutionStatus::Failed,
                ]);
            }
        });

        $summary = $this->runWithFirmContext($firm, fn () => $this->service->summary($firm));

        $this->assertContains($rule->id, $summary['repeatedly_failing_rule_ids']);
    }

    /**
     * Zero-Click Core Workflow Automation pass — the new counters added
     * on top of this same summary(). A genuinely delivered client
     * reminder (no result reference) counts as delivered; a
     * blocked-and-reviewed one (result reference = the fallback Task)
     * counts as blocked — see NotifyClientActionHandler's own docblock
     * for why this distinction is structurally derivable, not guessed.
     */
    public function test_summary_counts_the_new_zero_click_counters(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $execution = AutomationExecution::factory()->forFirm($firm)->create();

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::CreateTask,
                'status' => AutomationActionExecutionStatus::Succeeded,
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::CreateDocumentRequest,
                'status' => AutomationActionExecutionStatus::Succeeded,
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::MarkDocumentRequestItemSubmitted,
                'status' => AutomationActionExecutionStatus::Succeeded,
            ]);

            // A genuinely delivered reminder: no result reference.
            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::NotifyClient,
                'status' => AutomationActionExecutionStatus::Succeeded,
                'result_reference_type' => null,
                'result_reference_id' => null,
            ]);

            // A blocked reminder: result reference is the fallback Task.
            $task = app(TaskService::class)->create(firm: $firm, title: 'Review client reminder');
            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::NotifyClient,
                'status' => AutomationActionExecutionStatus::Succeeded,
                'result_reference_type' => (new Task)->getMorphClass(),
                'result_reference_id' => $task->id,
            ]);
        });

        $summary = $this->runWithFirmContext($firm, fn () => $this->service->summary($firm));

        $this->assertSame(1, $summary['tasks_created']);
        $this->assertSame(1, $summary['document_requests_created']);
        $this->assertSame(1, $summary['checklist_completions']);
        $this->assertSame(2, $summary['reminders_attempted']);
        $this->assertSame(1, $summary['reminders_delivered']);
        $this->assertSame(1, $summary['reminders_blocked']);
    }
}
