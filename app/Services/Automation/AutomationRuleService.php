<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionRiskLevel;
use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;
use App\Models\AutomationRule;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\TenantContextService;

/**
 * AutomationRuleService — Event-Driven Automation Engine, item 4. The
 * ONLY writer of automation_rules. Every save-time validation here is
 * the FIRST of the two defense-in-depth checks the rest of the engine
 * relies on never being bypassed (ConditionEvaluatorService and
 * AutomationRuleMatchingService both re-validate at evaluation time as
 * a backstop against direct DB tampering, never trusting this service
 * alone) — see conditions_json/actions_json's own docblocks on the
 * create-table migration for the exact shape validated below.
 */
class AutomationRuleService
{
    public function __construct(
        private readonly AutomationActionHandlerRegistry $handlers,
        private readonly AutomationAccessPolicyService $accessPolicy,
    ) {}

    /**
     * @param  array<int, array{field: string, operator: string, value: mixed}>  $conditions
     * @param  array<int, array{action_type: string, config: array<string, mixed>}>  $actions
     */
    public function create(
        Firm $firm,
        FirmUser $createdBy,
        string $name,
        ?string $description,
        DomainEventType $eventType,
        array $conditions,
        array $actions,
        bool $requiresApproval = false,
        bool $enabled = true,
        int $priority = 0,
        bool $isStarterTemplate = false,
    ): AutomationRule {
        $this->assertAuthorized($createdBy);
        $requiresApproval = $this->validate($eventType, $conditions, $actions, $requiresApproval);

        return (new TenantContextService)->runWithFirmContext($firm, fn () => AutomationRule::create([
            'firm_id' => $firm->id,
            'name' => $name,
            'description' => $description,
            'event_type' => $eventType,
            'enabled' => $enabled,
            'priority' => $priority,
            'conditions_json' => $conditions,
            'actions_json' => $actions,
            'requires_approval' => $requiresApproval,
            'is_starter_template' => $isStarterTemplate,
            'version' => 1,
            'created_by_firm_user_id' => $createdBy->id,
            'updated_by_firm_user_id' => $createdBy->id,
        ]));
    }

    /**
     * @param  array<int, array{field: string, operator: string, value: mixed}>|null  $conditions
     * @param  array<int, array{action_type: string, config: array<string, mixed>}>|null  $actions
     */
    public function update(
        Firm $firm,
        AutomationRule $rule,
        FirmUser $updatedBy,
        ?string $name = null,
        ?string $description = null,
        ?array $conditions = null,
        ?array $actions = null,
        ?bool $requiresApproval = null,
        ?int $priority = null,
    ): AutomationRule {
        $this->assertAuthorized($updatedBy);
        $this->assertBelongsToFirm($firm, $rule, $updatedBy);
        $newConditions = $conditions ?? $rule->conditions_json;
        $newActions = $actions ?? $rule->actions_json;
        $newRequiresApproval = $this->validate($rule->event_type, $newConditions, $newActions, $requiresApproval ?? $rule->requires_approval);

        $definitionChanged = $conditions !== null || $actions !== null;

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $rule, $updatedBy, $name, $description, $newConditions, $newActions, $newRequiresApproval, $priority, $definitionChanged,
        ) {
            $rule->update([
                'name' => $name ?? $rule->name,
                'description' => $description ?? $rule->description,
                'conditions_json' => $newConditions,
                'actions_json' => $newActions,
                'requires_approval' => $newRequiresApproval,
                'priority' => $priority ?? $rule->priority,
                'version' => $definitionChanged ? $rule->version + 1 : $rule->version,
                'updated_by_firm_user_id' => $updatedBy->id,
            ]);

            return $rule->fresh();
        });
    }

    public function setEnabled(Firm $firm, AutomationRule $rule, bool $enabled, FirmUser $updatedBy): AutomationRule
    {
        $this->assertAuthorized($updatedBy);
        $this->assertBelongsToFirm($firm, $rule, $updatedBy);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($rule, $enabled, $updatedBy) {
            $rule->update(['enabled' => $enabled, 'updated_by_firm_user_id' => $updatedBy->id]);

            return $rule->fresh();
        });
    }

    private function assertAuthorized(FirmUser $actor): void
    {
        if (! $this->accessPolicy->canManageRules($actor->role)) {
            throw new \RuntimeException('This user is not authorized to manage automation rules.');
        }
    }

    private function assertBelongsToFirm(Firm $firm, AutomationRule $rule, FirmUser $actor): void
    {
        if ((int) $rule->firm_id !== (int) $firm->id || (int) $actor->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This automation rule does not belong to this firm.');
        }
    }

    /**
     * @return bool the effective requires_approval value to store (may
     *              be forced true even if the caller supplied false)
     */
    private function validate(DomainEventType $eventType, array $conditions, array $actions, bool $requestedRequiresApproval): bool
    {
        foreach ($conditions as $clause) {
            $field = $clause['field'] ?? null;

            if (! is_string($field) || ! AutomationFieldAllowlistRegistry::isAllowed($eventType, $field)) {
                throw new \InvalidArgumentException(
                    'Condition field ['.(is_string($field) ? $field : json_encode($field))."] is not allowed for event type {$eventType->value}."
                );
            }

            if (AutomationConditionOperator::tryFrom((string) ($clause['operator'] ?? '')) === null) {
                throw new \InvalidArgumentException('Unknown condition operator ['.(string) ($clause['operator'] ?? '').'].');
            }
        }

        if (empty($actions)) {
            throw new \InvalidArgumentException('A rule must have at least one action.');
        }

        $needsApproval = false;

        foreach ($actions as $actionDef) {
            $actionType = AutomationActionType::tryFrom((string) ($actionDef['action_type'] ?? ''));

            if ($actionType === null) {
                throw new \InvalidArgumentException('Unknown action type ['.(string) ($actionDef['action_type'] ?? '').'].');
            }

            if (! is_array($actionDef['config'] ?? null)) {
                throw new \InvalidArgumentException("Action [{$actionType->value}] requires a config array.");
            }

            if ($this->handlers->resolve($actionType)->riskLevel() !== AutomationActionRiskLevel::AutoAllowed) {
                $needsApproval = true;
            }
        }

        if ($needsApproval && ! $requestedRequiresApproval) {
            throw new \InvalidArgumentException(
                'This rule contains an action that requires approval; requires_approval cannot be set to false for it.'
            );
        }

        return $requestedRequiresApproval || $needsApproval;
    }
}
