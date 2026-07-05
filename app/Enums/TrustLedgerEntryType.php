<?php

namespace App\Enums;

/**
 * TrustLedgerEntryType — every trust_ledger_entries row is exactly one
 * of these. amount_cents is signed: Deposit is positive; WithdrawalToInvoice,
 * Refund, and ChargebackReversal (of a prior positive deposit) are
 * negative; Adjustment may be either sign (two-approver-gated,
 * TrustHighRiskAdjustmentService); Reversal is always the exact
 * opposite sign of the entry it reverses (reverses_entry_id).
 *
 * There is no TrustLedgerEntryStatus enum (approved correction #5) —
 * entries are created once and never transition; the ONLY way a prior
 * entry is corrected is a brand-new Reversal row referencing it via
 * reverses_entry_id. The original row's fields never change.
 */
enum TrustLedgerEntryType: string
{
    case Deposit = 'deposit';
    case WithdrawalToInvoice = 'withdrawal_to_invoice';
    case Refund = 'refund';
    case ChargebackReversal = 'chargeback_reversal';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
}
