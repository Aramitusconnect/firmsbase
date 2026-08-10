<?php

namespace App\Enums;

/**
 * DomainEventType — Event-Driven Automation Engine, item 3. The closed
 * internal vocabulary DomainEventRecorderService may emit and
 * AutomationRule::event_type may target. Deliberately NOT
 * WebhookEventType (closed to exactly 11 outbound-integration-shaped
 * cases, entitlement-gated per firm — reusing it would silently disable
 * automation for firms without the webhook module) and NOT an open
 * string like TimelineEventRecorder's own vocabulary (that's a
 * write-only audit sink with no consumer; this enum's whole purpose is
 * to be consumed).
 *
 * A deliberately narrow starting set — see this pass's own final report
 * for the full audit of the 27 candidate events discussed with the
 * team: every case below backs one of the six approved starter
 * automations. The remaining candidates (MatterStageChanged,
 * TaskCreated, DeadlineCreated, DocumentRequested, DocumentClassified,
 * EngagementLetterSigned, InvoiceIssued, PaymentReceived/Applied/Failed,
 * PaymentAllocationResolved, every Trust event, ExpenseApproved,
 * UserInvited) were explicitly NOT added here per "do not automatically
 * create all of these simply because they are listed" — most either
 * have no real mutation call site to emit from yet (confirmed by
 * repository audit) or aren't needed by any approved starter. Extending
 * this enum for a genuinely new automation is expected over time; it
 * carries no migration (mirrors ChartOfAccountPurpose's own precedent —
 * no DB-level check constraint on the backing string column).
 */
enum DomainEventType: string
{
    /**
     * Emitted by a scheduled sweep (InvoiceOverdueSweepCommand), never
     * by InvoiceDraftingService/PaymentApplicationService directly —
     * "overdue" is a derived, time-based state (crossing a day-count
     * threshold), not a service-level mutation. The sweep reuses
     * AccountingReportingService::accountsReceivableAging() for the
     * underlying days_overdue calculation rather than re-deriving it.
     */
    case InvoiceOverdue = 'invoice_overdue';

    /**
     * Emitted by a scheduled sweep (DeadlineReminderSweepCommand),
     * reusing DeadlineService::reminderDates()'s existing calculation.
     */
    case DeadlineApproaching = 'deadline_approaching';

    /**
     * Emitted by the same sweep, reusing
     * DeadlineService::refreshMissedStatus()'s existing derivation.
     */
    case DeadlineMissed = 'deadline_missed';

    /**
     * Emitted at the SAME three real upload call sites already wired to
     * WebhookEventType::DocumentUploaded (DocumentSecurityService,
     * DocumentReplacementService, EmailAttachmentPromotionService) — a
     * parallel, independent emission, not derived from the webhook
     * event (which is entitlement-gated and would silently skip
     * automation for firms without that module).
     */
    case DocumentUploaded = 'document_uploaded';

    /**
     * Substitutes for the literal "MatterStageChanged" candidate: the
     * audit found Matter.stage has no real mutation path in this
     * codebase at all (set once at creation, never changed). Matter
     * OPENING (MatterOpeningService::openMatter()) is the nearest real,
     * existing transition with an actual call site — used for starter
     * 4 instead. True stage-change automation remains
     * NEW_EVENT_REQUIRED / deferred; see the final report.
     */
    case MatterOpened = 'matter_opened';

    /**
     * Emitted at PaymentPlanService/PaymentApplicationService's own
     * existing 'payment_plan_installment_missed' timeline-event call
     * site — a parallel emission, same real transition.
     */
    case PaymentPlanInstallmentMissed = 'payment_plan_installment_missed';

    /**
     * Emitted where ManualPaymentService::applyOrDeferInvoice()/
     * applyOrDeferInstallment() create a PendingPaymentAllocation row
     * (Mixed-Invoice Revenue Allocation pass, this session).
     */
    case PaymentAllocationPending = 'payment_allocation_pending';

    /**
     * Predictive Matter Budget Alerts pass. Emitted by
     * MatterBudgetAlertService the moment a NEW (never-before-alerted,
     * for the Matter's current budget version) threshold tier is
     * crossed — never once per sweep tick, never a repeat for a tier
     * already alerted (matter_budget_alerts' own dedup unique index is
     * the real gate; this event is only ever emitted alongside a
     * successfully newly-created MatterBudgetAlert row, in the same
     * transaction).
     */
    case MatterBudgetThresholdCrossed = 'matter_budget_threshold_crossed';

    /**
     * Leverage Ratio Optimizer pass. Emitted by
     * LeverageRecommendationService the moment a NEW (never previously
     * open/acknowledged for this exact matter/user + type + dedup_key)
     * staffing-leverage recommendation is created —
     * matter_leverage_recommendations' own dedup unique index is the
     * real gate; this event is only ever emitted alongside a
     * successfully newly-created recommendation row, in the same
     * transaction.
     */
    case MatterLeverageRecommendationCreated = 'matter_leverage_recommendation_created';
}
