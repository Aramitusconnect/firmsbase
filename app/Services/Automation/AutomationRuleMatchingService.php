<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionExecutionStatus;
use App\Enums\AutomationActionRiskLevel;
use App\Enums\AutomationActionType;
use App\Enums\AutomationApprovalStatus;
use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\DomainEvent;
use App\Models\Firm;

/**
 * AutomationRuleMatchingService — Event-Driven Automation Engine, item
 * 2/9/11. Given a claimed DomainEvent, finds every currently-enabled
 * AutomationRule for its event_type (priority DESC, higher number
 * evaluated first — a pure ordering hint, since every matching rule
 * still gets its own execution regardless of order), evaluates
 * conditions, and — for a match — creates one AutomationActionExecution
 * per action, snapshotting the ActionType registry's own hardcoded risk
 * classification so a RequiresApproval/Prohibited action is NEVER
 * created as auto-runnable, independent of whatever the rule's own
 * requires_approval flag says.
 *
 * Loop prevention (item 11) — the ONE mandatory check, applied before
 * anything else: an event whose causation_depth already exceeds
 * MAX_CAUSATION_DEPTH gets NO rules matched against it at all (this
 * method returns immediately, loop_prevented=true). No current action
 * handler in this pass emits a further DomainEvent (confirmed — none of
 * the five registered handlers call DomainEventRecorderService), so no
 * real chain can exceed depth 1 today; this check exists as real,
 * tested infrastructure for the moment one eventually does, not
 * theater.
 *
 * A malformed rule (an unevaluable condition field/operator, or an
 * unrecognized action_type — both only reachable via direct DB
 * tampering, since AutomationRuleService validates both at save time)
 * is caught PER RULE: that one rule's AutomationExecution is recorded
 * Failed with the reason, and matching continues for every other rule
 * — one corrupted rule can never abort matching for the whole event.
 *
 * Idempotent by construction: the unique(automation_rule_id,
 * domain_event_id) index means a redelivered/re-claimed event that
 * already has an AutomationExecution for a given rule is simply
 * skipped for that rule — never re-evaluated, never a second row.
 */
class AutomationRuleMatchingService
{
    public const MAX_CAUSATION_DEPTH = 5;

    public function __construct(
        private readonly ConditionEvaluatorService $conditions,
        private readonly AutomationActionHandlerRegistry $handlers,
    ) {}

    /**
     * @return array{matched_rules: int, loop_prevented: bool}
     */
    public function match(Firm $firm, DomainEvent $event): array
    {
        if ($event->causation_depth > self::MAX_CAUSATION_DEPTH) {
            return ['matched_rules' => 0, 'loop_prevented' => true];
        }

        $rules = AutomationRule::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', $event->event_type->value)
            ->where('enabled', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $matchedCount = 0;

        foreach ($rules as $rule) {
            if (AutomationExecution::query()->where('automation_rule_id', $rule->id)->where('domain_event_id', $event->id)->exists()) {
                continue;
            }

            if ($this->processRule($firm, $rule, $event)) {
                $matchedCount++;
            }
        }

        return ['matched_rules' => $matchedCount, 'loop_prevented' => false];
    }

    private function processRule(Firm $firm, AutomationRule $rule, DomainEvent $event): bool
    {
        try {
            $result = $this->conditions->evaluate($rule->event_type, $rule->conditions_json, $event->payload_json);
        } catch (\Throwable $e) {
            AutomationExecution::create([
                'firm_id' => $firm->id,
                'automation_rule_id' => $rule->id,
                'domain_event_id' => $event->id,
                'rule_version' => $rule->version,
                'conditions_evaluated_json' => [],
                'matched' => false,
                'status' => AutomationExecutionStatus::Failed,
                'started_at' => now(),
                'completed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ]);

            return false;
        }

        $execution = AutomationExecution::create([
            'firm_id' => $firm->id,
            'automation_rule_id' => $rule->id,
            'domain_event_id' => $event->id,
            'rule_version' => $rule->version,
            'conditions_evaluated_json' => $result['evaluated'],
            'matched' => $result['matched'],
            'status' => $result['matched'] ? AutomationExecutionStatus::Pending : AutomationExecutionStatus::Completed,
            'started_at' => now(),
            'completed_at' => $result['matched'] ? null : now(),
        ]);

        if (! $result['matched']) {
            return false;
        }

        try {
            $this->createActionExecutions($firm, $rule, $execution, $event);
        } catch (\Throwable $e) {
            $execution->update(['status' => AutomationExecutionStatus::Failed, 'completed_at' => now(), 'failure_reason' => $e->getMessage()]);

            return true;
        }

        return true;
    }

    private function createActionExecutions(Firm $firm, AutomationRule $rule, AutomationExecution $execution, DomainEvent $event): void
    {
        foreach ($rule->actions_json as $index => $actionDef) {
            $actionType = AutomationActionType::tryFrom((string) ($actionDef['action_type'] ?? ''));

            if ($actionType === null) {
                throw new \RuntimeException("Rule #{$rule->id} action index {$index} has an unrecognized action_type.");
            }

            $riskLevel = $this->handlers->resolve($actionType)->riskLevel();
            $needsApproval = $riskLevel !== AutomationActionRiskLevel::AutoAllowed;

            AutomationActionExecution::firstOrCreate(
                [
                    'firm_id' => $firm->id,
                    'idempotency_key' => "{$rule->id}:{$event->id}:{$index}:{$rule->version}",
                ],
                [
                    'automation_execution_id' => $execution->id,
                    'action_index' => $index,
                    'action_type' => $actionType,
                    'action_config_json' => $actionDef['config'] ?? [],
                    'risk_level' => $riskLevel,
                    'status' => $needsApproval ? AutomationActionExecutionStatus::RequiresReview : AutomationActionExecutionStatus::Pending,
                    'approval_status' => $needsApproval ? AutomationApprovalStatus::Pending : null,
                ]
            );
        }
    }
}
