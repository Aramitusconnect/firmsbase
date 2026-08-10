<?php

namespace Tests\Feature\Leverage;

use App\Console\Commands\SweepLeverageRecommendationsCommand;
use App\Enums\AutomationActionType;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Enums\MatterLeverageRecommendationType;
use App\Enums\MatterStatus;
use App\Enums\TaskWorkCategory;
use App\Jobs\AutomationActionDispatchJob;
use App\Jobs\AutomationEventDispatchJob;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterLeverageRecommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationActionExecutionClaimService;
use App\Services\Automation\AutomationActionHandlerRegistry;
use App\Services\Automation\AutomationExecutionCompletionService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\DomainEventClaimService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\Leverage\LeverageAnalysisService;
use App\Services\Leverage\LeverageRecommendationLifecycleService;
use App\Services\Leverage\LeverageRecommendationService;
use App\Services\Leverage\StaffingPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SweepLeverageRecommendationsCommandTest — Leverage Ratio Optimizer,
 * item 22/24. End-to-end proof that a staffing recommendation flows
 * through the REAL Automation Engine (never a parallel/duplicate
 * scheduler), and that the same sweep marks stale recommendations,
 * mirroring SweepMatterBudgetAlertsCommandTest's own shape.
 */
class SweepLeverageRecommendationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function command(): SweepLeverageRecommendationsCommand
    {
        return new SweepLeverageRecommendationsCommand(
            new LeverageRecommendationService(
                new LeverageAnalysisService,
                new StaffingPolicyService(new MatterBudgetAccessPolicyService),
                app(DomainEventRecorderService::class),
            ),
            new LeverageRecommendationLifecycleService(new MatterBudgetAccessPolicyService),
        );
    }

    private function matterWithMismatchedStaffing(Firm $firm): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);
            (new StaffingPolicyService(new MatterBudgetAccessPolicyService))
                ->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);

            $matter = Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Open]);
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], [
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => ['attorney' => ['expected' => 0, 'actual' => 1, 'consumed_percent' => null]],
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => 0,
                'cost_by_role_cents_json' => [],
                'total_expenses_cents' => 0,
                'work_completion_percent' => 50,
                'work_completion_breakdown_json' => [],
                'projected_hours_by_role_json' => [],
                'projected_overrun_hours_by_role_json' => [],
                'computed_at' => now(),
            ]);

            $attorney = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorney->id, 'role' => FirmUserRole::Attorney]);

            Task::factory()->count(3)->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id,
                'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp,
            ]);

            return $matter;
        });
    }

    public function test_sweeping_a_matter_with_mismatched_staffing_creates_a_recommendation_and_a_domain_event(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);
        $this->matterWithMismatchedStaffing($firm);

        $this->command()->handle();

        $this->runWithFirmContext($firm, function () {
            $recommendation = MatterLeverageRecommendation::query()
                ->where('recommendation_type', MatterLeverageRecommendationType::TaskRoleMismatch)
                ->first();
            $this->assertNotNull($recommendation);
            $this->assertNotNull($recommendation->domain_event_id);
        });
    }

    public function test_sweeping_a_closed_matter_never_creates_a_recommendation(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Closed]);
            MatterBudget::factory()->forMatter($matter)->create();
        });

        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => MatterLeverageRecommendation::query()->count());
        $this->assertSame(0, $count);
    }

    public function test_a_matter_with_no_budget_is_never_swept(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create(['status' => MatterStatus::Open]));

        $this->command()->handle();

        $count = $this->runWithFirmContext($firm, fn () => MatterLeverageRecommendation::query()->count());
        $this->assertSame(0, $count);
    }

    public function test_a_recommendation_flows_through_the_real_automation_engine_to_a_task(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::BillingStaff, 'status' => FirmUserStatus::Active]);

            AutomationRule::factory()->forFirm($firm)->create([
                'event_type' => DomainEventType::MatterLeverageRecommendationCreated,
                'conditions_json' => [],
                'actions_json' => [
                    ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => [
                        'title' => 'Review staffing recommendation', 'description' => 'A new staffing-leverage recommendation was created.',
                    ]],
                ],
            ]);
        });

        $this->matterWithMismatchedStaffing($firm);

        $this->command()->handle();

        (new AutomationEventDispatchJob($firm->id))->handle(
            app(DomainEventClaimService::class), app(AutomationRuleMatchingService::class),
        );
        (new AutomationActionDispatchJob($firm->id))->handle(
            app(AutomationActionExecutionClaimService::class),
            app(AutomationActionHandlerRegistry::class),
            app(AutomationExecutionCompletionService::class),
        );

        $taskCount = $this->runWithFirmContext($firm, fn () => Task::query()->where('title', 'Review staffing recommendation')->count());
        $this->assertGreaterThanOrEqual(1, $taskCount);
    }

    public function test_sweeping_marks_stale_recommendations_past_the_staleness_window(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $recommendationId = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();

            $recommendation = MatterLeverageRecommendation::factory()->forMatter($matter)->create();
            $recommendation->forceFill(['created_at' => now()->subDays(45)])->saveQuietly();

            return $recommendation->id;
        });

        $this->command()->handle();

        $status = $this->runWithFirmContext($firm, fn () => MatterLeverageRecommendation::query()->find($recommendationId)->status);
        $this->assertSame(MatterLeverageRecommendationStatus::Stale, $status);
    }

    public function test_sweeping_never_marks_a_recent_recommendation_stale(): void
    {
        $firm = Firm::factory()->create(['activation_status' => FirmActivationStatus::Activated]);

        $recommendationId = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();

            return MatterLeverageRecommendation::factory()->forMatter($matter)->create()->id;
        });

        $this->command()->handle();

        $status = $this->runWithFirmContext($firm, fn () => MatterLeverageRecommendation::query()->find($recommendationId)->status);
        $this->assertSame(MatterLeverageRecommendationStatus::Open, $status);
    }
}
