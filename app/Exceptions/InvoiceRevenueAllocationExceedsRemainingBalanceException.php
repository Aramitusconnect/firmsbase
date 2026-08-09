<?php

namespace App\Exceptions;

/**
 * InvoiceRevenueAllocationExceedsRemainingBalanceException —
 * Mixed-Invoice Revenue Allocation pass, item 2. Thrown by
 * PaymentApplicationService::resolveInvoiceRevenueAllocation() when a
 * purpose-constrained payment (PaymentRequestPurpose::EarnedFee or
 * FilingCostReimbursement) would exceed its own bucket's remaining
 * balance on a mixed invoice. Never silently reclassified into the
 * other bucket or clamped — the caller (ManualPaymentService::submit(),
 * inside PaymentRequestCheckoutService::routeConfirmedPayment()'s own
 * catch(Throwable)) routes this to the request's existing PendingReview
 * state, the same governed path already used for any other downstream
 * routing failure.
 */
class InvoiceRevenueAllocationExceedsRemainingBalanceException extends \RuntimeException {}
