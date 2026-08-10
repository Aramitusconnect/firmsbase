<?php

namespace App\Services\MatterBudget;

use App\Models\Firm;
use App\Models\MatterBudgetAnalysis;
use Illuminate\Support\Collection;

/**
 * MatterBudgetReportingService — Predictive Matter Budget Alerts,
 * item 23. Firm-level rollups over matter_budget_analyses — the ONE
 * centralized place this feature's own cross-matter reporting logic
 * lives (mirrors AccountingReportingService's own "single centralized
 * place" role for the Accounting domain), so a future consumer (the
 * Leverage Ratio Optimizer, natural-language reporting) has one
 * service to call rather than re-deriving these comparisons itself.
 *
 * Every method reads matter_budget_analyses only — the already-
 * computed, already-firm-scoped rollup MatterBudgetAnalysisService
 * maintains — never re-derives figures from time_entries/expenses/
 * invoices directly. A Matter with no budget/analysis simply never
 * appears in any of these lists (never a fabricated zero-row).
 */
class MatterBudgetReportingService
{
    /**
     * Matters where any role/expense category has already crossed
     * 100% consumed.
     *
     * @return Collection<int, MatterBudgetAnalysis>
     */
    public function mattersOverBudget(Firm $firm): Collection
    {
        return $this->analysesFor($firm)->filter(fn (MatterBudgetAnalysis $a) => $this->isOverBudget($a))->values();
    }

    /**
     * Matters not yet over budget, but whose forecast run-rate projects
     * a real (non-zero) overrun for at least one role — a leading
     * indicator, not yet a breach.
     *
     * @return Collection<int, MatterBudgetAnalysis>
     */
    public function mattersTrendingOverBudget(Firm $firm): Collection
    {
        return $this->analysesFor($firm)
            ->reject(fn (MatterBudgetAnalysis $a) => $this->isOverBudget($a))
            ->filter(fn (MatterBudgetAnalysis $a) => collect($a->projected_overrun_hours_by_role_json)->contains(fn ($hours) => $hours > 0))
            ->values();
    }

    /**
     * @return Collection<int, MatterBudgetAnalysis> ordered ascending by current_margin_percent (worst first)
     */
    public function lowestMarginMatters(Firm $firm, int $limit = 10): Collection
    {
        return $this->analysesFor($firm)
            ->filter(fn (MatterBudgetAnalysis $a) => $a->current_margin_percent !== null)
            ->sortBy('current_margin_percent')
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, MatterBudgetAnalysis> ordered descending by current_margin_percent (best first)
     */
    public function highestMarginMatters(Firm $firm, int $limit = 10): Collection
    {
        return $this->analysesFor($firm)
            ->filter(fn (MatterBudgetAnalysis $a) => $a->current_margin_percent !== null)
            ->sortByDesc('current_margin_percent')
            ->take($limit)
            ->values();
    }

    /**
     * Average hours-consumed% for a given role, grouped by the
     * Matter's own matter_type_id — a positive/negative variance
     * signal per matter type (e.g. "Immigration AOS matters are
     * consistently running 20% over their attorney-hours budget").
     *
     * @return array<int, array{matter_type_id: int, average_consumed_percent: float, matter_count: int}>
     */
    public function hourVarianceByMatterType(Firm $firm, string $role): array
    {
        return $this->varianceByGroup($firm, $role, fn (MatterBudgetAnalysis $a) => $a->matter->matter_type_id, 'matter_type_id');
    }

    /**
     * Same as hourVarianceByMatterType(), grouped by
     * primary_practice_area_id instead.
     *
     * @return array<int, array{practice_area_id: int, average_consumed_percent: float, matter_count: int}>
     */
    public function hourVarianceByPracticeArea(Firm $firm, string $role): array
    {
        return $this->varianceByGroup($firm, $role, fn (MatterBudgetAnalysis $a) => $a->matter->primary_practice_area_id, 'practice_area_id');
    }

    /**
     * Average expense-consumed% for a given MatterBudgetExpenseCategory,
     * across all matters that have a value for that category.
     */
    public function costVariance(Firm $firm, string $expenseCategory): ?float
    {
        $percents = $this->analysesFor($firm)
            ->map(fn (MatterBudgetAnalysis $a) => $a->expenses_by_category_json[$expenseCategory]['consumed_percent'] ?? null)
            ->filter(fn ($p) => $p !== null);

        return $percents->isEmpty() ? null : round((float) $percents->avg(), 1);
    }

    private function isOverBudget(MatterBudgetAnalysis $analysis): bool
    {
        $hoursOver = collect($analysis->hours_by_role_json)->contains(fn ($data) => ($data['consumed_percent'] ?? 0) >= 100);
        $expensesOver = collect($analysis->expenses_by_category_json)->contains(fn ($data) => ($data['consumed_percent'] ?? 0) >= 100);

        return $hoursOver || $expensesOver;
    }

    /**
     * @return Collection<int, MatterBudgetAnalysis>
     */
    private function analysesFor(Firm $firm): Collection
    {
        return MatterBudgetAnalysis::query()->where('firm_id', $firm->id)->with('matter')->get();
    }

    /**
     * @return array<int, array{average_consumed_percent: float, matter_count: int}>
     */
    private function varianceByGroup(Firm $firm, string $role, callable $groupKeyResolver, string $keyName): array
    {
        $grouped = $this->analysesFor($firm)
            ->map(function (MatterBudgetAnalysis $a) use ($role, $groupKeyResolver) {
                $consumedPercent = $a->hours_by_role_json[$role]['consumed_percent'] ?? null;
                $groupKey = $groupKeyResolver($a);

                return $consumedPercent === null || $groupKey === null ? null : ['group' => $groupKey, 'consumed_percent' => $consumedPercent];
            })
            ->filter()
            ->groupBy('group');

        $result = [];

        foreach ($grouped as $groupKey => $entries) {
            $result[] = [
                $keyName => $groupKey,
                'average_consumed_percent' => round((float) $entries->avg('consumed_percent'), 1),
                'matter_count' => $entries->count(),
            ];
        }

        return $result;
    }
}
