<?php

namespace Tests\Feature\Leverage;

use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageConfidence;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Enums\MatterLeverageRecommendationType;
use App\Enums\TaskWorkCategory;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterLeverageRecommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\Leverage\LeverageAnalysisService;
use App\Services\Leverage\LeverageRecommendationService;
use App\Services\Leverage\StaffingPolicyService;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeverageRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeverageRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeverageRecommendationService(
            new LeverageAnalysisService,
            new StaffingPolicyService(new MatterBudgetAccessPolicyService),
            new DomainEventRecorderService,
        );
    }

    private function owner(Firm $firm): FirmUser
    {
        return FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]);
    }

    private function matterWithAnalysis(Firm $firm, array $hoursByRole, array $analysisOverrides = [], array $budgetOverrides = []): Matter
    {
        $matter = Matter::factory()->forFirm($firm)->create();
        $budget = MatterBudget::factory()->forMatter($matter)->create(array_merge([
            'expected_hours_json' => ['attorney' => 5, 'paralegal' => 15],
        ], $budgetOverrides));

        $hoursBreakdown = [];
        foreach ($hoursByRole as $role => $hours) {
            $hoursBreakdown[$role] = ['expected' => 0, 'actual' => $hours, 'consumed_percent' => null];
        }

        MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], array_merge([
            'firm_id' => $firm->id,
            'matter_budget_id' => $budget->id,
            'hours_by_role_json' => $hoursBreakdown,
            'expenses_by_category_json' => [],
            'total_labor_cost_cents' => 0,
            'cost_by_role_cents_json' => [],
            'total_expenses_cents' => 0,
            'work_completion_percent' => 50,
            'work_completion_breakdown_json' => [],
            'projected_hours_by_role_json' => [],
            'projected_overrun_hours_by_role_json' => [],
            'computed_at' => now(),
        ], $analysisOverrides));

        return $matter;
    }

    public function test_attorney_time_high_fires_when_variance_exceeds_the_floor(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            // Expected 25%/75%, actual attorney=12/paralegal=8 -> 60%/40% (35pt variance).
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8]);

            return $this->service->evaluate($matter);
        });

        $r = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::AttorneyTimeHigh);
        $this->assertNotNull($r);
        $this->assertSame(MatterLeverageConfidence::Medium, $r->confidence);
        $this->assertNotNull($r->domain_event_id);
    }

    public function test_no_recommendation_fires_when_the_mix_is_close_to_expected(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15]);

            return $this->service->evaluate($matter);
        });

        $this->assertEmpty($created);
    }

    public function test_evaluating_the_same_matter_twice_never_creates_a_duplicate(): void
    {
        $firm = Firm::factory()->create();

        [$first, $second] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8]);
            $first = $this->service->evaluate($matter);
            $second = $this->service->evaluate($matter);

            return [$first, $second];
        });

        $this->assertNotEmpty($first);
        $this->assertEmpty($second);
    }

    public function test_task_role_mismatch_requires_an_explicit_staffing_policy(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 1]);
            $attorney = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorney->id, 'role' => FirmUserRole::Attorney]);

            Task::factory()->count(3)->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id,
                'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp,
            ]);

            // No StaffingPolicyService expectation configured -> no mismatch can be claimed.
            return $this->service->evaluate($matter);
        });

        $mismatch = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::TaskRoleMismatch);
        $this->assertNull($mismatch);
    }

    public function test_task_role_mismatch_fires_with_high_confidence_when_policy_is_configured(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = $this->owner($firm);
            (new StaffingPolicyService(new MatterBudgetAccessPolicyService))
                ->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);

            $matter = $this->matterWithAnalysis($firm, ['attorney' => 1]);
            $attorney = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorney->id, 'role' => FirmUserRole::Attorney]);

            Task::factory()->count(3)->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id,
                'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp,
            ]);

            return $this->service->evaluate($matter);
        });

        $mismatch = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::TaskRoleMismatch);
        $this->assertNotNull($mismatch);
        $this->assertSame(MatterLeverageConfidence::High, $mismatch->confidence);
        $this->assertSame(3, $mismatch->evidence_json['mismatched_task_counts_by_role']['attorney']);
    }

    public function test_task_role_mismatch_does_not_fire_below_the_minimum_task_count(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $owner = $this->owner($firm);
            (new StaffingPolicyService(new MatterBudgetAccessPolicyService))
                ->setExpectation($firm, $owner, TaskWorkCategory::DocumentFollowUp, [FirmUserRole::Paralegal]);

            $matter = $this->matterWithAnalysis($firm, ['attorney' => 1]);
            $attorney = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorney->id, 'role' => FirmUserRole::Attorney]);

            // Only 1 mismatched task -> below MIN_MISMATCHED_TASKS.
            Task::factory()->create([
                'firm_id' => $firm->id, 'matter_id' => $matter->id,
                'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp,
            ]);

            return $this->service->evaluate($matter);
        });

        $mismatch = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::TaskRoleMismatch);
        $this->assertNull($mismatch);
    }

    public function test_projected_margin_at_risk_fires_when_projected_is_below_target(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15], [
                'projected_margin_percent' => 20,
            ], [
                'target_gross_margin_percent' => 40,
            ]);

            return $this->service->evaluate($matter);
        });

        $r = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::ProjectedMarginAtRisk);
        $this->assertNotNull($r);
        $this->assertSame(MatterLeverageConfidence::High, $r->confidence);
    }

    public function test_labor_cost_ahead_of_progress_fires_when_cost_pace_outstrips_completion(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15], [
                'cost_by_role_cents_json' => ['attorney' => 90000],
                'estimated_labor_cost_cents' => 100000,
                'work_completion_percent' => 30,
            ]);

            return $this->service->evaluate($matter);
        });

        // 90000/100000 = 90% consumed vs 30% complete -> 60pt gap.
        $r = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::LaborCostAheadOfProgress);
        $this->assertNotNull($r);
        $this->assertSame(MatterLeverageConfidence::Medium, $r->confidence);
    }

    public function test_flat_fee_labor_risk_fires_when_labor_cost_consumes_most_of_the_fee(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15], [
                'cost_by_role_cents_json' => ['attorney' => 70000],
                'work_completion_percent' => 40,
            ], [
                'expected_revenue_cents' => 100000,
            ]);

            return $this->service->evaluate($matter);
        });

        $r = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::FlatFeeLaborRisk);
        $this->assertNotNull($r);
    }

    public function test_flat_fee_labor_risk_never_fires_once_the_matter_is_complete(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15], [
                'total_labor_cost_cents' => 70000,
                'work_completion_percent' => 100,
            ], [
                'expected_revenue_cents' => 100000,
            ]);

            return $this->service->evaluate($matter);
        });

        $r = collect($created)->firstWhere('recommendation_type', MatterLeverageRecommendationType::FlatFeeLaborRisk);
        $this->assertNull($r);
    }

    public function test_every_new_recommendation_emits_a_matching_domain_event(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8]);

            return $this->service->evaluate($matter);
        });

        $this->assertNotEmpty($created);

        foreach ($created as $r) {
            $this->assertNotNull($r->domain_event_id);
            $event = $this->runWithFirmContext($firm, fn () => DomainEvent::find($r->domain_event_id));
            $this->assertSame(DomainEventType::MatterLeverageRecommendationCreated, $event->event_type);
        }
    }

    public function test_a_resolved_recommendation_allows_a_fresh_one_to_be_created(): void
    {
        $firm = Firm::factory()->create();

        [$first, $second] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8]);
            $first = $this->service->evaluate($matter);

            $r = MatterLeverageRecommendation::query()->where('matter_id', $matter->id)->first();
            $r->update(['status' => MatterLeverageRecommendationStatus::Resolved]);

            $second = $this->service->evaluate($matter);

            return [$first, $second];
        });

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
    }
}
