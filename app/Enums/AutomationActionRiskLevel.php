<?php

namespace App\Enums;

/**
 * AutomationActionRiskLevel — Event-Driven Automation Engine, item 7.
 * A property of the ACTION TYPE, hardcoded in
 * AutomationActionHandlerRegistry — never a per-rule, firm-editable
 * setting. AutomationRule.requires_approval is a firm-settable ADDITION
 * on top of this (a firm may require approval for an otherwise
 * AutoAllowed action), never a way to remove a registry-mandated gate:
 * AutomationRuleService validates at save time that requires_approval
 * cannot be false if any of the rule's actions resolve to
 * RequiresApproval/Prohibited, and the execution engine independently
 * re-checks the registry's own classification at run time regardless of
 * what the rule row says — defense in depth, the stored flag is never
 * the sole gate.
 *
 * No AutomationActionType case is currently classified
 * RequiresApproval or Prohibited (none of the five registered actions
 * touches a sensitive domain) — this enum exists so the mechanism is
 * real, tested, and ready the moment a genuinely higher-risk action
 * type is added, rather than being retrofitted under time pressure
 * later. See AutomationActionExecutionResolutionServiceTest for the
 * gate's own direct proof.
 */
enum AutomationActionRiskLevel: string
{
    case AutoAllowed = 'auto_allowed';
    case RequiresApproval = 'requires_approval';

    /**
     * Reserved for documentation purposes: a risk level a FUTURE action
     * type could be classified as, meaning "never auto-executable under
     * any approval." No code path currently checks for this value
     * because no action type is ever classified this way — the actual
     * enforcement for genuinely prohibited operations (Trust ledger
     * writes, accounting postings, refunds, payment allocation
     * resolution, etc.) is that no AutomationActionType case for them
     * exists at all, which is a stronger guarantee than a runtime flag.
     */
    case Prohibited = 'prohibited';
}
