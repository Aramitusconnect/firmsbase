<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DomainEventProcessingStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;

/**
 * AutomationObservabilityService — Event-Driven Automation Engine, item
 * 18. Read-only, firm-scoped query methods over the engine's own audit
 * tables (domain_events, automation_rules, automation_executions,
 * automation_action_executions) — no separate metrics store, no
 * time-series database, exactly what's needed to answer "is automation
 * healthy for this firm right now." Every method runs under the
 * caller's already-active tenant context (never opens one itself),
 * matching every other read-side service in this pass. Deliberately
 * excludes payload_json/action_config_json from every result — counts
 * and identifiers only, never event content (see
 * AutomationEventDispatchJob's own loop-prevention log line for the
 * same discipline).
 */
class AutomationObservabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Firm $firm): array
    {
        return [
            'executions_total' => AutomationExecution::query()->where('firm_id', $firm->id)->count(),
            'executions_matched' => AutomationExecution::query()->where('firm_id', $firm->id)->where('matched', true)->count(),
            'actions_succeeded' => $this->countActionsWithStatus($firm, AutomationActionExecutionStatus::Succeeded),
            'actions_failed' => $this->countActionsWithStatus($firm, AutomationActionExecutionStatus::Failed),
            'actions_retry_scheduled' => $this->countActionsWithStatus($firm, AutomationActionExecutionStatus::RetryScheduled),
            'actions_awaiting_approval' => $this->countActionsWithStatus($firm, AutomationActionExecutionStatus::RequiresReview),
            'rules_enabled' => AutomationRule::query()->where('firm_id', $firm->id)->where('enabled', true)->count(),
            'rules_disabled' => AutomationRule::query()->where('firm_id', $firm->id)->where('enabled', false)->count(),
            'events_dead_lettered' => DomainEvent::query()->where('firm_id', $firm->id)->where('processing_status', DomainEventProcessingStatus::DeadLettered)->count(),
            'average_execution_duration_seconds' => $this->averageExecutionDurationSeconds($firm),
            'oldest_queued_event_created_at' => $this->oldestQueuedEventCreatedAt($firm),
            'repeatedly_failing_rule_ids' => $this->repeatedlyFailingRuleIds($firm),
        ];
    }

    private function countActionsWithStatus(Firm $firm, AutomationActionExecutionStatus $status): int
    {
        return AutomationActionExecution::query()->where('firm_id', $firm->id)->where('status', $status)->count();
    }

    private function averageExecutionDurationSeconds(Firm $firm): ?float
    {
        $avg = AutomationExecution::query()
            ->where('firm_id', $firm->id)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->selectRaw('avg(extract(epoch from (completed_at - started_at))) as avg_seconds')
            ->value('avg_seconds');

        return $avg === null ? null : round((float) $avg, 2);
    }

    private function oldestQueuedEventCreatedAt(Firm $firm): ?string
    {
        $oldest = DomainEvent::query()
            ->where('firm_id', $firm->id)
            ->where('processing_status', DomainEventProcessingStatus::Pending)
            ->orderBy('id')
            ->value('created_at');

        return $oldest?->toIso8601String();
    }

    /**
     * Rule ids with 3 or more Failed executions in the last 24 hours —
     * a rule that is consistently misconfigured (an unresolvable
     * condition field/action type, almost always only reachable via
     * direct DB tampering — see AutomationTrustAccountingFirewallTest)
     * is worth a Firm's attention, not an indefinite silent retry.
     *
     * @return array<int, int>
     */
    private function repeatedlyFailingRuleIds(Firm $firm): array
    {
        return AutomationExecution::query()
            ->where('firm_id', $firm->id)
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('automation_rule_id')
            ->havingRaw('count(*) >= 3')
            ->pluck('automation_rule_id')
            ->all();
    }
}
