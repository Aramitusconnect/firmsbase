<?php

namespace Tests\Feature\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageRecommendationStatus;
use App\Enums\TaskStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterLeverageRecommendation;
use App\Models\MatterType;
use App\Models\Task;
use App\Services\Leverage\BottleneckDetectionService;
use App\Services\Leverage\LeverageAnalysisService;
use App\Services\Leverage\LeverageReportingService;
use App\Services\Leverage\StaffUtilizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeverageReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeverageReportingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeverageReportingService(
            new LeverageAnalysisService,
            new StaffUtilizationService,
            new BottleneckDetectionService,
        );
    }

    private function matterWithAnalysis(Firm $firm, array $hoursByRole, ?MatterType $matterType = null, array $analysisOverrides = []): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $hoursByRole, $matterType, $analysisOverrides) {
            $matter = Matter::factory()->forFirm($firm)->create($matterType ? ['matter_type_id' => $matterType->id] : []);
            $budget = MatterBudget::factory()->forMatter($matter)->create();

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
        });
    }

    public function test_matters_with_highest_attorney_share_are_ordered_descending(): void
    {
        $firm = Firm::factory()->create();
        $this->matterWithAnalysis($firm, ['attorney' => 10, 'paralegal' => 10]);
        $high = $this->matterWithAnalysis($firm, ['attorney' => 18, 'paralegal' => 2]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->mattersWithHighestAttorneyShare($firm));

        $this->assertSame($high->id, $result[0]['matter_id']);
        $this->assertEquals(90.0, $result[0]['attorney_share_percent']);
    }

    public function test_matters_with_lowest_projected_margin_excludes_null_and_orders_ascending(): void
    {
        $firm = Firm::factory()->create();
        $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 5], null, ['projected_margin_percent' => 40]);
        $worst = $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 5], null, ['projected_margin_percent' => -10]);
        $this->matterWithAnalysis($firm, ['attorney' => 5, 'paralegal' => 5], null, ['projected_margin_percent' => null]);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->mattersWithLowestProjectedMargin($firm));

        $this->assertCount(2, $result);
        $this->assertSame($worst->id, $result[0]['matter_id']);
        $this->assertSame(-10, $result[0]['projected_margin_percent']);
    }

    public function test_task_role_mismatch_count_and_delegation_opportunity_only_count_open_recommendations(): void
    {
        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($matter) {
            MatterLeverageRecommendation::factory()->forMatter($matter)->create([
                'evidence_json' => ['mismatched_task_counts_by_role' => ['attorney' => 4]],
            ]);
            MatterLeverageRecommendation::factory()->forMatter($matter)->status(MatterLeverageRecommendationStatus::Acknowledged)->create([
                'dedup_key' => 'other',
                'evidence_json' => ['mismatched_task_counts_by_role' => ['attorney' => 3]],
            ]);
            MatterLeverageRecommendation::factory()->forMatter($matter)->status(MatterLeverageRecommendationStatus::Resolved)->create([
                'dedup_key' => 'resolved',
                'evidence_json' => ['mismatched_task_counts_by_role' => ['attorney' => 100]],
            ]);
        });

        $count = $this->runWithFirmContext($firm, fn () => $this->service->taskRoleMismatchOpenCount($firm));
        $taskCount = $this->runWithFirmContext($firm, fn () => $this->service->estimatedDelegationOpportunityTaskCount($firm));

        $this->assertSame(2, $count);
        $this->assertSame(7, $taskCount);
    }

    public function test_staffing_variance_by_matter_type_groups_and_averages(): void
    {
        $firm = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        $this->matterWithAnalysis($firm, ['attorney' => 10, 'paralegal' => 10], $matterType);
        $this->matterWithAnalysis($firm, ['attorney' => 18, 'paralegal' => 2], $matterType);

        $result = $this->runWithFirmContext($firm, fn () => $this->service->staffingVarianceByMatterType($firm));

        $this->assertCount(1, $result);
        $this->assertSame($matterType->id, $result[0]['matter_type_id']);
        $this->assertSame(2, $result[0]['matter_count']);
        $this->assertSame(70.0, $result[0]['average_attorney_share_percent']);
    }

    public function test_workload_by_role_groups_staff_utilization_output(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, function () use ($firm) {
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]);
            FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Paralegal]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->workloadByRole($firm));

        $this->assertArrayHasKey(FirmUserRole::Attorney->value, $result);
        $this->assertArrayHasKey(FirmUserRole::Paralegal->value, $result);
        $this->assertCount(1, $result[FirmUserRole::Attorney->value]);
    }

    public function test_bottlenecks_aggregates_every_bottleneck_signal(): void
    {
        $firm = Firm::factory()->create();
        $attorney = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]));

        $this->runWithFirmContext($firm, function () use ($firm, $attorney) {
            for ($i = 0; $i < 5; $i++) {
                Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => $attorney->user_id, 'status' => TaskStatus::Overdue]);
            }
            Task::factory()->create(['firm_id' => $firm->id, 'assigned_to' => null, 'status' => TaskStatus::Open]);
        });

        $result = $this->runWithFirmContext($firm, fn () => $this->service->bottlenecks($firm));

        $this->assertArrayHasKey('overdue_task_backlog', $result);
        $this->assertArrayHasKey('deadline_concentration', $result);
        $this->assertArrayHasKey('stalled_document_requests', $result);
        $this->assertCount(1, $result['overdue_task_backlog']);
        $this->assertSame(1, $result['unassigned_task_count']);
    }

    public function test_cross_firm_reporting_is_isolated(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->matterWithAnalysis($firmA, ['attorney' => 10, 'paralegal' => 10]);
        $this->matterWithAnalysis($firmB, ['attorney' => 19, 'paralegal' => 1]);

        $resultA = $this->runWithFirmContext($firmA, fn () => $this->service->mattersWithHighestAttorneyShare($firmA));

        $this->assertCount(1, $resultA);
        $this->assertEquals(50.0, $resultA[0]['attorney_share_percent']);
    }
}
