<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskWorkCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\Task;
use App\Models\User;
use App\Services\Leverage\LeverageAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeverageAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeverageAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeverageAnalysisService;
    }

    private function matterWithAnalysis(Firm $firm, array $hoursByRole, array $costByRoleCents, array $analysisOverrides = []): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $hoursByRole, $costByRoleCents, $analysisOverrides) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $budget = MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => ['attorney' => 5, 'paralegal' => 15]]);

            $hoursBreakdown = [];
            foreach ($hoursByRole as $role => $hours) {
                $hoursBreakdown[$role] = ['expected' => 0, 'actual' => $hours, 'consumed_percent' => null];
            }

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], array_merge([
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => $hoursBreakdown,
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => array_sum($costByRoleCents),
                'cost_by_role_cents_json' => $costByRoleCents,
                'total_expenses_cents' => 0,
                'work_completion_percent' => 50,
                'work_completion_breakdown_json' => [],
                'projected_hours_by_role_json' => [],
                'projected_overrun_hours_by_role_json' => [],
                'computed_at' => now(),
            ], $analysisOverrides));

            return $matter;
        });
    }

    public function test_a_matter_with_no_budget_is_insufficient_data(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(MatterLeverageStatus::InsufficientData, $result['status']);
        $this->assertFalse($result['has_budget']);
    }

    public function test_hours_shares_and_ratio_are_computed_correctly(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8], []);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertEquals(20.0, $result['total_hours']);
        $this->assertEquals(12.0, $result['attorney_hours']);
        $this->assertEquals(8.0, $result['support_hours']);
        $this->assertSame(60, $result['attorney_share_percent']);
        $this->assertSame(40, $result['support_share_percent']);
        $this->assertSame(1.5, $result['attorney_to_support_ratio']['attorney']);
        $this->assertSame(1.0, $result['attorney_to_support_ratio']['support']);
    }

    public function test_cost_by_role_and_average_cost_per_hour(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 10], ['attorney' => 150000]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(150000, $result['total_labor_cost_cents']);
        $this->assertSame(15000, $result['average_cost_per_hour_cents']);
    }

    public function test_expected_vs_actual_mix_variance(): void
    {
        $firm = Firm::factory()->create();
        // Budget expects attorney=5, paralegal=15 (25%/75%); actual is attorney=12, paralegal=8 (60%/40%).
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8], []);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(25, $result['expected_mix_percent']['attorney']);
        $this->assertSame(75, $result['expected_mix_percent']['paralegal']);
        $this->assertSame(60, $result['actual_mix_percent']['attorney']);
        $this->assertSame(40, $result['actual_mix_percent']['paralegal']);
        $this->assertSame(35, $result['mix_variance_points']['attorney']);
        $this->assertSame(-35, $result['mix_variance_points']['paralegal']);
    }

    public function test_task_category_distribution_counts_by_category_and_assignee_role(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 1], []);

        $this->runWithFirmContext($firm, function () use ($firm, $matter) {
            $attorney = User::factory()->create();
            $paralegal = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorney->id, 'role' => FirmUserRole::Attorney]);
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $paralegal->id, 'role' => FirmUserRole::Paralegal]);

            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'assigned_to' => $paralegal->id, 'task_category' => TaskWorkCategory::DocumentFollowUp]);
            // No category -> excluded entirely, never guessed.
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'assigned_to' => $attorney->id, 'task_category' => null]);
            // Cancelled -> excluded.
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'assigned_to' => $attorney->id, 'task_category' => TaskWorkCategory::DocumentFollowUp, 'status' => TaskStatus::Cancelled]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(2, $result['task_category_distribution']['document_follow_up']['attorney']);
        $this->assertSame(1, $result['task_category_distribution']['document_follow_up']['paralegal']);
    }

    public function test_status_is_inefficient_when_variance_is_severe_and_margin_is_below_target(): void
    {
        $firm = Firm::factory()->create();
        // MatterBudgetFactory's own default target_gross_margin_percent is 40.
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 12, 'paralegal' => 8], [], [
            'current_margin_percent' => 10,
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(MatterLeverageStatus::Inefficient, $result['status']);
    }

    public function test_status_is_watch_when_variance_is_moderate_with_no_confirmed_margin_impact(): void
    {
        $firm = Firm::factory()->create();
        // Expected attorney 25%, actual attorney hours=6/(6+14)=30% -> 5pt variance is too small;
        // use a moderate mix instead: attorney=8, paralegal=12 -> 40% actual vs 25% expected = 15pt.
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 8, 'paralegal' => 12], []);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(MatterLeverageStatus::Watch, $result['status']);
    }

    public function test_status_is_healthy_when_actual_mix_closely_matches_expected(): void
    {
        $firm = Firm::factory()->create();
        // Expected attorney 25%/paralegal 75%; actual attorney=5/paralegal=15 -> exact match.
        $matter = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 15], []);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->analyze($matter));

        $this->assertSame(MatterLeverageStatus::Healthy, $result['status']);
    }
}
