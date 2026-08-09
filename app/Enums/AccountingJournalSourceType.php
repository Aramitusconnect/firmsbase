<?php

namespace App\Enums;

/**
 * AccountingJournalSourceType — the closed set of domain events that
 * may post to accounting_journal_entries, mirroring PaymentClassification's
 * closed-enum discipline (this is a consequential financial
 * classification, not a free-text label like payment_plan_events.event_type).
 * Every journal entry traces back to exactly one of these; the
 * corresponding structured FK column on accounting_journal_entries
 * (payment_id, invoice_id, expense_id, trust_transfer_request_id) is
 * populated according to which source type posted it.
 */
enum AccountingJournalSourceType: string
{
    case InvoicePaymentApplied = 'invoice_payment_applied';
    case TrustToOperatingTransfer = 'trust_to_operating_transfer';
    case ExpensePaid = 'expense_paid';
    case Refund = 'refund';
    case WriteOff = 'write_off';
    case Adjustment = 'adjustment';
}
