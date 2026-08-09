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
    case Chargeback = 'chargeback';

    /**
     * Accounting Integrity Hardening Pass, item 8 — the one-time
     * cutover entry AccountingOpeningBalanceService posts when a firm
     * adopts native accounting mid-flight. Never posted by any other
     * service; never posted more than once per firm (enforced by that
     * service, both a pre-check and an idempotency key).
     */
    case OpeningBalanceCutover = 'opening_balance_cutover';

    /**
     * Pending-Cash Accounting pass. Posted by
     * OperatingJournalRecorderService::recordUnappliedFundsReceived()
     * the moment a confirmed operating payment's fee/cost revenue
     * allocation is ambiguous: Dr Operating Cash / Cr
     * UnappliedOperatingFundsLiability, for the full payment amount.
     * The cash is real and recorded immediately; no revenue is
     * recognized yet.
     */
    case UnappliedFundsReceived = 'unapplied_funds_received';

    /**
     * Pending-Cash Accounting pass. Posted by
     * OperatingJournalRecorderService::recordUnappliedFundsResolved()
     * when an authorized user resolves a PendingPaymentAllocation: Dr
     * UnappliedOperatingFundsLiability / Cr LegalFeeRevenue and/or Cr
     * CostReimbursementRevenue, for the exact resolved split. Never
     * debits Operating Cash again — that leg was already posted by
     * UnappliedFundsReceived.
     */
    case UnappliedFundsResolved = 'unapplied_funds_resolved';
}
