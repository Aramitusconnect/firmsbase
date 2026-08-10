<?php

namespace App\Services\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskWorkCategory;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAnalysis;
use App\Models\Task;

/**
 * LeverageAnalysisService — Leverage Ratio Optimizer, item 3/4/9/20.
 * A pure, stateless computation — deliberately NOT a second "recompute
 * in place, one current row per Matter" cache table the way
 * MatterBudgetAnalysisService/MatterReadinessService are: every
 * expensive aggregation this service needs (hours/cost by role) is
 * already cached on MatterBudgetAnalysis (extended by this pass to
 * carry cost_by_role_cents_json — see that migration's own docblock,
 * "a small, additive extension... rather than a second cost
 * calculator"); the only NEW aggregation here (task counts by
 * category/role) is a cheap, already-indexed query, not worth its own
 * cache table. Called live by the UI, the recommendation engine, and
 * the sweep command alike.
 *
 * Never recalculates margin, revenue, or labor cost independently —
 * every dollar figure is read straight off MatterBudgetAnalysis. This
 * service adds only genuinely NEW derivations: percentage shares, the
 * Attorney:Support ratio, expected-vs-actual mix variance, task-role
 * distribution, and the transparent (never black-box) Matter-level
 * status.
 *
 * Privacy is NOT enforced here — like MatterBudgetAnalysisService
 * itself, this computes everything unconditionally; the caller (UI,
 * recommendation engine) is responsible for gating cost/margin fields
 * behind MatterBudgetAccessPolicyService::canViewProfitability()
 * before ever displaying or acting on them for an unauthorized role.
 */
class LeverageAnalysisService
{
    /**
     * Support-tier roles for the Attorney:Support leverage ratio — the
     * two roles the master spec itself names alongside Attorney
     * throughout (item 3's own "ATTORNEY HOURS / PARALEGAL HOURS /
     * LEGAL ASSISTANT HOURS / OTHER ROLE HOURS" breakdown groups
     * Paralegal and Legal Assistant as the comparison point for
     * Attorney, with every other role bucketed as "other").
     */
    private const SUPPORT_ROLES = [FirmUserRole::Paralegal, FirmUserRole::LegalAssistant];

    /**
     * @return array<string, mixed>
     */
    public function analyze(Matter $matter): array
    {
        $analysis = MatterBudgetAnalysis::query()->where('matter_id', $matter->id)->first();
        $budget = MatterBudget::query()->where('matter_id', $matter->id)->orderByDesc('version')->first();

        $hoursByRole = $this->extractActualHours($analysis);
        $costByRole = $analysis?->cost_by_role_cents_json ?? [];

        $attorneyHours = $hoursByRole[FirmUserRole::Attorney->value] ?? 0.0;
        $supportHours = $this->sumRoles($hoursByRole, self::SUPPORT_ROLES);
        $totalHours = array_sum($hoursByRole);
        $otherHours = $totalHours - $attorneyHours - $supportHours;

        $shares = $this->shares($hoursByRole, $totalHours);
        $ratio = $this->attorneyToSupportRatio($attorneyHours, $supportHours);

        $totalLaborCostCents = (int) array_sum($costByRole);
        $averageCostPerHourCents = $totalHours > 0 ? (int) round($totalLaborCostCents / $totalHours) : null;

        $expectedMixPercent = $budget !== null ? $this->percentMix($budget->expected_hours_json) : null;
        $actualMixPercent = $totalHours > 0 ? $shares : null;
        $varianceByRolePoints = ($expectedMixPercent !== null && $actualMixPercent !== null)
            ? $this->variancePoints($expectedMixPercent, $actualMixPercent)
            : null;

        $taskCategoryDistribution = $this->taskCategoryDistribution($matter);

        $result = [
            'matter_id' => $matter->id,
            'has_budget' => $budget !== null,
            'has_recorded_hours' => $totalHours > 0,
            'hours_by_role' => $hoursByRole,
            'total_hours' => $totalHours,
            'attorney_hours' => $attorneyHours,
            'support_hours' => $supportHours,
            'other_hours' => max(0.0, $otherHours),
            'attorney_share_percent' => $shares[FirmUserRole::Attorney->value] ?? null,
            'support_share_percent' => $totalHours > 0 ? (int) round(($supportHours / $totalHours) * 100) : null,
            'attorney_to_support_ratio' => $ratio,
            'cost_by_role_cents' => $costByRole,
            'total_labor_cost_cents' => $totalLaborCostCents,
            'average_cost_per_hour_cents' => $averageCostPerHourCents,
            'expected_mix_percent' => $expectedMixPercent,
            'actual_mix_percent' => $actualMixPercent,
            'mix_variance_points' => $varianceByRolePoints,
            'task_category_distribution' => $taskCategoryDistribution,
            'current_margin_percent' => $analysis?->current_margin_percent,
            'projected_margin_percent' => $analysis?->projected_margin_percent,
            'target_gross_margin_percent' => $budget?->target_gross_margin_percent,
            'work_completion_percent' => $analysis?->work_completion_percent,
            'estimated_labor_cost_cents' => $analysis?->estimated_labor_cost_cents,
            'expected_revenue_cents' => $budget?->expected_revenue_cents,
        ];

        $result['status'] = $this->status($result);

        return $result;
    }

    private function extractActualHours(?MatterBudgetAnalysis $analysis): array
    {
        if ($analysis === null) {
            return [];
        }

        $hours = [];

        foreach ($analysis->hours_by_role_json as $role => $data) {
            $hours[$role] = (float) ($data['actual'] ?? 0);
        }

        return $hours;
    }

    /**
     * @param  array<int, FirmUserRole>  $roles
     */
    private function sumRoles(array $hoursByRole, array $roles): float
    {
        $sum = 0.0;

        foreach ($roles as $role) {
            $sum += (float) ($hoursByRole[$role->value] ?? 0);
        }

        return $sum;
    }

    /**
     * @return array<string, int>
     */
    private function shares(array $hoursByRole, float $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $shares = [];

        foreach ($hoursByRole as $role => $hours) {
            $shares[$role] = (int) round(($hours / $total) * 100);
        }

        return $shares;
    }

    /**
     * @return array{attorney: float, support: float}|null null when
     *                                                     there is no
     *                                                     support
     *                                                     time to
     *                                                     compare
     *                                                     against
     *                                                     (division
     *                                                     by zero is
     *                                                     not a
     *                                                     meaningful
     *                                                     ratio)
     */
    private function attorneyToSupportRatio(float $attorneyHours, float $supportHours): ?array
    {
        if ($supportHours <= 0) {
            return null;
        }

        return ['attorney' => round($attorneyHours / $supportHours, 2), 'support' => 1.0];
    }

    /**
     * @param  array<string, int|float>  $expectedHoursByRole
     * @return array<string, int>
     */
    private function percentMix(array $expectedHoursByRole): array
    {
        $total = array_sum($expectedHoursByRole);

        if ($total <= 0) {
            return [];
        }

        $percent = [];

        foreach ($expectedHoursByRole as $role => $hours) {
            $percent[$role] = (int) round((((float) $hours) / $total) * 100);
        }

        return $percent;
    }

    /**
     * @return array<string, int> expected minus actual, in percentage
     *                            points, for every role present in
     *                            either mix
     */
    private function variancePoints(array $expectedMixPercent, array $actualMixPercent): array
    {
        $roles = array_unique(array_merge(array_keys($expectedMixPercent), array_keys($actualMixPercent)));
        $variance = [];

        foreach ($roles as $role) {
            $variance[$role] = ($actualMixPercent[$role] ?? 0) - ($expectedMixPercent[$role] ?? 0);
        }

        return $variance;
    }

    /**
     * Task COUNTS by (task_category, assigned FirmUserRole) — never
     * hours, since TimeEntry carries no task_id linkage anywhere in
     * this codebase (confirmed by this pass's own audit) and adding
     * one would mean invasively modifying the canonical billing
     * TimeEntry table for an unproven precision gain. Task count by
     * category+role is a real, directly-measurable, zero-new-coupling
     * signal instead — a deliberate, documented substitution (see
     * LeverageRecommendationService's own docblock for how this
     * shapes TASK_ROLE_MISMATCH evidence).
     *
     * @return array<string, array<string, int>> category value => [role value => count]
     */
    private function taskCategoryDistribution(Matter $matter): array
    {
        $tasks = Task::query()
            ->where('matter_id', $matter->id)
            ->where('status', '!=', TaskStatus::Cancelled->value)
            ->whereNotNull('task_category')
            ->whereNotNull('assigned_to')
            ->get(['task_category', 'assigned_to']);

        if ($tasks->isEmpty()) {
            return [];
        }

        $roleByUser = FirmUser::query()
            ->where('firm_id', $matter->firm_id)
            ->whereIn('user_id', $tasks->pluck('assigned_to')->unique())
            ->pluck('role', 'user_id');

        $distribution = [];

        foreach ($tasks as $task) {
            $role = $roleByUser->get($task->assigned_to);

            if ($role === null) {
                continue;
            }

            $categoryValue = $task->task_category instanceof TaskWorkCategory ? $task->task_category->value : (string) $task->task_category;
            $roleValue = $role instanceof FirmUserRole ? $role->value : (string) $role;

            $distribution[$categoryValue][$roleValue] = ($distribution[$categoryValue][$roleValue] ?? 0) + 1;
        }

        return $distribution;
    }

    /**
     * Transparent, explicitly-ruled status — never a numeric score.
     *
     * INSUFFICIENT_DATA: no budget configured, or no hours recorded
     * yet — nothing meaningful to compare.
     * INEFFICIENT: a budget-configured Matter whose actual mix
     * diverges from its expected mix by 25+ points on ANY role AND
     * whose current margin has fallen below its own target (both
     * conditions required — a mix divergence alone is a signal, not
     * proof of inefficiency, matching item 9's own "do not call a
     * variance 'wrong' automatically").
     * WATCH: a budget-configured Matter with a 15+ point mix
     * divergence but no confirmed margin impact yet.
     * HEALTHY: everything else with real data to evaluate.
     */
    private function status(array $result): MatterLeverageStatus
    {
        if (! $result['has_budget'] || ! $result['has_recorded_hours']) {
            return MatterLeverageStatus::InsufficientData;
        }

        $variance = $result['mix_variance_points'];
        $maxAbsVariance = $variance === null ? 0 : max(array_map('abs', $variance) ?: [0]);

        $marginBelowTarget = $result['target_gross_margin_percent'] !== null
            && $result['current_margin_percent'] !== null
            && $result['current_margin_percent'] < $result['target_gross_margin_percent'];

        if ($maxAbsVariance >= 25 && $marginBelowTarget) {
            return MatterLeverageStatus::Inefficient;
        }

        if ($maxAbsVariance >= 15) {
            return MatterLeverageStatus::Watch;
        }

        return MatterLeverageStatus::Healthy;
    }
}
