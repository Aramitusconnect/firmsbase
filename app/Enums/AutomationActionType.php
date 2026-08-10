<?php

namespace App\Enums;

/**
 * AutomationActionType — Event-Driven Automation Engine, item 6. The
 * closed action registry. Every case here has exactly one handler
 * class (AutomationActionHandlerRegistry), and every handler invokes an
 * EXISTING canonical domain service — never a direct write to a
 * sensitive domain table. No arbitrary service class/method name is
 * ever stored in automation_rules.actions_json; only this enum's own
 * string values are valid there, validated on save (AutomationRuleService)
 * and re-validated on execution (AutomationActionHandlerRegistry::resolve()
 * throws on anything not a case of this enum).
 *
 * Deliberately narrow — backs exactly the six approved starters. Two
 * canonical-service gaps this pass's audit surfaced and did NOT paper
 * over (see the final report): (1) there is no internal firm-staff
 * notification service anywhere in this codebase — NotificationDispatchService
 * is client-notification-only (hard-requires a Client for consent
 * lookup) — so "notify" here means creating a Task assigned to the
 * resolved recipient (TaskService::create(), a real canonical service),
 * not a push notification/email; (2) "escalate according to Firm
 * configuration" has no FirmSettings field to read (confirmed absent) —
 * EscalateDeadline instead reads its escalation target directly from
 * the rule's own config, since the rule IS the firm's configuration
 * surface here.
 *
 * Every registered case today is ActionRiskLevel::AutoAllowed — none
 * touches Trust, Accounting, refunds, chargebacks, write-offs, payment
 * allocation resolution, or any other item 19-listed sensitive
 * operation. No such action type is registered, full stop — the
 * firewall this enforces is structural (it cannot be selected because
 * it does not exist as a case), not merely a runtime risk-level check.
 */
enum AutomationActionType: string
{
    /**
     * Creates a Task via TaskService::create(). Config:
     * {title, description?, matter_from_event?: bool, client_from_event?: bool,
     *  assigned_to: 'event_matter_assigned_attorney'|'role:<FirmUserRole value>',
     *  priority?: TaskPriority value, due_in_days?: int}.
     */
    case CreateTask = 'create_task';

    /**
     * Resolves every Active FirmUser with role=BillingStaff for the
     * firm and creates one Task per recipient (TaskService::create()) —
     * Task has no multi-assignee/broadcast concept, so "notify a group"
     * means "one task per group member," never a new parallel
     * notification mechanism.
     */
    case NotifyBillingStaff = 'notify_billing_staff';

    /**
     * Resolves the triggering event's Matter (directly, or via its
     * Deadline/other subject's own matter relation) -> assignedAttorney,
     * creates one Task for that User. If no matter or no assigned
     * attorney can be resolved, the action outcome is Skipped (a real,
     * audited outcome — never a silent no-op, never a guessed fallback
     * recipient).
     */
    case NotifyResponsibleAttorney = 'notify_responsible_attorney';

    /**
     * Creates a high-priority Task for the escalation target named in
     * THIS action's own config ({escalate_to: 'role:<FirmUserRole value>'}
     * — read from the rule, since no firm-wide escalation setting
     * exists to read from instead).
     */
    case EscalateDeadline = 'escalate_deadline';

    /**
     * Calls DocumentRequestService::markSubmitted(Firm, DocumentRequestItem)
     * when the triggering DocumentUploaded event's subject Document has
     * a non-null document_request_item_id. A genuinely new integration
     * point (no existing service auto-wires upload -> checklist today —
     * confirmed by audit), but the call itself is 100% the existing
     * canonical method, never a direct write to document_request_items.
     */
    case MarkDocumentRequestItemSubmitted = 'mark_document_request_item_submitted';
}
