<?php

namespace App\Enums;

/**
 * ProductAnalyticsEventType — the closed set of event types
 * ProductAnalyticsEventService is allowed to record. Recording an event
 * type outside this enum must be rejected (see the service's own test:
 * "product analytics events record allowed event types").
 */
enum ProductAnalyticsEventType: string
{
    case FirmCreated = 'firm_created';
    case UserInvited = 'user_invited';
    case LeadCreated = 'lead_created';
    case ConsultationHeld = 'consultation_held';
    case ClientCreated = 'client_created';
    case MatterCreated = 'matter_created';
    case DocumentRequestSent = 'document_request_sent';
    case DocumentUploaded = 'document_uploaded';
    case IntakeSubmitted = 'intake_submitted';
    case InvoiceCreated = 'invoice_created';
    case PaymentPlanCreated = 'payment_plan_created';
    case PaymentRecorded = 'payment_recorded';
    case AiUsed = 'ai_used';
    case TemplateInstalled = 'template_installed';
    case ClientPortalLogin = 'client_portal_login';
    case ReminderSent = 'reminder_sent';
    case MatterReadyForReview = 'matter_ready_for_review';
}
