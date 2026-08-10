<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\DomainEventType;
use App\Enums\MatterBudgetAlertSeverity;
use App\Enums\MatterBudgetAlertType;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Models\MatterBudgetAnalysis;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\MatterBudget\MatterBudgetAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterBudgetAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetAlertService(new DomainEventRecorderService);
    }

    private function matter(Firm $firm): Matter
    {
        return Matter::factory()->forFirm($firm)->create();
    }

    private function budget(Matter $matter, array $overrides = []): MatterBudget
    {
        return MatterBudget::factory()->forMatter($matter)->create(array_merge([
            'expected_hours_json' => ['attorney' => 10],
            'expected_expenses_json' => [],
        ], $overrides));
    }

    private function analysis(Matter $matter, MatterBudget $budget, array $overrides = []): MatterBudgetAnalysis
    {
        $attributes = array_merge([
            'firm_id' => $matter->firm_id,
            'matter_budget_id' => $budget->id,
            'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
            'expenses_by_category_json' => [],
            'total_labor_cost_cents' => 0,
            'total_expenses_cents' => 0,
            'work_completion_percent' => 50,
            'work_completion_breakdown_json' => [],
            'projected_hours_by_role_json' => [],
            'projected_overrun_hours_by_role_json' => [],
            'computed_at' => now(),
        ], $overrides);

        // A test-only stand-in for MatterBudgetAnalysisService's own
        // updateOrCreate() shape — a real Matter has at most one
        // current analysis row at a time.
        return MatterBudgetAnalysis::updateOrCreate(['matter_id' => $matter->id], $attributes);
    }

    public function test_a_warning_tier_alert_fires_at_or_above_the_warning_threshold(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $roleAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::RoleHoursThreshold);
        $this->assertNotNull($roleAlert);
        $this->assertSame(MatterBudgetAlertSeverity::Warning, $roleAlert->severity);
        $this->assertSame(75, $roleAlert->threshold_percent_crossed);
        $this->assertSame('attorney', $roleAlert->metric_key);
    }

    public function test_an_overbudget_alert_fires_at_or_above_one_hundred_percent(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 11, 'consumed_percent' => 110]],
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $roleAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::RoleHoursThreshold);
        $this->assertSame(MatterBudgetAlertSeverity::OverBudget, $roleAlert->severity);
        $this->assertSame(100, $roleAlert->threshold_percent_crossed);
    }

    public function test_below_the_warning_threshold_no_alert_fires(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 2, 'consumed_percent' => 20]],
                'work_completion_percent' => 20,
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $roleAlerts = collect($created)->where('alert_type', MatterBudgetAlertType::RoleHoursThreshold);
        $this->assertCount(0, $roleAlerts);
    }

    public function test_evaluating_the_same_analysis_twice_never_creates_a_duplicate_alert(): void
    {
        $firm = Firm::factory()->create();

        [$firstRun, $secondRun] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget);

            $first = $this->service->evaluate($matter, $budget, $analysis);
            $second = $this->service->evaluate($matter, $budget, $analysis);

            return [$first, $second];
        });

        $this->assertNotEmpty($firstRun);
        $this->assertEmpty($secondRun);
    }

    public function test_crossing_a_higher_tier_creates_a_new_alert_alongside_the_lower_one(): void
    {
        $firm = Firm::factory()->create();

        [$warningAlerts, $overCount] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);

            $warningAnalysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
            ]);
            $warningAlerts = $this->service->evaluate($matter, $budget, $warningAnalysis);

            $overAnalysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 11, 'consumed_percent' => 110]],
            ]);
            $overAlerts = $this->service->evaluate($matter, $budget, $overAnalysis);

            return [$warningAlerts, count($overAlerts)];
        });

        $this->assertNotEmpty($warningAlerts);
        $this->assertGreaterThan(0, $overCount);

        $alertCount = $this->runWithFirmContext($firm, fn () => MatterBudgetAlert::query()->where('metric_key', 'attorney')->where('alert_type', MatterBudgetAlertType::RoleHoursThreshold->value)->count());
        $this->assertSame(2, $alertCount);
    }

    public function test_a_new_budget_version_gets_a_fresh_alert_slate(): void
    {
        $firm = Firm::factory()->create();

        $secondVersionAlerts = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget1 = $this->budget($matter, ['version' => 1]);
            $analysis1 = $this->analysis($matter, $budget1);
            $this->service->evaluate($matter, $budget1, $analysis1);

            $budget2 = $this->budget($matter, ['version' => 2]);
            $analysis2 = $this->analysis($matter, $budget2);

            return $this->service->evaluate($matter, $budget2, $analysis2);
        });

        // Same consumed_percent, but scoped to a DIFFERENT budget version -> alerts fresh, not deduped away.
        $this->assertNotEmpty($secondVersionAlerts);
    }

    public function test_usage_ahead_of_progress_fires_when_the_gap_is_material(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
                'work_completion_percent' => 20,
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $usageAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::UsageAheadOfProgress);
        $this->assertNotNull($usageAlert);
        $this->assertSame(MatterBudgetAlertSeverity::Info, $usageAlert->severity);
    }

    public function test_usage_ahead_of_progress_does_not_fire_for_a_small_gap(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 8, 'consumed_percent' => 80]],
                'work_completion_percent' => 70,
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $usageAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::UsageAheadOfProgress);
        $this->assertNull($usageAlert);
    }

    public function test_margin_below_target_fires_when_current_margin_is_under_the_target(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter, ['target_gross_margin_percent' => 40]);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 2, 'consumed_percent' => 20]],
                'work_completion_percent' => 20,
                'current_margin_percent' => 15,
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $marginAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::MarginBelowTarget);
        $this->assertNotNull($marginAlert);
    }

    public function test_projected_overrun_fires_when_a_role_has_a_positive_projected_overrun(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget, [
                'hours_by_role_json' => ['attorney' => ['expected' => 10, 'actual' => 2, 'consumed_percent' => 20]],
                'work_completion_percent' => 20,
                'projected_overrun_hours_by_role_json' => ['attorney' => 5.5],
            ]);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $overrunAlert = collect($created)->firstWhere('alert_type', MatterBudgetAlertType::ProjectedOverrun);
        $this->assertNotNull($overrunAlert);
        $this->assertSame(MatterBudgetAlertSeverity::Warning, $overrunAlert->severity);
    }

    public function test_every_new_alert_emits_a_matching_domain_event(): void
    {
        $firm = Firm::factory()->create();

        $created = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget = $this->budget($matter);
            $analysis = $this->analysis($matter, $budget);

            return $this->service->evaluate($matter, $budget, $analysis);
        });

        $this->assertNotEmpty($created);

        foreach ($created as $alert) {
            $this->assertNotNull($alert->domain_event_id);
            $event = $this->runWithFirmContext($firm, fn () => DomainEvent::find($alert->domain_event_id));
            $this->assertSame(DomainEventType::MatterBudgetThresholdCrossed, $event->event_type);
        }
    }
}
