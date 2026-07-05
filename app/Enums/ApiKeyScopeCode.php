<?php

namespace App\Enums;

/**
 * ApiKeyScopeCode — the fixed catalog of firm API scopes. Approved
 * exact value set (Phase 8 correction #3). ApiAccessPolicyService is
 * the only place a scope is checked against firm entitlements
 * (EntitlementService::isEnabled($firmId, 'api')) and against the
 * FirmUserRole allowlist for the actor requesting the key.
 */
enum ApiKeyScopeCode: string
{
    case ClientsRead = 'clients_read';
    case ClientsWrite = 'clients_write';
    case MattersRead = 'matters_read';
    case MattersWrite = 'matters_write';
    case DocumentsRead = 'documents_read';
    case DocumentsWrite = 'documents_write';
    case InvoicesRead = 'invoices_read';
    case InvoicesWrite = 'invoices_write';
    case PaymentPlansRead = 'payment_plans_read';
    case PaymentPlansWrite = 'payment_plans_write';
    case TimeEntriesRead = 'time_entries_read';
    case ImportManage = 'import_manage';
    case ExportManage = 'export_manage';
}
