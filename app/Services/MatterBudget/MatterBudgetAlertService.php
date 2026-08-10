<?php

namespace App\Services\MatterBudget;

use App\Enums\DomainEventType;
use App\Enums\MatterBudgetAlertSeverity;
use App\Enums\MatterBudgetAlertType;
use App\Models\Matter;
use App\Models\MatterBudget;
use App\Models\MatterBudgetAlert;
use App\Models\MatterBudgetAnalysis;
use App\Services\Automation\DomainEventRecorderService;

/**
 * MatterBudgetAlertService — Predictive Matter Budget Alerts, item 12/
 * 13/14/15/16. Evaluates a freshly-recomputed MatterBudgetAnalysis
 * against its MatterBudget's own thresholds and creates
 * MatterBudgetAlert rows for any NEWLY-crossed tier — dedup is a
 * database guarantee (matter_budget_alerts' own unique index), not a
 * convention re-checked here; a duplicate insert attempt for an
 * already-alerted tier simply does not happen (firstOrCreate: the
 * SELECT finds the existing row and no INSERT is attempted).
 *
 * Every new alert emits exactly one DomainEventType::MatterBudgetThresholdCrossed
 * — the Automation Engine (never duplicated here) then decides WHAT to
 * do (create a task, notify a role) via a Firm's own configured
 * AutomationRule, exactly like every other starter event in this
 * codebase.
 *
 * Must be called from inside an already-active tenant context (same
 * convention as MatterBudgetAnalysisService) — never opens one itself.
 */
class MatterBudgetAlertService
{
    /**
     * Percentage-point gap between hours-consumed% and work-completion%
     * required before a comparative "usage ahead of progress" alert is
     * worth raising — the master spec's own explicit "do not spam
     * alerts for tiny variances." A deliberately chosen materiality
     * threshold, not derived from anywhere else in this codebase.
     */
    private const USAGE_AHEAD_OF_PROGRESS_GAP_POINTS = 25;

    public function __construct(private readonly DomainEventRecorderService $domainEvents) {}

    /**
     * @return array<int, MatterBudgetAlert> newly-created alerts only
     *                                       (never ones that already
     *                                       existed — dedup is silent)
     */
    public function evaluate(Matter $matter, MatterBudget $budget, MatterBudgetAnalysis $analysis): array
    {
        $created = [];

        foreach ($analysis->hours_by_role_json as $role => $data) {
            $alert = $this->maybeThresholdAlert(
                $matter, $budget, MatterBudgetAlertType::RoleHoursThreshold, $role,
                $data['consumed_percent'] ?? null, $data,
            );
            if ($alert !== null) {
                $created[] = $alert;
            }
        }

        foreach ($analysis->expenses_by_category_json as $category => $data) {
            $alert = $this->maybeThresholdAlert(
                $matter, $budget, MatterBudgetAlertType::ExpenseThreshold, $category,
                $data['consumed_percent'] ?? null, $data,
            );
            if ($alert !== null) {
                $created[] = $alert;
            }
        }

        $totalHoursAlert = $this->maybeTotalHoursThresholdAlert($matter, $budget, $analysis);
        if ($totalHoursAlert !== null) {
            $created[] = $totalHoursAlert;
        }

        foreach ($analysis->hours_by_role_json as $role => $data) {
            $consumedPercent = $data['consumed_percent'] ?? null;

            if ($consumedPercent === null || $consumedPercent < $budget->warning_threshold_percent) {
                continue;
            }

            if (($consumedPercent - $analysis->work_completion_percent) < self::USAGE_AHEAD_OF_PROGRESS_GAP_POINTS) {
                continue;
            }

            $alert = $this->maybeInfoAlert(
                $matter, $budget, MatterBudgetAlertType::UsageAheadOfProgress, $role,
                ['consumed_percent' => $consumedPercent, 'progress_percent' => $analysis->work_completion_percent],
            );
            if ($alert !== null) {
                $created[] = $alert;
            }
        }

        if (
            $budget->target_gross_margin_percent !== null
            && $analysis->current_margin_percent !== null
            && $analysis->current_margin_percent < $budget->target_gross_margin_percent
        ) {
            $alert = $this->maybeInfoAlert(
                $matter, $budget, MatterBudgetAlertType::MarginBelowTarget, 'margin',
                ['current_margin_percent' => $analysis->current_margin_percent, 'target_gross_margin_percent' => $budget->target_gross_margin_percent],
            );
            if ($alert !== null) {
                $created[] = $alert;
            }
        }

        foreach ($analysis->projected_overrun_hours_by_role_json as $role => $overrunHours) {
            if ($overrunHours <= 0) {
                continue;
            }

            $alert = $this->maybeInfoAlert(
                $matter, $budget, MatterBudgetAlertType::ProjectedOverrun, $role,
                ['projected_overrun_hours' => $overrunHours],
                severity: MatterBudgetAlertSeverity::Warning,
            );
            if ($alert !== null) {
                $created[] = $alert;
            }
        }

        return $created;
    }

    private function maybeThresholdAlert(
        Matter $matter,
        MatterBudget $budget,
        MatterBudgetAlertType $type,
        string $metricKey,
        ?int $consumedPercent,
        array $snapshot,
    ): ?MatterBudgetAlert {
        if ($consumedPercent === null) {
            return null;
        }

        $tier = $this->tierFor($consumedPercent, $budget);

        if ($tier === null) {
            return null;
        }

        [$severity, $thresholdPercent] = $tier;

        return $this->createIfNew($matter, $budget, $type, $metricKey, $severity, $thresholdPercent, $snapshot);
    }

    private function maybeTotalHoursThresholdAlert(Matter $matter, MatterBudget $budget, MatterBudgetAnalysis $analysis): ?MatterBudgetAlert
    {
        $expectedTotal = 0.0;
        $actualTotal = 0.0;

        foreach ($analysis->hours_by_role_json as $data) {
            $expectedTotal += (float) $data['expected'];
            $actualTotal += (float) $data['actual'];
        }

        if ($expectedTotal <= 0) {
            return null;
        }

        $consumedPercent = (int) round(($actualTotal / $expectedTotal) * 100);
        $tier = $this->tierFor($consumedPercent, $budget);

        if ($tier === null) {
            return null;
        }

        [$severity, $thresholdPercent] = $tier;

        return $this->createIfNew(
            $matter, $budget, MatterBudgetAlertType::TotalLaborThreshold, 'total_hours',
            $severity, $thresholdPercent, ['consumed_percent' => $consumedPercent],
        );
    }

    /**
     * @return array{0: MatterBudgetAlertSeverity, 1: int}|null
     */
    private function tierFor(int $consumedPercent, MatterBudget $budget): ?array
    {
        return match (true) {
            $consumedPercent >= 100 => [MatterBudgetAlertSeverity::OverBudget, 100],
            $consumedPercent >= $budget->high_threshold_percent => [MatterBudgetAlertSeverity::High, $budget->high_threshold_percent],
            $consumedPercent >= $budget->warning_threshold_percent => [MatterBudgetAlertSeverity::Warning, $budget->warning_threshold_percent],
            default => null,
        };
    }

    /**
     * A non-tiered comparative alert (UsageAheadOfProgress, MarginBelowTarget,
     * ProjectedOverrun) — fires once per (matter_budget, type, metric_key)
     * using the fixed sentinel threshold 100 (see matter_budget_alerts'
     * own migration docblock for why NULL cannot be used here).
     */
    private function maybeInfoAlert(
        Matter $matter,
        MatterBudget $budget,
        MatterBudgetAlertType $type,
        string $metricKey,
        array $snapshot,
        MatterBudgetAlertSeverity $severity = MatterBudgetAlertSeverity::Info,
    ): ?MatterBudgetAlert {
        return $this->createIfNew($matter, $budget, $type, $metricKey, $severity, 100, $snapshot);
    }

    private function createIfNew(
        Matter $matter,
        MatterBudget $budget,
        MatterBudgetAlertType $type,
        string $metricKey,
        MatterBudgetAlertSeverity $severity,
        int $thresholdPercentCrossed,
        array $snapshot,
    ): ?MatterBudgetAlert {
        $exists = MatterBudgetAlert::query()
            ->where('matter_budget_id', $budget->id)
            ->where('alert_type', $type->value)
            ->where('metric_key', $metricKey)
            ->where('threshold_percent_crossed', $thresholdPercentCrossed)
            ->exists();

        if ($exists) {
            return null;
        }

        $event = $this->domainEvents->record(
            $matter->firm,
            DomainEventType::MatterBudgetThresholdCrossed,
            [
                'matter_budget_alert' => [
                    'alert_type' => $type->value,
                    'metric_key' => $metricKey,
                    'severity' => $severity->value,
                    'threshold_percent_crossed' => $thresholdPercentCrossed,
                ],
                'matter' => [
                    'id' => $matter->id,
                    'assigned_attorney_id' => $matter->assigned_attorney_id,
                ],
            ],
            subject: $matter,
        );

        return MatterBudgetAlert::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'matter_budget_id' => $budget->id,
            'alert_type' => $type,
            'metric_key' => $metricKey,
            'severity' => $severity,
            'threshold_percent_crossed' => $thresholdPercentCrossed,
            'metric_snapshot_json' => $snapshot,
            'domain_event_id' => $event->id,
        ]);
    }
}
