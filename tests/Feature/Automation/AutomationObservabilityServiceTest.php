<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\AutomationObservabilityService;
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
}
