<?php

namespace App\Enums;

/**
 * TrustApprovalEventType — the closed set of append-only
 * trust_approval_events rows. Chargebacks are deliberately absent from
 * this list — chargebacks are externally-reported facts, not
 * discretionary approvals, and are tracked entirely by
 * trust_chargeback_events/TrustChargebackStatus instead (design §13).
 * TrustModeActivationLinked is a read-only cross-reference to an
 * already-Approved Phase 7 HighRiskChangeRequest — it never represents
 * a second approval mechanism for trust-mode activation.
 */
enum TrustApprovalEventType: string
{
    case DepositRequested = 'deposit_requested';
    case DepositApproved = 'deposit_approved';
    case DepositDenied = 'deposit_denied';
    case TransferRequested = 'transfer_requested';
    case TransferApproved = 'transfer_approved';
    case TransferDenied = 'transfer_denied';
    case TransferApplied = 'transfer_applied';
    case RefundRequested = 'refund_requested';
    case RefundApproved = 'refund_approved';
    case RefundDenied = 'refund_denied';
    case RefundCompleted = 'refund_completed';
    case AdjustmentRequested = 'adjustment_requested';
    case AdjustmentFirstApproved = 'adjustment_first_approved';
    case AdjustmentSecondApproved = 'adjustment_second_approved';
    case AdjustmentDenied = 'adjustment_denied';
    case TrustModeActivationLinked = 'trust_mode_activation_linked';
    case ReconciliationCompleted = 'reconciliation_completed';
}
