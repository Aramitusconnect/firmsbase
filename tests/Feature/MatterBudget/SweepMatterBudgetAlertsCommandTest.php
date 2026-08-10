<?php

namespace Tests\Feature\MatterBudget;

use App\Console\Commands\SweepMatterBudgetAlertsCommand;
use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MatterStatus;
use App\Enums\TimeEntryStatus;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\DomainEventClaimService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\EmployeeRateService;
use App\Services\MatterBudget\MatterBudgetAlertService;
use App\Services\MatterBudget\MatterBudgetAnalysisService;
use App\Services\MatterBudget\MatterProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SweepMatterBudgetAlertsCommandTest — Predictive Matter Budget
 * Alerts, item 14. End-to-end proof that a budget threshold crossing
 * flows through the REAL Automation Engine (never a parallel/duplicate
 * scheduler) all the way to a created Task, exactly the same
 * dispatch-job pipeline the Event-Driven Automation Engine's own tests
 * exercise.
 */
class SweepMatterBudgetAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function command(): SweepMatterBudgetAlertsCommand
    {
        return new SweepMatterBudgetAlertsCommand(
            new MatterBudgetAnalysisService(new MatterProgressService, new EmployeeRateService),
            new MatterBudgetAlertService(app(DomainEventRecorderService::class)),
        );
    }

    public function test_sweeping_a_matter_over_threshold_creates_an_alert_and_a_domain_event(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Open]);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [FirmUserRole::Attorney->value => 10],
                'expected_expenses_json' => [],
            ]);

            $user = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $user->id, 'role' => FirmUserRole::Attorney]);
            TimeEntry::factory()->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $user->id,
                'seconds' => 8 * 3600, 'status' => TimeEntryStatus::Approved,
            ]);
        });

        $this->command()->handle();

        $this->runWithFirmContext($firm, function () {
            $alert = MatterBudgetAlert::query()->first();
            $this->assertNotNull($alert);
            $this->assertSame(75, $alert->threshold_percent_crossed);
            $this->assertNotNull($alert->domain_event_id);
        });
    }

    public function test_sweeping_a_closed_matter_never_creates_an_alert(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Closed]);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [FirmUserRole::Attorney->value => 1],
                'expected_expenses_json' => [],
            ]);
        });

        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => MatterBudgetAlert::query()->count());
        $this->assertSame(0, $count);
    }

    public function test_a_matter_with_no_budget_is_never_swept(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Open]));

        // Should simply do nothing — no matter_budgets rows exist for this firm at all.
        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => MatterBudgetAlert::query()->count());
        $this->assertSame(0, $count);
    }

    public function test_a_budget_threshold_crossing_flows_through_the_real_automation_engine_to_a_task(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]);

            AutomationRule::factory()->forFirm($firm)->create([
                'event_type' => DomainEventType::MatterBudgetThresholdCrossed,
                'conditions_json' => [],
                'actions_json' => [
                    ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => [
                        'title' => 'Matter budget threshold crossed', 'description' => 'Review this matter\'s budget.',
                    ]],
                ],
            ]);

            $matter = Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Open]);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [FirmUserRole::Attorney->value => 10],
                'expected_expenses_json' => [],
            ]);

            $user = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $user->id, 'role' => FirmUserRole::Attorney]);
            TimeEntry::factory()->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $user->id,
                'seconds' => 8 * 3600, 'status' => TimeEntryStatus::Approved,
            ]);
        });

        $this->command()->handle();

        // The REAL Automation Engine dispatch pipeline — no bespoke alert-delivery path.
        (new AutomationEventDispatchJob($firm->id))->handle(
            app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class),
        );
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        // More than one MatterBudgetAlertType can legitimately co-occur
        // for the same underlying situation (e.g. both a role-hours
        // threshold and a usage-ahead-of-progress alert at once) — each
        // is its own DomainEvent, so more than one Task is a correct
        // outcome here, not a bug. The real proof is that the pipeline
        // fired at all, through the actual Automation Engine.
        $taskCount = $this->runWithFirmContext($firm, fn () => Task::query()->where('title', 'Matter budget threshold crossed')->count());
        $this->assertGreaterThanOrEqual(1, $taskCount);
    }
}
