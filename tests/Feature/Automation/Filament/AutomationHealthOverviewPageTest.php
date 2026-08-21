<?php

declare(strict_types=1);

namespace Tests\Feature\Automation\Filament;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionType;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\AutomationHealthOverviewPage;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationObservabilityService;
use App\Services\TaskService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AutomationHealthOverviewPageTest — RPT-004: AutomationObservabilityService::summary()
 * computed 16 real firm-scoped automation-health metrics with zero
 * production callers. Proves the new page actually calls it, renders
 * its numbers, and is gated the same way AutomationRuleResource's own
 * AutomationRulePolicy gates automation visibility (AutomationAccessPolicyService::
 * canManageRules()) — a real Livewire boot/registration test, not a
 * snapshot test, mirroring AccountingOverviewPageTest's own style.
 */
final class AutomationHealthOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }

    public function test_the_page_renders_successfully_for_an_authorized_firm_user(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(AutomationHealthOverviewPage::class);
            $test->assertSuccessful();
        });
    }

    public function test_an_unauthorized_role_cannot_access_the_page_at_all(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(AutomationHealthOverviewPage::getUrl()));

        $response->assertForbidden();
    }

    public function test_an_unauthorized_role_does_not_see_the_page_in_navigation(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->assertFalse(AutomationHealthOverviewPage::shouldRegisterNavigation());
    }

    /**
     * Builds a known fixture set covering all 16 summary() keys, calls
     * the service directly to capture the exact expected numbers, then
     * asserts the page renders those same numbers — proving the page
     * is a real, non-fabricated wire-up rather than a static mock-up.
     */
    public function test_the_page_displays_numbers_matching_the_observability_service_summary(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function () use ($firm): void {
            AutomationRule::factory()->forFirm($firm)->create(['enabled' => true]);
            AutomationRule::factory()->forFirm($firm)->create(['enabled' => false]);

            $execution = AutomationExecution::factory()->forFirm($firm)->create([
                'matched' => true,
                'status' => AutomationExecutionStatus::Completed,
                'started_at' => now()->subSeconds(20),
                'completed_at' => now(),
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::CreateTask,
                'status' => AutomationActionExecutionStatus::Succeeded,
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::CreateTask,
                'status' => AutomationActionExecutionStatus::Failed,
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::CreateTask,
                'status' => AutomationActionExecutionStatus::RetryScheduled,
            ]);

            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::NotifyClient,
                'status' => AutomationActionExecutionStatus::RequiresReview,
            ]);

            DomainEvent::factory()->forFirm($firm)->create(['processing_status' => DomainEventProcessingStatus::DeadLettered]);
            DomainEvent::factory()->forFirm($firm)->create(['processing_status' => DomainEventProcessingStatus::Pending]);

            // A genuinely delivered reminder: no result reference.
            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::NotifyClient,
                'status' => AutomationActionExecutionStatus::Succeeded,
                'result_reference_type' => null,
                'result_reference_id' => null,
            ]);

            // A blocked-and-reviewed reminder: result reference is the
            // fallback Task.
            $task = app(TaskService::class)->create(firm: $firm, title: 'Review client reminder');
            AutomationActionExecution::factory()->forFirm($firm)->create([
                'automation_execution_id' => $execution->id,
                'action_type' => AutomationActionType::NotifyClient,
                'status' => AutomationActionExecutionStatus::Succeeded,
                'result_reference_type' => (new Task)->getMorphClass(),
                'result_reference_id' => $task->id,
            ]);
        });

        $summary = $this->runWithFirmContext($firm, fn () => app(AutomationObservabilityService::class)->summary($firm));

        // Sanity-check the fixture actually produced the shape this
        // test relies on before asserting the page reflects it.
        $this->assertSame(1, $summary['executions_total']);
        $this->assertSame(1, $summary['executions_matched']);
        $this->assertSame(3, $summary['actions_succeeded']);
        $this->assertSame(1, $summary['actions_failed']);
        $this->assertSame(1, $summary['actions_retry_scheduled']);
        $this->assertSame(1, $summary['actions_awaiting_approval']);
        $this->assertSame(1, $summary['rules_enabled']);
        $this->assertSame(1, $summary['rules_disabled']);
        $this->assertSame(1, $summary['events_dead_lettered']);
        $this->assertSame(1, $summary['tasks_created']);
        $this->assertSame(0, $summary['document_requests_created']);
        $this->assertSame(0, $summary['checklist_completions']);
        $this->assertSame(2, $summary['reminders_attempted']);
        $this->assertSame(1, $summary['reminders_delivered']);
        $this->assertSame(1, $summary['reminders_blocked']);
        $this->assertSame([], $summary['repeatedly_failing_rule_ids']);
        $this->assertNotNull($summary['average_execution_duration_seconds']);
        $this->assertNotNull($summary['oldest_queued_event_created_at']);

        $this->runWithFirmContext($firm, function () use ($summary): void {
            $test = Livewire::test(AutomationHealthOverviewPage::class);
            $test->assertSuccessful();

            $test->assertSeeText("Total executions: {$summary['executions_total']}");
            $test->assertSeeText("Matched executions: {$summary['executions_matched']}");
            $test->assertSeeText("Succeeded: {$summary['actions_succeeded']}");
            $test->assertSeeText("Failed: {$summary['actions_failed']}");
            $test->assertSeeText("Retry scheduled: {$summary['actions_retry_scheduled']}");
            $test->assertSeeText("Awaiting approval: {$summary['actions_awaiting_approval']}");
            $test->assertSeeText("Enabled: {$summary['rules_enabled']}");
            $test->assertSeeText("Disabled: {$summary['rules_disabled']}");
            $test->assertSeeText("Dead-lettered events: {$summary['events_dead_lettered']}");
            $test->assertSeeText("Tasks created: {$summary['tasks_created']}");
            $test->assertSeeText("Document requests created: {$summary['document_requests_created']}");
            $test->assertSeeText("Checklist completions: {$summary['checklist_completions']}");
            $test->assertSeeText("Attempted: {$summary['reminders_attempted']}");
            $test->assertSeeText("Delivered: {$summary['reminders_delivered']}");
            $test->assertSeeText("Blocked (requires review): {$summary['reminders_blocked']}");
            $test->assertSeeText('No rules are repeatedly failing.');
            $test->assertSeeText("Average execution duration: {$summary['average_execution_duration_seconds']}s");
            $test->assertSeeText("Oldest queued event: {$summary['oldest_queued_event_created_at']}");
        });
    }
}
