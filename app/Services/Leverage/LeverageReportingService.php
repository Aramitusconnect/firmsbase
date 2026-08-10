<?php

namespace App\Services\Leverage;

use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageRecommendationType;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudgetAnalysis;
use App\Models\MatterLeverageRecommendation;
use Illuminate\Support\Collection;

/**
 * LeverageReportingService — Leverage Ratio Optimizer, item 21. The ONE
 * centralized place this feature's own cross-matter/cross-staff
 * reporting logic lives — mirrors MatterBudgetReportingService's own
 * "single centralized place" role, so the Matter/Firm UI and any
 * future natural-language reporting layer have one service to call
 * rather than re-deriving these comparisons themselves (item 21's own
 * explicit instruction: "Do not put calculations directly inside
 * Filament pages").
 *
 * Every per-Matter figure here is produced by re-running
 * LeverageAnalysisService::analyze() against each Matter that already
 * has a matter_budget_analyses row (never re-derived independently) —
 * the same pattern HistoricalBenchmarkService already established.
 *
 * "Estimated delegation opportunity" is reported as a TASK COUNT, not a
 * dollar estimate — TimeEntry carries no task_id anywhere in this
 * codebase (confirmed by this pass's own repeated audit), so a precise
 * per-mismatched-task labor-cost difference is not provable and is
 * deliberately not fabricated here — the same reasoning already
 * documented in LeverageRecommendationService's own TaskRoleMismatch
 * evidence (task counts, never hours).
 *
 * Must be called from inside an already-active tenant context.
 */
class LeverageReportingService
{
    public function __construct(
        private readonly LeverageAnalysisService $leverageAnalysis,
        private readonly StaffUtilizationService $staffUtilization,
        private readonly BottleneckDetectionService $bottleneckDetection,
    ) {}

    /**
     * @return array<int, array{matter_id: int, attorney_share_percent: float}>
     */
    public function mattersWithHighestAttorneyShare(Firm $firm, int $limit = 10): array
    {
        return $this->analyzedMatters($firm)
            ->sortByDesc('analysis.attorney_share_percent')
            ->take($limit)
            ->map(fn (array $row) => [
                'matter_id' => $row['matter']->id,
                'attorney_share_percent' => $row['analysis']['attorney_share_percent'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{matter_id: int, support_share_percent: float}>
     */
    public function mattersWithHighestSupportShare(Firm $firm, int $limit = 10): array
    {
        return $this->analyzedMatters($firm)
            ->sortByDesc('analysis.support_share_percent')
            ->take($limit)
            ->map(fn (array $row) => [
                'matter_id' => $row['matter']->id,
                'support_share_percent' => $row['analysis']['support_share_percent'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{matter_id: int, projected_margin_percent: int}>
     */
    public function mattersWithLowestProjectedMargin(Firm $firm, int $limit = 10): array
    {
        return $this->analyzedMatters($firm)
            ->filter(fn (array $row) => $row['analysis']['projected_margin_percent'] !== null)
            ->sortBy('analysis.projected_margin_percent')
            ->take($limit)
            ->map(fn (array $row) => [
                'matter_id' => $row['matter']->id,
                'projected_margin_percent' => $row['analysis']['projected_margin_percent'],
            ])
            ->values()
            ->all();
    }

    public function taskRoleMismatchOpenCount(Firm $firm): int
    {
        return $this->openTaskRoleMismatchRecommendations($firm)->count();
    }

    /**
     * See this class's own docblock on why this is a task count, not a
     * dollar estimate.
     */
    public function estimatedDelegationOpportunityTaskCount(Firm $firm): int
    {
        return (int) $this->openTaskRoleMismatchRecommendations($firm)
            ->sum(fn (MatterLeverageRecommendation $r) => array_sum($r->evidence_json['mismatched_task_counts_by_role'] ?? []));
    }

    /**
     * @return array<int, array{matter_type_id: int, average_attorney_share_percent: float, matter_count: int}>
     */
    public function staffingVarianceByMatterType(Firm $firm): array
    {
        return $this->varianceByGroup($firm, fn (Matter $m) => $m->matter_type_id, 'matter_type_id');
    }

    /**
     * @return array<int, array{practice_area_id: int, average_attorney_share_percent: float, matter_count: int}>
     */
    public function staffingVarianceByPracticeArea(Firm $firm): array
    {
        return $this->varianceByGroup($firm, fn (Matter $m) => $m->primary_practice_area_id, 'practice_area_id');
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> workload rows keyed by FirmUserRole value
     */
    public function workloadByRole(Firm $firm): array
    {
        $byRole = [];

        foreach ($this->staffUtilization->workloadForFirm($firm) as $workload) {
            $roleValue = $workload['role'] instanceof FirmUserRole ? $workload['role']->value : $workload['role'];
            $byRole[$roleValue][] = $workload;
        }

        return $byRole;
    }

    /**
     * @return array{overdue_task_backlog: array, deadline_concentration: array, stalled_document_requests: array, unassigned_task_count: int}
     */
    public function bottlenecks(Firm $firm): array
    {
        return [
            'overdue_task_backlog' => $this->bottleneckDetection->staffWithOverdueTaskBacklog($firm),
            'deadline_concentration' => $this->bottleneckDetection->deadlineConcentration($firm),
            'stalled_document_requests' => $this->bottleneckDetection->stalledDocumentRequestItems($firm),
            'unassigned_task_count' => $this->bottleneckDetection->unassignedTaskCount($firm),
        ];
    }

    /**
     * @return Collection<int, MatterLeverageRecommendation>
     */
    private function openTaskRoleMismatchRecommendations(Firm $firm): Collection
    {
        return MatterLeverageRecommendation::query()
            ->where('firm_id', $firm->id)
            ->where('recommendation_type', MatterLeverageRecommendationType::TaskRoleMismatch)
            ->whereIn('status', ['open', 'acknowledged'])
            ->get();
    }

    /**
     * @return Collection<int, array{matter: Matter, analysis: array}>
     */
    private function analyzedMatters(Firm $firm): Collection
    {
        return MatterBudgetAnalysis::query()
            ->where('firm_id', $firm->id)
            ->with('matter')
            ->get()
            ->filter(fn (MatterBudgetAnalysis $a) => $a->matter !== null)
            ->map(fn (MatterBudgetAnalysis $a) => [
                'matter' => $a->matter,
                'analysis' => $this->leverageAnalysis->analyze($a->matter),
            ])
            ->filter(fn (array $row) => $row['analysis']['has_recorded_hours']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function varianceByGroup(Firm $firm, callable $groupKeyResolver, string $keyName): array
    {
        $grouped = $this->analyzedMatters($firm)
            ->map(function (array $row) use ($groupKeyResolver) {
                $groupKey = $groupKeyResolver($row['matter']);

                return $groupKey === null ? null : ['group' => $groupKey, 'attorney_share_percent' => $row['analysis']['attorney_share_percent']];
            })
            ->filter()
            ->groupBy('group');

        $result = [];

        foreach ($grouped as $groupKey => $entries) {
            $result[] = [
                $keyName => $groupKey,
                'average_attorney_share_percent' => round((float) $entries->avg('attorney_share_percent'), 1),
                'matter_count' => $entries->count(),
            ];
        }

        return $result;
    }
}
