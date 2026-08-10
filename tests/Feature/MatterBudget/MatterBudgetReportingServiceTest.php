<?php

namespace Tests\Feature\MatterBudget;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterType;
use App\Services\MatterBudget\MatterBudgetReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterBudgetReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetReportingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetReportingService;
    }

    private function analysisFor(Firm $firm, array $overrides = []): MatterBudgetAnalysis
    {
        $matter = Matter::factory()->forFirm($firm)->create();
        $budget = MatterBudget::factory()->forMatter($matter)->create();

        return MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], array_merge([
            'firm_id' => $firm->id,
            'matter_budget_id' => $budget->id,
            'hours_by_role_json' => [],
            'expenses_by_category_json' => [],
            'total_labor_cost_cents' => 0,
            'total_expenses_cents' => 0,
            'work_completion_percent' => 0,
            'work_completion_breakdown_json' => [],
            'projected_hours_by_role_json' => [],
            'projected_overrun_hours_by_role_json' => [],
            'computed_at' => now(),
        ], $overrides));
    }

    public function test_matters_over_budget_includes_a_matter_with_a_role_at_or_above_one_hundred_percent(): void
    {
        $firm = Firm::factory()->create();

        $overBudget = $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, [
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 12, 'consumed_percent' => 120]],
        ]));
        $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, [
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 5, 'consumed_percent' => 50]],
        ]));

        $result = $this->runWithFirmContext($firm, fn () => $this->service->mattersOverBudget($firm));

        $this->assertCount(1, $result);
        $this->assertSame($overBudget->id, $result->first()->id);
    }

    public function test_matters_trending_over_budget_excludes_matters_already_over_budget(): void
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, [
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 12, 'consumed_percent' => 120]],
            'projected_overrun_hours_by_role_json' => ['attorney' => 5],
        ]));
        $trending = $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, [
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 6, 'consumed_percent' => 60]],
            'projected_overrun_hours_by_role_json' => ['attorney' => 3],
        ]));

        $result = $this->runWithFirmContext($firm, fn () => $this->service->mattersTrendingOverBudget($firm));

        $this->assertCount(1, $result);
        $this->assertSame($trending->id, $result->first()->id);
    }

    public function test_lowest_and_highest_margin_matters_are_ordered_correctly(): void
    {
        $firm = Firm::factory()->create();

        $worst = $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, ['current_margin_percent' => -20]));
        $best = $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, ['current_margin_percent' => 60]));
        $this->runWithFirmContext($firm, fn () => $this->analysisFor($firm, ['current_margin_percent' => 30]));

        $lowest = $this->runWithFirmContext($firm, fn () => $this->service->lowestMarginMatters($firm));
        $highest = $this->runWithFirmContext($firm, fn () => $this->service->highestMarginMatters($firm));

        $this->assertSame($worst->id, $lowest->first()->id);
        $this->assertSame($best->id, $highest->first()->id);
    }

    public function test_hour_variance_by_matter_type_averages_consumed_percent_within_the_same_type(): void
    {
        $firm = Firm::factory()->create();

        $result = $this->runWithFirmContext($firm, function () use ($firm) {
            $matterType = MatterType::factory()->create();

            $matterA = Matter::factory()->forFirm($firm)->create(['matter_type_id' => $matterType->id]);
            $budgetA = MatterBudget::factory()->forMatter($matterA)->create();
            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matterA->id], [
                'firm_id' => $firm->id, 'matter_budget_id' => $budgetA->id,
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
                'expenses_by_category_json' => [], 'total_expenses_cents' => 0, 'work_completion_percent' => 0,
                'work_completion_breakdown_json' => [], 'projected_hours_by_role_json' => [], 'projected_overrun_hours_by_role_json' => [], 'computed_at' => now(),
            ]);

            $matterB = Matter::factory()->forFirm($firm)->create(['matter_type_id' => $matterType->id]);
            $budgetB = MatterBudget::factory()->forMatter($matterB)->create();
            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matterB->id], [
                'firm_id' => $firm->id, 'matter_budget_id' => $budgetB->id,
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 12, 'consumed_percent' => 120]],
                'expenses_by_category_json' => [], 'total_expenses_cents' => 0, 'work_completion_percent' => 0,
                'work_completion_breakdown_json' => [], 'projected_hours_by_role_json' => [], 'projected_overrun_hours_by_role_json' => [], 'computed_at' => now(),
            ]);

            return $this->service->hourVarianceByMatterType($firm, 'attorney');
        });

        $this->assertCount(1, $result);
        $this->assertSame(100.0, $result[0]['average_consumed_percent']);
        $this->assertSame(2, $result[0]['matter_count']);
    }

    public function test_reports_never_see_a_different_firms_analyses(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $this->runWithFirmContext($firmB, fn () => $this->analysisFor($firmB, [
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 15, 'consumed_percent' => 150]],
        ]));

        $result = $this->runWithFirmContext($firmA, fn () => $this->service->mattersOverBudget($firmA));

        $this->assertCount(0, $result);
    }
}
