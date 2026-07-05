<?php

namespace App\Enums;

/**
 * WebhookEventType — the exact 11 approved Phase 14 event types
 * (correction #15). No other event type may be subscribed to or
 * recorded — WebhookEventTypeRegistry is the single place these cases
 * are validated against, so this list is enforced closed, not by
 * convention.
 */
enum WebhookEventType: string
{
    case LeadCreated = 'lead.created';
    case ClientCreated = 'client.created';
    case MatterCreated = 'matter.created';
    case DocumentUploaded = 'document.uploaded';
    case InvoiceCreated = 'invoice.created';
    case PaymentPlanInstallmentDue = 'payment_plan.installment_due';
    case PaymentRecorded = 'payment.recorded';
    case TaskCompleted = 'task.completed';
    case FormApproved = 'form.approved';
    case SignatureCompleted = 'signature.completed';
    case MatterReadinessChanged = 'matter.readiness_changed';
}
