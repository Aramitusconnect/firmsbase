<?php

namespace App\Enums;

enum DataProcessingRecordType: string
{
    case ClientIntake = 'client_intake';
    case DocumentStorage = 'document_storage';
    case AiProcessing = 'ai_processing';
    case PaymentProcessing = 'payment_processing';
    case EmailCommunication = 'email_communication';
    case TrustAccounting = 'trust_accounting';
    case ExportOffboarding = 'export_offboarding';
    case SupportAccess = 'support_access';
    case Other = 'other';
}
