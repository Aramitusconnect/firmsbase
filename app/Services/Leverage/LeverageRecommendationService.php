<?php

namespace App\Services\Leverage;

use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Enums\MatterLeverageConfidence;
use App\Enums\MatterLeverageRecommendationType;
use App\Enums\TaskWorkCategory;
use App\Models\Matter;
use App\Models\MatterLeverageRecommendation;
use App\Services\Automation\DomainEventRecorderService;

/**
 * LeverageRecommendationService — Leverage Ratio Optimizer, item 3/9/
 * 10/12/13/14/19/23. Evaluates a Matter's own LeverageAnalysisService
 * output against Firm-configured StaffingPolicyService expectations
 * and creates a MatterLeverageRecommendation for any newly-warranted
 * signal — dedup is a database partial-unique-index guarantee (open/
 * acknowledged only, scoped per matter+type+dedup_key), never a
 * convention re-checked here.
 *
 * Six matter-level recommendation types are implemented, each with its
 * own deterministic trigger and rule-derived confidence (never an LLM
 * judgment call):
 *
 * - ATTORNEY_TIME_HIGH / SUPPORT_STAFF_UNDERUSED: the SAME
 *   mix_variance_points LeverageAnalysisService already computes,
 *   read from opposite ends — Attorney 25+ points above its expected
 *   share, or a support role 25+ points below. Confidence MEDIUM
 *   (item 14's own rule: "Matter Type staffing template strongly
 *   differs from actual mix").
 * - TASK_ROLE_MISMATCH: only fires when a Firm has EXPLICITLY
 *   configured a StaffingPolicyService expectation for the task
 *   category involved (a category with no configured policy has no
 *   Firm opinion at all — see StaffingPolicyService's own docblock) —
 *   Confidence HIGH (item 14's own rule). Evidence is TASK COUNTS by
 *   role, never hours: TimeEntry carries no task_id linkage anywhere
 *   in this codebase (confirmed by this pass's own audit), so
 *   "N Attorney hours were recorded on this category" is not provable
 *   — task counts are the honest, directly-measurable substitute (see
 *   LeverageAnalysisService's own docblock on this same point). A
 *   minimum of 2 mismatched tasks avoids flagging a single incidental
 *   assignment.
 * - PROJECTED_MARGIN_AT_RISK: a direct comparison of
 *   MatterBudgetAnalysis's own projected_margin_percent against the
 *   Matter's own target_gross_margin_percent — no new calculation.
 *   Confidence HIGH.
 * - LABOR_COST_AHEAD_OF_PROGRESS: actual labor cost as a percentage of
 *   the SAME estimated_labor_cost_cents MatterBudgetAnalysisService
 *   already derives (extended by this pass to persist it — see that
 *   migration's own docblock), compared against work completion.
 *   Confidence MEDIUM.
 * - FLAT_FEE_LABOR_RISK: actual labor cost as a percentage of the
 *   Matter's own expected_revenue_cents (the flat-fee proxy this
 *   codebase already uses — MatterBudgetTemplate's own docblock
 *   documents expected_revenue_cents as "primarily for flat-fee
 *   matters"), while work is still incomplete. Confidence MEDIUM.
 *
 * Every recommendation's evidence_json carries the real underlying
 * figures (including cost/margin where the recommendation type is
 * inherently about cost) — this service does NOT gate on viewer
 * authorization (same convention as MatterBudgetAnalysisService
 * itself); the UI/consumer layer is responsible for hiding
 * profitability-sensitive fields from an unauthorized role before
 * ever displaying them (see MatterBudgetAccessPolicyService).
 *
 * Must be called from inside an already-active tenant context.
 */
class LeverageRecommendationService
{
    /**
     * Percentage-point / percent materiality floors — deliberately
     * chosen, documented constants, not derived from anywhere else in
     * this codebase. Mirrors MatterBudgetAlertService's own
     * USAGE_AHEAD_OF_PROGRESS_GAP_POINTS precedent for "never spam for
     * a tiny variance."
     */
    private const MIX_VARIANCE_POINTS_FLOOR = 25;

    private const LABOR_COST_AHEAD_POINTS_FLOOR = 25;

    private const FLAT_FEE_LABOR_RISK_PERCENT_FLOOR = 60;

    private const MIN_MISMATCHED_TASKS = 2;

    public function __construct(
        private readonly LeverageAnalysisService $analysisService,
        private readonly StaffingPolicyService $staffingPolicy,
        private readonly DomainEventRecorderService $domainEvents,
    ) {}

    /**
     * @return array<int, MatterLeverageRecommendation> newly-created
     *                                                  recommendations
     *                                                  only
     */
    public function evaluate(Matter $matter): array
    {
        $analysis = $this->analysisService->analyze($matter);
        $created = [];

        foreach ($this->evaluateMixVariance($matter, $analysis) as $r) {
            $created[] = $r;
        }

        foreach ($this->evaluateTaskRoleMismatch($matter, $analysis) as $r) {
            $created[] = $r;
        }

        if ($r = $this->evaluateProjectedMarginAtRisk($matter, $analysis)) {
            $created[] = $r;
        }

        if ($r = $this->evaluateLaborCostAheadOfProgress($matter, $analysis)) {
            $created[] = $r;
        }

        if ($r = $this->evaluateFlatFeeLaborRisk($matter, $analysis)) {
            $created[] = $r;
        }

        return array_values(array_filter($created));
    }

    /**
     * @return array<int, MatterLeverageRecommendation>
     */
    private function evaluateMixVariance(Matter $matter, array $analysis): array
    {
        if ($analysis['mix_variance_points'] === null) {
            return [];
        }

        $created = [];
        $attorneyVariance = $analysis['mix_variance_points'][FirmUserRole::Attorney->value] ?? 0;

        if ($attorneyVariance >= self::MIX_VARIANCE_POINTS_FLOOR) {
            $r = $this->createIfNew(
                $matter, MatterLeverageRecommendationType::AttorneyTimeHigh, 'attorney_share', MatterLeverageConfidence::Medium,
                [
                    'attorney_share_percent' => $analysis['actual_mix_percent'][FirmUserRole::Attorney->value] ?? null,
                    'expected_attorney_share_percent' => $analysis['expected_mix_percent'][FirmUserRole::Attorney->value] ?? null,
                    'variance_points' => $attorneyVariance,
                    'attorney_hours' => $analysis['attorney_hours'],
                ],
            );
            if ($r !== null) {
                $created[] = $r;
            }
        }

        foreach ([FirmUserRole::Paralegal, FirmUserRole::LegalAssistant] as $role) {
            $variance = $analysis['mix_variance_points'][$role->value] ?? 0;

            if ($variance <= -self::MIX_VARIANCE_POINTS_FLOOR) {
                $r = $this->createIfNew(
                    $matter, MatterLeverageRecommendationType::SupportStaffUnderused, 'support_share', MatterLeverageConfidence::Medium,
                    [
                        'role' => $role->value,
                        'actual_share_percent' => $analysis['actual_mix_percent'][$role->value] ?? null,
                        'expected_share_percent' => $analysis['expected_mix_percent'][$role->value] ?? null,
                        'variance_points' => $variance,
                    ],
                );
                if ($r !== null) {
                    $created[] = $r;
                }

                break; // one SupportStaffUnderused recommendation per evaluation is enough signal.
            }
        }

        return $created;
    }

    /**
     * @return array<int, MatterLeverageRecommendation>
     */
    private function evaluateTaskRoleMismatch(Matter $matter, array $analysis): array
    {
        $created = [];

        foreach ($analysis['task_category_distribution'] as $categoryValue => $countsByRole) {
            $category = TaskWorkCategory::tryFrom($categoryValue);

            if ($category === null) {
                continue;
            }

            $recommendedRoles = $this->staffingPolicy->recommendedRolesFor($matter->firm, $category);

            if ($recommendedRoles === null) {
                continue;
            }

            $recommendedValues = array_map(fn (FirmUserRole $r) => $r->value, $recommendedRoles);
            $mismatchedCounts = [];

            foreach ($countsByRole as $roleValue => $count) {
                if (! in_array($roleValue, $recommendedValues, true)) {
                    $mismatchedCounts[$roleValue] = $count;
                }
            }

            $totalMismatched = array_sum($mismatchedCounts);

            if ($totalMismatched < self::MIN_MISMATCHED_TASKS) {
                continue;
            }

            $r = $this->createIfNew(
                $matter, MatterLeverageRecommendationType::TaskRoleMismatch, $categoryValue, MatterLeverageConfidence::High,
                [
                    'task_category' => $categoryValue,
                    'recommended_roles' => $recommendedValues,
                    'mismatched_task_counts_by_role' => $mismatchedCounts,
                ],
            );

            if ($r !== null) {
                $created[] = $r;
            }
        }

        return $created;
    }

    private function evaluateProjectedMarginAtRisk(Matter $matter, array $analysis): ?MatterLeverageRecommendation
    {
        if (
            $analysis['target_gross_margin_percent'] === null
            || $analysis['projected_margin_percent'] === null
            || $analysis['projected_margin_percent'] >= $analysis['target_gross_margin_percent']
        ) {
            return null;
        }

        return $this->createIfNew(
            $matter, MatterLeverageRecommendationType::ProjectedMarginAtRisk, 'projected_margin', MatterLeverageConfidence::High,
            [
                'projected_margin_percent' => $analysis['projected_margin_percent'],
                'target_gross_margin_percent' => $analysis['target_gross_margin_percent'],
            ],
        );
    }

    private function evaluateLaborCostAheadOfProgress(Matter $matter, array $analysis): ?MatterLeverageRecommendation
    {
        $estimated = $analysis['estimated_labor_cost_cents'];
        $completion = $analysis['work_completion_percent'];

        if ($estimated === null || $estimated <= 0 || $completion === null || $completion <= 0) {
            return null;
        }

        $laborConsumedPercent = (int) round(($analysis['total_labor_cost_cents'] / $estimated) * 100);
        $gap = $laborConsumedPercent - $completion;

        if ($gap < self::LABOR_COST_AHEAD_POINTS_FLOOR) {
            return null;
        }

        return $this->createIfNew(
            $matter, MatterLeverageRecommendationType::LaborCostAheadOfProgress, 'labor_cost_pace', MatterLeverageConfidence::Medium,
            [
                'labor_cost_consumed_percent' => $laborConsumedPercent,
                'work_completion_percent' => $completion,
                'total_labor_cost_cents' => $analysis['total_labor_cost_cents'],
                'estimated_labor_cost_cents' => $estimated,
            ],
        );
    }

    private function evaluateFlatFeeLaborRisk(Matter $matter, array $analysis): ?MatterLeverageRecommendation
    {
        $expectedRevenue = $analysis['expected_revenue_cents'];
        $completion = $analysis['work_completion_percent'];

        if ($expectedRevenue === null || $expectedRevenue <= 0 || $completion === null || $completion >= 100) {
            return null;
        }

        $laborAsPercentOfFee = (int) round(($analysis['total_labor_cost_cents'] / $expectedRevenue) * 100);

        if ($laborAsPercentOfFee < self::FLAT_FEE_LABOR_RISK_PERCENT_FLOOR) {
            return null;
        }

        return $this->createIfNew(
            $matter, MatterLeverageRecommendationType::FlatFeeLaborRisk, 'flat_fee_labor_risk', MatterLeverageConfidence::Medium,
            [
                'labor_cost_as_percent_of_fee' => $laborAsPercentOfFee,
                'total_labor_cost_cents' => $analysis['total_labor_cost_cents'],
                'expected_revenue_cents' => $expectedRevenue,
                'work_completion_percent' => $completion,
            ],
        );
    }

    private function createIfNew(
        Matter $matter,
        MatterLeverageRecommendationType $type,
        string $dedupKey,
        MatterLeverageConfidence $confidence,
        array $evidence,
    ): ?MatterLeverageRecommendation {
        $exists = MatterLeverageRecommendation::query()
            ->where('matter_id', $matter->id)
            ->where('recommendation_type', $type->value)
            ->where('dedup_key', $dedupKey)
            ->whereIn('status', ['open', 'acknowledged'])
            ->exists();

        if ($exists) {
            return null;
        }

        $event = $this->domainEvents->record(
            $matter->firm,
            DomainEventType::MatterLeverageRecommendationCreated,
            [
                'leverage_recommendation' => [
                    'recommendation_type' => $type->value,
                    'confidence' => $confidence->value,
                    'dedup_key' => $dedupKey,
                ],
                'matter' => [
                    'id' => $matter->id,
                    'assigned_attorney_id' => $matter->assigned_attorney_id,
                ],
            ],
            subject: $matter,
        );

        return MatterLeverageRecommendation::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'recommendation_type' => $type,
            'dedup_key' => $dedupKey,
            'confidence' => $confidence,
            'evidence_json' => $evidence,
            'domain_event_id' => $event->id,
        ]);
    }
}
