<?php

namespace App\Services\MatterBudget;

use App\Enums\ExpenseStatus;
use App\Enums\FirmUserRole;
use App\Enums\InvoiceStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Expense;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\EmployeeRateService;

/**
 * MatterBudgetAnalysisService — Predictive Matter Budget Alerts, item
 * 10/11. Recomputes and persists exactly ONE matter_budget_analyses
 * row per Matter, in place — mirrors MatterReadinessService's own
 * "ENTIRE body wrapped in one tenant-context call, including the
 * lookup itself" discipline (its docblock explains exactly why: under
 * FORCE RLS, an unscoped SELECT with no active context returns zero
 * rows even when a row already exists, which would attempt a duplicate
 * INSERT against the unique(matter_id) index on a second recompute()).
 * Callers must already have tenant context active, or must call this
 * from inside their own runWithFirmContext() — this service does not
 * open one itself (same convention as the rest of this feature's
 * services).
 *
 * recompute() returns null (and writes nothing) when the Matter has no
 * current MatterBudget — item 24's own explicit "existing Matters
 * without a budget should show 'No Budget Configured', not zero."
 *
 * Every actual figure is sourced from canonical tables ONLY:
 * - actual hours: time_entries (all rows except Rejected — a rejected
 *   entry never happened; Draft/Submitted/Approved/Invoiced are all
 *   real recorded effort, not gated behind the billing-approval cycle
 *   the way invoicing itself is)
 * - actual expenses: expenses with status=Approved only (an
 *   unapproved expense is not yet a committed cost)
 * - revenue: invoices (status != Void)
 * - internal labor cost: EmployeeRate.cost_rate_cents, via
 *   EmployeeRateService::currentRateFor() at CURRENT time for actual
 *   cost (a documented simplification — not the rate effective on each
 *   individual entry's own worked_on date) and the FIRM's own average
 *   current cost rate for whichever staff currently hold a given role,
 *   for ESTIMATED cost (matter_budget_templates/matter_budgets only
 *   store expected HOURS BY ROLE, never a specific person, so there is
 *   no single per-person rate to apply to an expected-hours figure —
 *   an average-by-role estimate is the honest, deterministic best
 *   available input, not a fabricated number).
 */
class MatterBudgetAnalysisService
{
    public function __construct(
        private readonly MatterProgressService $progress,
        private readonly EmployeeRateService $employeeRates,
    ) {}

    public function recompute(Matter $matter): ?MatterBudgetAnalysis
    {
        $budget = MatterBudget::query()
            ->where('matter_id', $matter->id)
            ->orderByDesc('version')
            ->first();

        if ($budget === null) {
            return null;
        }

        $firm = $matter->firm;

        $hoursByRole = $this->actualHoursByRole($matter);
        $expensesByCategory = $this->actualExpensesByCategory($matter);
        $totalExpensesCents = $this->totalApprovedExpenseCents($matter);

        $laborCostByRole = $this->actualLaborCostByRole($firm, $matter);
        $totalLaborCostCents = (int) array_sum($laborCostByRole);

        $hoursBreakdown = $this->buildHoursBreakdown($budget, $hoursByRole);
        $expensesBreakdown = $this->buildExpensesBreakdown($budget, $expensesByCategory);

        [$invoicedCents, $collectedCents] = $this->revenueTotals($matter);
        $outstandingCents = $invoicedCents - $collectedCents;

        [$estimatedMarginCents, $estimatedMarginPercent, $estimatedLaborCostCents] = $this->estimatedMargin($firm, $budget);
        [$currentMarginCents, $currentMarginPercent] = $this->currentMargin($invoicedCents, $totalLaborCostCents, $totalExpensesCents);

        $progress = $this->progress->compute($matter);
        $timeElapsedPercent = $this->timeElapsedPercent($matter, $budget);

        [$projectedHoursByRole, $projectedOverrunByRole, $projectedFinalCostCents, $projectedMarginCents, $projectedMarginPercent] =
            $this->forecast($budget, $hoursByRole, $totalLaborCostCents, $totalExpensesCents, $progress['completion_percent']);

        return MatterBudgetAnalysis::updateOrCreate(
            ['matter_id' => $matter->id],
            [
                'firm_id' => $matter->firm_id,
                'matter_budget_id' => $budget->id,
                'hours_by_role_json' => $hoursBreakdown,
                'expenses_by_category_json' => $expensesBreakdown,
                'total_labor_cost_cents' => $totalLaborCostCents,
                'cost_by_role_cents_json' => $laborCostByRole,
                'total_expenses_cents' => $totalExpensesCents,
                'revenue_expected_cents' => $budget->expected_revenue_cents,
                'revenue_invoiced_cents' => $invoicedCents,
                'revenue_collected_cents' => $collectedCents,
                'revenue_outstanding_cents' => $outstandingCents,
                'estimated_margin_cents' => $estimatedMarginCents,
                'estimated_margin_percent' => $estimatedMarginPercent,
                'estimated_labor_cost_cents' => $estimatedLaborCostCents,
                'current_margin_cents' => $currentMarginCents,
                'current_margin_percent' => $currentMarginPercent,
                'work_completion_percent' => $progress['completion_percent'],
                'work_completion_breakdown_json' => $progress['breakdown'],
                'time_elapsed_percent' => $timeElapsedPercent,
                'projected_hours_by_role_json' => $projectedHoursByRole,
                'projected_overrun_hours_by_role_json' => $projectedOverrunByRole,
                'projected_final_cost_cents' => $projectedFinalCostCents,
                'projected_margin_cents' => $projectedMarginCents,
                'projected_margin_percent' => $projectedMarginPercent,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, float> role value => actual hours
     */
    private function actualHoursByRole(Matter $matter): array
    {
        $entries = TimeEntry::query()
            ->where('matter_id', $matter->id)
            ->where('status', '!=', TimeEntryStatus::Rejected->value)
            ->get(['user_id', 'seconds']);

        if ($entries->isEmpty()) {
            return [];
        }

        $secondsByUser = $entries->groupBy('user_id')->map(fn ($group) => $group->sum('seconds'));
        $roleByUser = FirmUser::query()
            ->where('firm_id', $matter->firm_id)
            ->whereIn('user_id', $secondsByUser->keys())
            ->pluck('role', 'user_id');

        $hoursByRole = [];

        foreach ($secondsByUser as $userId => $seconds) {
            $role = $roleByUser->get($userId);

            if ($role === null) {
                // No resolvable firm_users membership for this user —
                // cannot attribute to a role; excluded from the
                // role-keyed breakdown (see class docblock).
                continue;
            }

            $roleValue = $role instanceof FirmUserRole ? $role->value : (string) $role;
            $hoursByRole[$roleValue] = ($hoursByRole[$roleValue] ?? 0) + ($seconds / 3600);
        }

        return $hoursByRole;
    }

    /**
     * Predictive Matter Budget Alerts pass built this keyed only by an
     * opaque numeric index (its own docblock claimed role-keying it
     * never actually did — harmless there, since its only caller just
     * array_sum()'d the result). The Leverage Ratio Optimizer needs a
     * REAL role breakdown, so this now keys by FirmUserRole value —
     * array_sum() over the result is identical either way, so
     * total_labor_cost_cents (this method's other caller) is
     * unaffected.
     *
     * @return array<string, int> role value => actual cost cents
     */
    private function actualLaborCostByRole(Firm $firm, Matter $matter): array
    {
        $entries = TimeEntry::query()
            ->where('matter_id', $matter->id)
            ->where('status', '!=', TimeEntryStatus::Rejected->value)
            ->get(['user_id', 'seconds']);

        if ($entries->isEmpty()) {
            return [];
        }

        $secondsByUser = $entries->groupBy('user_id')->map(fn ($group) => $group->sum('seconds'));
        $roleByUser = FirmUser::query()
            ->where('firm_id', $matter->firm_id)
            ->whereIn('user_id', $secondsByUser->keys())
            ->pluck('role', 'user_id');

        $costByRole = [];

        foreach ($secondsByUser as $userId => $seconds) {
            $role = $roleByUser->get($userId);

            if ($role === null) {
                continue;
            }

            $user = User::find($userId);
            $rate = $user === null ? null : $this->employeeRates->currentRateFor($firm, $user);

            if ($rate === null) {
                continue;
            }

            $hours = $seconds / 3600;
            $roleValue = $role instanceof FirmUserRole ? $role->value : (string) $role;
            $costByRole[$roleValue] = ($costByRole[$roleValue] ?? 0) + (int) round($hours * $rate->cost_rate_cents);
        }

        return $costByRole;
    }

    /**
     * @return array<string, int> budget category value => actual cents
     */
    private function actualExpensesByCategory(Matter $matter): array
    {
        $expenses = Expense::query()
            ->where('matter_id', $matter->id)
            ->where('status', ExpenseStatus::Approved->value)
            ->with('category')
            ->get();

        $byCategory = [];

        foreach ($expenses as $expense) {
            $budgetCategory = $expense->category?->budget_category;

            if ($budgetCategory === null) {
                continue;
            }

            $key = $budgetCategory->value;
            $byCategory[$key] = ($byCategory[$key] ?? 0) + $expense->amount_cents;
        }

        return $byCategory;
    }

    private function totalApprovedExpenseCents(Matter $matter): int
    {
        return (int) Expense::query()
            ->where('matter_id', $matter->id)
            ->where('status', ExpenseStatus::Approved->value)
            ->sum('amount_cents');
    }

    /**
     * @param  array<string, float>  $actualHoursByRole
     * @return array<string, array{expected: float, actual: float, consumed_percent: ?int}>
     */
    private function buildHoursBreakdown(MatterBudget $budget, array $actualHoursByRole): array
    {
        $roles = array_unique(array_merge(array_keys($budget->expected_hours_json), array_keys($actualHoursByRole)));
        $breakdown = [];

        foreach ($roles as $role) {
            $expected = (float) ($budget->expected_hours_json[$role] ?? 0);
            $actual = (float) ($actualHoursByRole[$role] ?? 0);

            $breakdown[$role] = [
                'expected' => $expected,
                'actual' => $actual,
                'consumed_percent' => $expected > 0 ? (int) round(($actual / $expected) * 100) : null,
            ];
        }

        return $breakdown;
    }

    /**
     * @param  array<string, int>  $actualExpensesByCategory
     * @return array<string, array{expected_cents: int, actual_cents: int, consumed_percent: ?int}>
     */
    private function buildExpensesBreakdown(MatterBudget $budget, array $actualExpensesByCategory): array
    {
        $categories = array_unique(array_merge(array_keys($budget->expected_expenses_json), array_keys($actualExpensesByCategory)));
        $breakdown = [];

        foreach ($categories as $category) {
            $expected = (int) ($budget->expected_expenses_json[$category] ?? 0);
            $actual = (int) ($actualExpensesByCategory[$category] ?? 0);

            $breakdown[$category] = [
                'expected_cents' => $expected,
                'actual_cents' => $actual,
                'consumed_percent' => $expected > 0 ? (int) round(($actual / $expected) * 100) : null,
            ];
        }

        return $breakdown;
    }

    /**
     * @return array{0: int, 1: int} [invoiced_cents, collected_cents]
     */
    private function revenueTotals(Matter $matter): array
    {
        $invoices = Invoice::query()
            ->where('matter_id', $matter->id)
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->get(['total_cents', 'amount_paid_cents']);

        return [(int) $invoices->sum('total_cents'), (int) $invoices->sum('amount_paid_cents')];
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int} [margin_cents, margin_percent, expected_labor_cost_cents]
     */
    private function estimatedMargin(Firm $firm, MatterBudget $budget): array
    {
        $expectedLaborCostCents = 0;

        foreach ($budget->expected_hours_json as $role => $hours) {
            $avgRateCents = $this->averageCostRateForRole($firm, $role);

            if ($avgRateCents !== null) {
                $expectedLaborCostCents += (float) $hours * $avgRateCents;
            }
        }

        $expectedLaborCostCents = (int) round($expectedLaborCostCents);

        if ($budget->expected_revenue_cents === null) {
            return [null, null, $expectedLaborCostCents];
        }

        $expectedExpensesCents = array_sum($budget->expected_expenses_json);

        $marginCents = (int) round($budget->expected_revenue_cents - $expectedLaborCostCents - $expectedExpensesCents);
        $marginPercent = $budget->expected_revenue_cents > 0
            ? (int) round(($marginCents / $budget->expected_revenue_cents) * 100)
            : null;

        return [$marginCents, $marginPercent, $expectedLaborCostCents];
    }

    /**
     * @return array{0: int, 1: ?int} [margin_cents, margin_percent]
     */
    private function currentMargin(int $invoicedCents, int $totalLaborCostCents, int $totalExpensesCents): array
    {
        $marginCents = $invoicedCents - $totalLaborCostCents - $totalExpensesCents;
        $marginPercent = $invoicedCents > 0 ? (int) round(($marginCents / $invoicedCents) * 100) : null;

        return [$marginCents, $marginPercent];
    }

    private function averageCostRateForRole(Firm $firm, string $role): ?float
    {
        $userIds = FirmUser::query()
            ->where('firm_id', $firm->id)
            ->where('role', $role)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return null;
        }

        $rates = $userIds
            ->map(function ($userId) use ($firm) {
                $user = User::find($userId);

                return $user === null ? null : $this->employeeRates->currentRateFor($firm, $user)?->cost_rate_cents;
            })
            ->filter(fn ($rate) => $rate !== null);

        return $rates->isEmpty() ? null : (float) $rates->avg();
    }

    private function timeElapsedPercent(Matter $matter, MatterBudget $budget): ?int
    {
        if ($matter->opened_at === null || $budget->expected_duration_days === null || $budget->expected_duration_days === 0) {
            return null;
        }

        $daysElapsed = max(0, $matter->opened_at->diffInHours(now(), false) / 24);

        return (int) round(($daysElapsed / $budget->expected_duration_days) * 100);
    }

    /**
     * Run-rate forecasting (item 11): projected total = actual / (work
     * completion as a fraction). No projection is possible at 0%
     * completion (division by zero) — returns empty/null forecast
     * fields rather than a fabricated guess.
     *
     * @param  array<string, float>  $actualHoursByRole
     * @return array{0: array<string, float>, 1: array<string, float>, 2: ?int, 3: ?int, 4: ?int}
     */
    private function forecast(MatterBudget $budget, array $actualHoursByRole, int $totalLaborCostCents, int $totalExpensesCents, int $completionPercent): array
    {
        if ($completionPercent <= 0) {
            return [[], [], null, null, null];
        }

        $completionFraction = $completionPercent / 100;

        $projectedHours = [];
        $projectedOverrun = [];

        foreach ($actualHoursByRole as $role => $actual) {
            $projected = $actual / $completionFraction;
            $expected = (float) ($budget->expected_hours_json[$role] ?? 0);

            $projectedHours[$role] = round($projected, 2);
            $projectedOverrun[$role] = round(max(0, $projected - $expected), 2);
        }

        $projectedFinalCostCents = (int) round(($totalLaborCostCents + $totalExpensesCents) / $completionFraction);

        $projectedMarginCents = null;
        $projectedMarginPercent = null;

        if ($budget->expected_revenue_cents !== null) {
            $projectedMarginCents = (int) round($budget->expected_revenue_cents - $projectedFinalCostCents);
            $projectedMarginPercent = $budget->expected_revenue_cents > 0
                ? (int) round(($projectedMarginCents / $budget->expected_revenue_cents) * 100)
                : null;
        }

        return [$projectedHours, $projectedOverrun, $projectedFinalCostCents, $projectedMarginCents, $projectedMarginPercent];
    }
}
