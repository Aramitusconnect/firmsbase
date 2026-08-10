<?php

namespace Tests\Feature\Leverage;

use App\Enums\MatterStatus;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterType;
use App\Services\Leverage\HistoricalBenchmarkService;
use App\Services\Leverage\LeverageAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalBenchmarkServiceTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalBenchmarkService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HistoricalBenchmarkService(new LeverageAnalysisService);
    }

    private function closedComparableMatter(Firm $firm, MatterType $matterType, int $attorneyHours, int $paralegalHours, int $marginPercent, int $durationDays): Matter
    {
        return $this->runWithFirmContext($firm, function () use ($firm, $matterType, $attorneyHours, $paralegalHours, $marginPercent, $durationDays) {
            $matter = Matter::factory()->forFirm($firm)->create([
                'matter_type_id' => $matterType->id,
                'status' => MatterStatus::Closed,
                'opened_at' => now()->subDays($durationDays + 30),
                'closed_at' => now()->subDays(30),
            ]);
            $budget = MatterBudget::factory()->forMatter($matter)->create();

            MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], [
                'firm_id' => $firm->id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => [
                    'attorney' => ['expected' => 0, 'actual' => $attorneyHours, 'consumed_percent' => null],
                    'paralegal' => ['expected' => 0, 'actual' => $paralegalHours, 'consumed_percent' => null],
                ],
                'expenses_by_category_json' => [],
                'total_labor_cost_cents' => 0,
                'cost_by_role_cents_json' => ['attorney' => $attorneyHours * 15000, 'paralegal' => $paralegalHours * 5500],
                'total_expenses_cents' => 0,
                'current_margin_percent' => $marginPercent,
                'work_completion_percent' => 100,
                'work_completion_breakdown_json' => [],
                'projected_hours_by_role_json' => [],
                'projected_overrun_hours_by_role_json' => [],
                'computed_at' => now(),
            ]);

            return $matter;
        });
    }

    public function test_fewer_than_the_minimum_sample_size_is_reported_as_insufficient(): void
    {
        $firm = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->closedComparableMatter($firm, $matterType, 10, 10, 30, 60);
        }

        $result = $this->runWithFirmContext($firm, fn () => $this->service->benchmarkForMatterType($firm, $matterType));

        $this->assertFalse($result['sufficient_sample']);
        $this->assertSame(3, $result['sample_size']);
        $this->assertSame(5, $result['minimum_sample_size']);
        $this->assertArrayNotHasKey('average_attorney_share_percent', $result);
    }

    public function test_at_the_minimum_sample_size_returns_deterministic_averages(): void
    {
        $firm = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->closedComparableMatter($firm, $matterType, 20, 20, 40, 90);
        }

        $result = $this->runWithFirmContext($firm, fn () => $this->service->benchmarkForMatterType($firm, $matterType));

        $this->assertTrue($result['sufficient_sample']);
        $this->assertSame(5, $result['sample_size']);
        $this->assertSame(50.0, $result['average_attorney_share_percent']);
        $this->assertSame(50.0, $result['average_support_share_percent']);
        $this->assertSame(40.0, $result['average_margin_percent']);
        $this->assertSame(90, $result['average_duration_days']);
    }

    public function test_matters_without_a_budget_or_recorded_hours_are_excluded_from_the_sample(): void
    {
        $firm = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->closedComparableMatter($firm, $matterType, 10, 10, 30, 60);
        }

        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create([
            'matter_type_id' => $matterType->id,
            'status' => MatterStatus::Closed,
            'opened_at' => now()->subDays(90),
            'closed_at' => now()->subDays(30),
        ]));

        $result = $this->runWithFirmContext($firm, fn () => $this->service->benchmarkForMatterType($firm, $matterType));

        $this->assertTrue($result['sufficient_sample']);
        $this->assertSame(5, $result['sample_size']);
    }

    public function test_open_matters_of_the_same_type_are_not_comparable(): void
    {
        $firm = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->closedComparableMatter($firm, $matterType, 10, 10, 30, 60);
        }

        $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create([
            'matter_type_id' => $matterType->id,
            'status' => MatterStatus::Open,
        ]));

        $result = $this->runWithFirmContext($firm, fn () => $this->service->benchmarkForMatterType($firm, $matterType));

        $this->assertSame(5, $result['sample_size']);
    }

    public function test_benchmarks_never_combine_data_across_firms(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterType = MatterType::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->closedComparableMatter($firmA, $matterType, 10, 10, 30, 60);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->closedComparableMatter($firmB, $matterType, 90, 10, 90, 60);
        }

        $resultA = $this->runWithFirmContext($firmA, fn () => $this->service->benchmarkForMatterType($firmA, $matterType));

        $this->assertSame(5, $resultA['sample_size']);
        $this->assertSame(30.0, $resultA['average_margin_percent']);
    }
}
