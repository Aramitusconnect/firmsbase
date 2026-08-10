<?php

namespace App\Services\Automation\Contracts;

use App\Enums\AutomationActionRiskLevel;
use App\Exceptions\AutomationActionPermanentException;
use App\Exceptions\AutomationActionTransientException;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Services\Automation\AutomationActionOutcome;

/**
 * AutomationActionHandler — Event-Driven Automation Engine, item 6. One
 * implementation per AutomationActionType case, resolved exclusively
 * through AutomationActionHandlerRegistry (never instantiated directly
 * by rule/config-driven code — the mapping from action_type string to
 * handler class is a fixed, hardcoded array, not reflection over a
 * class name stored in the database).
 *
 * $config is the rule's own actions_json[i]['config'] — already
 * validated shape-wise by AutomationRuleService at save time; a handler
 * should still treat it as untrusted input (wrong types, missing keys)
 * and fail via AutomationActionPermanentException rather than a raw PHP
 * error.
 */
interface AutomationActionHandler
{
    /**
     * Hardcoded classification — never influenced by $config, never
     * read from the database. This is the actual safety gate item 7
     * requires; AutomationRule.requires_approval is an ADDITIVE
     * firm-settable flag on top of this, never a substitute for it.
     */
    public function riskLevel(): AutomationActionRiskLevel;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws AutomationActionTransientException retry later
     * @throws AutomationActionPermanentException never retry
     */
    public function handle(Firm $firm, DomainEvent $event, array $config): AutomationActionOutcome;
}
