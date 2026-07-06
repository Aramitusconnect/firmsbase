<?php

namespace App\Enums;

enum DataCategory: string
{
    case Pii = 'pii';
    case PaymentData = 'payment_data';
    case LegalDocuments = 'legal_documents';
    case Communications = 'communications';
    case AiContent = 'ai_content';
    case TrustFinancialData = 'trust_financial_data';
    case IdentityDocuments = 'identity_documents';
}
