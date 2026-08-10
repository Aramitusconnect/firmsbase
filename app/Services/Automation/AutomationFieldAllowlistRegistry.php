<?php

namespace App\Services\Automation;

use App\Enums\DomainEventType;

/**
 * AutomationFieldAllowlistRegistry — Event-Driven Automation Engine,
 * item 5. The single source of truth for which dot-path fields a
 * DomainEvent of a given type may carry in its payload_json, and which
 * fields an AutomationRule's conditions_json may reference. Both
 * DomainEventRecorderService (write side — refuses to persist a payload
 * with any key not on this list) and ConditionEvaluatorService (read
 * side — refuses to evaluate a condition against any field not on this
 * list) consult the SAME allowlist, so there is no way for a condition
 * to reference a field the payload never actually promises to carry,
 * and no way for a payload to smuggle in an unlisted (and therefore
 * un-vetted) field — passwords, API credentials, cross-tenant data, or
 * any other raw database column never explicitly reviewed and added
 * here.
 *
 * Deliberately hand-authored per event type (never reflection over a
 * model's own $fillable/columns) — exactly the "no arbitrary database
 * fields" requirement. Adding a field here is a reviewed code change,
 * never a runtime/config decision.
 */
final class AutomationFieldAllowlistRegistry
{
    private const ALLOWLISTS = [
        'invoice_overdue' => [
            'invoice.id', 'invoice.status', 'invoice.balance_due_cents',
            'invoice.total_cents', 'invoice.days_overdue', 'invoice.bucket',
            'client.id', 'matter.id',
        ],
        'deadline_approaching' => [
            'deadline.id', 'deadline.title', 'deadline.deadline_type',
            'deadline.due_at', 'deadline.days_until_due',
            'matter.id', 'matter.assigned_attorney_id',
        ],
        'deadline_missed' => [
            'deadline.id', 'deadline.title', 'deadline.deadline_type', 'deadline.due_at',
            'deadline.days_until_due', 'matter.id', 'matter.assigned_attorney_id',
        ],
        'document_uploaded' => [
            'document.id', 'document.file_name', 'document.document_request_item_id',
            'document.matter_id', 'matter.id', 'matter.assigned_attorney_id', 'client.id',
        ],
        'matter_opened' => [
            'matter.id', 'matter.client_id', 'matter.assigned_attorney_id', 'matter.status',
        ],
        'payment_plan_installment_missed' => [
            'installment.id', 'installment.amount_cents', 'installment.due_at', 'installment.sequence',
            'payment_plan.id', 'payment_plan.client_id', 'payment_plan.matter_id',
        ],
        'payment_allocation_pending' => [
            'pending_allocation.id', 'pending_allocation.payment_id',
            'pending_allocation.invoice_id', 'pending_allocation.amount_cents',
        ],
        'matter_budget_threshold_crossed' => [
            'matter_budget_alert.alert_type', 'matter_budget_alert.metric_key',
            'matter_budget_alert.severity', 'matter_budget_alert.threshold_percent_crossed',
            'matter.id', 'matter.assigned_attorney_id',
        ],
        'matter_leverage_recommendation_created' => [
            'leverage_recommendation.recommendation_type', 'leverage_recommendation.confidence',
            'leverage_recommendation.dedup_key',
            'matter.id', 'matter.assigned_attorney_id',
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedFields(DomainEventType $type): array
    {
        return self::ALLOWLISTS[$type->value] ?? [];
    }

    public static function isAllowed(DomainEventType $type, string $field): bool
    {
        return in_array($field, self::allowedFields($type), true);
    }
}
