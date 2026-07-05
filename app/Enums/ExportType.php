<?php

namespace App\Enums;

enum ExportType: string
{
    case Clients = 'clients';
    case Matters = 'matters';
    case Documents = 'documents';
    case BillingRecords = 'billing_records';
    case Logs = 'logs';
    case Templates = 'templates';
    case OffboardingPackage = 'offboarding_package';
}
