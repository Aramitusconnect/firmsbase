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

    /**
     * Zero-Click Core Workflow Automation pass. Sends a client-facing
     * reminder through the EXISTING, unmodified consent/eligibility/
     * dispatch pipeline — ConsentService (via NotificationEligibilityService),
     * NotificationEligibilityService, NotificationDispatchService::dispatch()
     * — never a second notification system, never a raw mail call. Per
     * this pass's own explicit safety rule ("if real delivery transport
     * is unavailable, create a staff task — never fake a successful
     * send"), a non-successful NotificationDispatchResult (no active
     * template, unverified sender domain, or ineligible/blocked/
     * suppressed recipient — dispatch()'s own existing gate chain)
     * creates a REQUIRES_REVIEW staff Task instead of silently failing
     * or silently claiming success. Config:
     * {template_key: string, channel?: 'email'|'sms'|'whatsapp'|'portal' (default 'email'),
     *  review_task_title?: string}.
     */
    case NotifyClient = 'notify_client';

    /**
     * Calls DocumentRequestService::create() — the ONLY canonical
     * creator of a DocumentRequest — never writes document_requests/
     * document_request_items directly. Config:
     * {title?: string, instructions?: string, due_in_days?: int,
     *  items: array<int, array{label: string, is_required?: bool}>}.
     */
    case CreateDocumentRequest = 'create_document_request';

    /**
     * Deterministic document-to-request matching (item 5). Only fires
     * when the triggering DocumentUploaded event's document had NO
     * document_request_item_id at upload time (see
     * MarkDocumentRequestItemSubmitted for the already-linked case).
     * Calls DocumentMatchingService::singleSafeMatch() — auto-links +
     * marks submitted ONLY when exactly one open DocumentRequestItem
     * exists for the same Firm+Matter; two or more candidates (or a
     * Matter that cannot be resolved) creates a "Review Document
     * Matching" Task instead of guessing. Never infers from filename
     * alone. Config: {} (document/matter come entirely from the
     * triggering event's own payload).
     */
    case MatchDocumentToRequest = 'match_document_to_request';
}
