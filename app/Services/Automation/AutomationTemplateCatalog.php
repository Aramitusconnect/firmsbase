<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;

/**
 * AutomationTemplateCatalog — Event-Driven Automation Engine, item 13/16.
 * The six first-party starter templates, verbatim from the master
 * prompt's own list. A template is data (a plain PHP array), never a
 * hidden hardcoded workflow — AutomationTemplateInstallService turns
 * one into a completely normal, firm-owned AutomationRule row
 * (is_starter_template=true is provenance labeling only; the resulting
 * rule is exactly as inspectable/editable/disableable as one a firm
 * user builds from scratch).
 *
 * No Trust/Accounting mutation appears in any template, per the master
 * prompt's own explicit instruction — every action_type used here is
 * ActionRiskLevel::AutoAllowed.
 */
final class AutomationTemplateCatalog
{
    /**
     * @return array<string, array{name: string, description: string, event_type: DomainEventType, conditions: array, actions: array}>
     */
    public static function templates(): array
    {
        return [
            'invoice_overdue_reminder' => [
                'name' => 'Invoice overdue reminder',
                'description' => 'Notifies Billing Staff once an invoice has been overdue for 7 or more days.',
                'event_type' => DomainEventType::InvoiceOverdue,
                'conditions' => [
                    ['field' => 'invoice.days_overdue', 'operator' => AutomationConditionOperator::GreaterThanOrEqual->value, 'value' => 7],
                ],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => [
                        'title' => 'Invoice overdue 7+ days',
                        'description' => 'This invoice has been overdue for at least 7 days — follow up with the client.',
                    ]],
                ],
            ],
            'deadline_approaching' => [
                'name' => 'Deadline approaching',
                'description' => 'Notifies the responsible attorney when a deadline enters its reminder window, and escalates to the Firm Owner.',
                'event_type' => DomainEventType::DeadlineApproaching,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyResponsibleAttorney->value, 'config' => [
                        'title' => 'Deadline approaching',
                        'description' => 'A deadline on one of your matters is approaching.',
                    ]],
                    ['action_type' => AutomationActionType::EscalateDeadline->value, 'config' => [
                        'title' => 'Deadline escalation',
                        'description' => 'A deadline is approaching and is being escalated for visibility.',
                        'escalate_to' => 'role:firm_owner',
                    ]],
                ],
            ],
            'document_uploaded_notification' => [
                'name' => 'Document uploaded notification',
                'description' => 'Notifies the responsible attorney when a document is uploaded, and marks the matching document request item submitted.',
                'event_type' => DomainEventType::DocumentUploaded,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyResponsibleAttorney->value, 'config' => [
                        'title' => 'Document uploaded',
                        'description' => 'A new document was uploaded on one of your matters.',
                    ]],
                    ['action_type' => AutomationActionType::MarkDocumentRequestItemSubmitted->value, 'config' => []],
                ],
            ],
            'matter_opened_next_task' => [
                'name' => 'Matter opened — next task',
                'description' => 'Creates a starting workflow task for the assigned attorney when a matter opens.',
                'event_type' => DomainEventType::MatterOpened,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => [
                        'title' => 'Begin matter workflow',
                        'assigned_to' => 'matter_assigned_attorney',
                        'due_in_days' => 3,
                    ]],
                ],
            ],
            'payment_plan_installment_missed' => [
                'name' => 'Payment plan installment missed',
                'description' => 'Notifies Billing Staff and creates a follow-up task when a payment plan installment is missed.',
                'event_type' => DomainEventType::PaymentPlanInstallmentMissed,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => [
                        'title' => 'Payment plan installment missed',
                        'description' => 'A scheduled installment was missed.',
                    ]],
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => [
                        'title' => 'Follow up on missed installment',
                        'assigned_to' => 'role:billing_staff',
                        'due_in_days' => 1,
                    ]],
                ],
            ],
            'pending_payment_allocation' => [
                'name' => 'Pending payment allocation',
                'description' => 'Notifies authorized Billing Staff when a payment is awaiting a fee/cost allocation decision.',
                'event_type' => DomainEventType::PaymentAllocationPending,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyBillingStaff->value, 'config' => [
                        'title' => 'Payment awaiting fee/cost allocation',
                        'description' => 'A payment on a mixed invoice needs an authorized user to resolve its fee/cost split.',
                    ]],
                ],
            ],

            // Zero-Click Core Workflow Automation pass — items 4B/12/29.
            // A separate rule per is_escalation value (conditions are
            // AND-only, no OR groups — see ConditionEvaluatorService's
            // own docblock), matching the codebase's own established
            // "many small rules over one complex rule" convention
            // (deadline_approaching/deadline_missed are two separate
            // event types for the same reason).
            'document_request_reminder' => [
                'name' => 'Document request reminder',
                'description' => 'Sends a client reminder when a document request reaches a configured reminder checkpoint.',
                'event_type' => DomainEventType::DocumentRequestReminderDue,
                'conditions' => [
                    ['field' => 'document_request_item.is_escalation', 'operator' => AutomationConditionOperator::Equals->value, 'value' => false],
                ],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyClient->value, 'config' => [
                        'template_key' => 'document_request_reminder',
                        'channel' => 'email',
                    ]],
                ],
            ],
            'document_request_reminder_escalation' => [
                'name' => 'Document request escalation',
                'description' => 'Creates a follow-up task for the responsible attorney once a document request passes the Firm\'s own escalation threshold.',
                'event_type' => DomainEventType::DocumentRequestReminderDue,
                'conditions' => [
                    ['field' => 'document_request_item.is_escalation', 'operator' => AutomationConditionOperator::Equals->value, 'value' => true],
                ],
                'actions' => [
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => [
                        'title' => 'Document request overdue — needs attention',
                        'assigned_to' => 'matter_assigned_attorney',
                        'priority' => 'high',
                    ]],
                ],
            ],

            // Item 8/30. Ships with ONE clearly-labeled placeholder item
            // — never a pre-filled, practice-area-specific document
            // list (item 8's own explicit instruction: "Do not globally
            // hardcode... requirements... Firm controls the template").
            // A Firm duplicates and edits this before enabling it for
            // real use.
            'new_matter_onboarding_documents' => [
                'name' => 'New matter onboarding — document request (example)',
                'description' => 'Creates a starter document request when a matter opens. Edit the item list for your own practice area before enabling.',
                'event_type' => DomainEventType::MatterOpened,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::CreateDocumentRequest->value, 'config' => [
                        'title' => 'Required documents',
                        'due_in_days' => 14,
                        'items' => [
                            ['label' => 'Example required document — edit this item for your own practice area before enabling', 'is_required' => true],
                        ],
                    ]],
                ],
            ],

            'invoice_overdue_client_reminder' => [
                'name' => 'Invoice overdue — client reminder',
                'description' => 'Sends a client reminder once an invoice has been overdue for 14 or more days (a later checkpoint than the internal Billing Staff follow-up).',
                'event_type' => DomainEventType::InvoiceOverdue,
                'conditions' => [
                    ['field' => 'invoice.days_overdue', 'operator' => AutomationConditionOperator::GreaterThanOrEqual->value, 'value' => 14],
                ],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyClient->value, 'config' => [
                        'template_key' => 'invoice_overdue_reminder',
                        'channel' => 'email',
                    ]],
                ],
            ],

            'payment_plan_installment_client_reminder' => [
                'name' => 'Payment plan installment missed — client reminder',
                'description' => 'Sends a client reminder when a scheduled payment plan installment is missed.',
                'event_type' => DomainEventType::PaymentPlanInstallmentMissed,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::NotifyClient->value, 'config' => [
                        'template_key' => 'payment_plan_installment_missed',
                        'channel' => 'email',
                    ]],
                ],
            ],

            // Item 10. Only fires an onboarding action when the
            // completed signature request is matter-linked — not every
            // SignatureRequest is (source_document_type may be a
            // standalone Document with no matter_id).
            'signature_request_completed_onboarding' => [
                'name' => 'Signature completed — onboarding',
                'description' => 'Creates a follow-up task for the assigned attorney once a matter-linked signature request completes.',
                'event_type' => DomainEventType::SignatureRequestCompleted,
                'conditions' => [
                    ['field' => 'matter.id', 'operator' => AutomationConditionOperator::IsNotNull->value, 'value' => null],
                ],
                'actions' => [
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => [
                        'title' => 'Begin onboarding — signature completed',
                        'assigned_to' => 'matter_assigned_attorney',
                        'due_in_days' => 2,
                    ]],
                ],
            ],

            // Mission 3 (MyAttorney Conversion + AI Intake), checkpoint
            // 12. The earliest automation can react to a new matter —
            // before matter_opened_next_task/new_matter_onboarding_documents
            // (both keyed on MatterOpened, which requires a clear
            // conflict check first — this mission's own top-line rule
            // forbids automation from bypassing that gate). Assigned to
            // the Firm Owner rather than 'matter_assigned_attorney'
            // because a matter is very often created without an
            // attorney assigned yet (e.g. a MyAttorney conversion,
            // where assignment is optional at conversion time) — the
            // Firm Owner is the one recipient guaranteed to exist.
            'matter_created_review_task' => [
                'name' => 'New matter created — review task',
                'description' => 'Creates a review task for the Firm Owner when a new matter is created, so it can be checked for readiness (conflict check, attorney assignment) before opening.',
                'event_type' => DomainEventType::MatterCreated,
                'conditions' => [],
                'actions' => [
                    ['action_type' => AutomationActionType::CreateTask->value, 'config' => [
                        'title' => 'Review new matter',
                        'assigned_to' => 'role:firm_owner',
                        'due_in_days' => 2,
                    ]],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::templates()[$key] ?? null;
    }
}
