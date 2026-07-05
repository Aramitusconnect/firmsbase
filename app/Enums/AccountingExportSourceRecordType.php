<?php

namespace App\Enums;

/**
 * AccountingExportSourceRecordType — names which of the three nullable
 * FKs on accounting_export_lines (invoice_id/payment_id/expense_id) is
 * populated for a given row, mirroring Phase 11's
 * SignatureSourceDocumentType dual-FK "source typing" pattern (a typed
 * enum column plus service-enforced XOR, not a morph relation).
 */
enum AccountingExportSourceRecordType: string
{
    case Invoice = 'invoice';
    case Payment = 'payment';
    case Expense = 'expense';
}
