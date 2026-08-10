<?php

namespace Tests\Feature\MatterBudget;

use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\MatterBudgetExpenseCategory;
use App\Enums\TaskStatus;
use App\Enums\TimeEntryStatus;
use App\Models\EmployeeRate;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EmployeeRateService;
use App\Services\MatterBudget\MatterBudgetAnalysisService;
use App\Services\MatterBudget\MatterProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatterBudgetAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private MatterBudgetAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MatterBudgetAnalysisService(new MatterProgressService, new EmployeeRateService);
    }

    private function matter(Firm $firm): Matter
    {
        return Matter::factory()->forFirm($firm)->create(['opened_at' => now()->subDays(10)]);
    }

    public function test_a_matter_with_no_budget_produces_no_analysis(): void
    {
        $firm = Firm::factory()->create();

        $result = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);

            return $this->service->recompute($matter);
        });

        $this->assertNull($result);
        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => MatterBudgetAnalysis::query()->count()));
    }

    public function test_actual_hours_are_attributed_by_role_and_consumed_percent_is_computed(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [FirmUserRole::Attorney->value => 10],
                'expected_expenses_json' => [],
                'expected_revenue_cents' => null,
            ]);

            $attorneyUser = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $attorneyUser->id, 'role' => FirmUserRole::Attorney]);
            TimeEntry::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $attorneyUser->id, 'seconds' => 5 * 3600, 'status' => TimeEntryStatus::Approved]);
            // A rejected entry must never count.
            TimeEntry::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $attorneyUser->id, 'seconds' => 100 * 3600, 'status' => TimeEntryStatus::Rejected]);

            return $this->service->recompute($matter);
        });

        $this->assertEquals(10.0, $analysis->hours_by_role_json['attorney']['expected']);
        $this->assertEquals(5.0, $analysis->hours_by_role_json['attorney']['actual']);
        $this->assertSame(50, $analysis->hours_by_role_json['attorney']['consumed_percent']);
    }

    public function test_actual_labor_cost_uses_the_employee_rate_cost_rate(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => [], 'expected_expenses_json' => []]);

            $user = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $user->id, 'role' => FirmUserRole::Paralegal]);
            EmployeeRate::factory()->forFirm($firm)->forUser($user)->create(['cost_rate_cents' => 5000]);
            TimeEntry::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $user->id, 'seconds' => 2 * 3600, 'status' => TimeEntryStatus::Approved]);

            return $this->service->recompute($matter);
        });

        // 2 hours * 5000 cents/hr = 10000 cents.
        $this->assertSame(10000, $analysis->total_labor_cost_cents);
    }

    public function test_approved_expenses_are_mapped_into_the_closed_budget_categories(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [],
                'expected_expenses_json' => [MatterBudgetExpenseCategory::FilingCourtCosts->value => 10000],
            ]);

            $mappedCategory = ExpenseCategory::factory()->forFirm($firm)->create(['budget_category' => MatterBudgetExpenseCategory::FilingCourtCosts]);
            $unmappedCategory = ExpenseCategory::factory()->forFirm($firm)->create(['budget_category' => null]);

            Expense::factory()->forFirm($firm)->create([
                'matter_id' => $matter->id, 'expense_category_id' => $mappedCategory->id,
                'amount_cents' => 4000, 'status' => ExpenseStatus::Approved,
            ]);
            Expense::factory()->forFirm($firm)->create([
                'matter_id' => $matter->id, 'expense_category_id' => $unmappedCategory->id,
                'amount_cents' => 1500, 'status' => ExpenseStatus::Approved,
            ]);
            // Draft expenses never count.
            Expense::factory()->forFirm($firm)->create([
                'matter_id' => $matter->id, 'expense_category_id' => $mappedCategory->id,
                'amount_cents' => 99999, 'status' => ExpenseStatus::Draft,
            ]);

            return $this->service->recompute($matter);
        });

        $this->assertSame(4000, $analysis->expenses_by_category_json['filing_court_costs']['actual_cents']);
        $this->assertArrayNotHasKey('unmapped', $analysis->expenses_by_category_json);
        // Total still includes the unmapped expense — never silently dropped.
        $this->assertSame(5500, $analysis->total_expenses_cents);
    }

    public function test_revenue_totals_exclude_void_invoices(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => [], 'expected_expenses_json' => [], 'expected_revenue_cents' => 100000]);

            Invoice::factory()->forMatter($matter)->create(['status' => InvoiceStatus::Sent, 'total_cents' => 50000, 'amount_paid_cents' => 20000]);
            Invoice::factory()->forMatter($matter)->create(['status' => InvoiceStatus::Void, 'total_cents' => 99999, 'amount_paid_cents' => 0]);

            return $this->service->recompute($matter);
        });

        $this->assertSame(50000, $analysis->revenue_invoiced_cents);
        $this->assertSame(20000, $analysis->revenue_collected_cents);
        $this->assertSame(30000, $analysis->revenue_outstanding_cents);
    }

    public function test_current_margin_is_invoiced_revenue_minus_actual_costs(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => [], 'expected_expenses_json' => []]);

            Invoice::factory()->forMatter($matter)->create(['status' => InvoiceStatus::Sent, 'total_cents' => 100000, 'amount_paid_cents' => 0]);

            $category = ExpenseCategory::factory()->forFirm($firm)->create(['budget_category' => MatterBudgetExpenseCategory::OtherExpenses]);
            Expense::factory()->forFirm($firm)->create(['matter_id' => $matter->id, 'expense_category_id' => $category->id, 'amount_cents' => 15000, 'status' => ExpenseStatus::Approved]);

            return $this->service->recompute($matter);
        });

        // 100000 invoiced - 0 labor - 15000 expenses = 85000, margin% = 85.
        $this->assertSame(85000, $analysis->current_margin_cents);
        $this->assertSame(85, $analysis->current_margin_percent);
    }

    public function test_forecast_projects_zero_at_zero_percent_completion(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => [FirmUserRole::Attorney->value => 10], 'expected_expenses_json' => []]);

            // No tasks/documents at all -> 0% completion.
            return $this->service->recompute($matter);
        });

        $this->assertSame(0, $analysis->work_completion_percent);
        $this->assertSame([], $analysis->projected_hours_by_role_json);
        $this->assertNull($analysis->projected_final_cost_cents);
    }

    public function test_forecast_projects_an_overrun_via_run_rate_at_partial_completion(): void
    {
        $firm = Firm::factory()->create();

        $analysis = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create([
                'expected_hours_json' => [FirmUserRole::Attorney->value => 10],
                'expected_expenses_json' => [],
            ]);

            $user = User::factory()->create();
            FirmUser::factory()->forFirm($firm)->create(['user_id' => $user->id, 'role' => FirmUserRole::Attorney]);
            TimeEntry::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'user_id' => $user->id, 'seconds' => 8 * 3600, 'status' => TimeEntryStatus::Approved]);

            // 1 of 2 tasks completed -> 50% work completion.
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Completed]);
            Task::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'status' => TaskStatus::Open]);

            return $this->service->recompute($matter);
        });

        $this->assertSame(50, $analysis->work_completion_percent);
        // 8 actual hours / 50% completion = 16 projected hours; 16 - 10 expected = 6 overrun.
        $this->assertEquals(16.0, $analysis->projected_hours_by_role_json['attorney']);
        $this->assertEquals(6.0, $analysis->projected_overrun_hours_by_role_json['attorney']);
    }

    public function test_recompute_is_idempotent_and_updates_the_same_row(): void
    {
        $firm = Firm::factory()->create();

        [$first, $second] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            MatterBudget::factory()->forMatter($matter)->create(['expected_hours_json' => [], 'expected_expenses_json' => []]);

            $first = $this->service->recompute($matter);
            $second = $this->service->recompute($matter);

            return [$first, $second];
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => MatterBudgetAnalysis::query()->count()));
    }

    public function test_a_new_budget_revision_is_reflected_in_the_next_recompute(): void
    {
        $firm = Firm::factory()->create();

        [$analysis1, $budget2, $analysis2] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = $this->matter($firm);
            $budget1 = MatterBudget::factory()->forMatter($matter)->create(['version' => 1, 'expected_hours_json' => [FirmUserRole::Attorney->value => 5], 'expected_expenses_json' => []]);
            $analysis1 = $this->service->recompute($matter);

            $budget2 = MatterBudget::factory()->forMatter($matter)->create(['version' => 2, 'expected_hours_json' => [FirmUserRole::Attorney->value => 20], 'expected_expenses_json' => []]);
            $analysis2 = $this->service->recompute($matter);

            return [$analysis1, $budget2, $analysis2];
        });

        $this->assertEquals(5.0, $analysis1->hours_by_role_json['attorney']['expected']);
        $this->assertSame($budget2->id, $analysis2->matter_budget_id);
        $this->assertEquals(20.0, $analysis2->hours_by_role_json['attorney']['expected']);
    }
}
