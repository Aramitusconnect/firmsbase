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
        ];
    }

    public static function get(string $key): ?array
    {
        return self::templates()[$key] ?? null;
    }
}
