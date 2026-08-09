<?php

namespace App\Enums;

/**
 * PaymentRequestStatus — Payment Link / QR Routing phase. The lifecycle
 * states named explicitly by the phase spec. PaymentRequestService and
 * PaymentRequestCheckoutService are the only writers.
 *
 * Payment-Channel Safety Hardening pass, item 8 — exact status
 * semantics, so "Paid" can never be read as merely "the provider UI
 * returned success":
 *
 *   - Draft / Active / Expired / Revoked: pre-money lifecycle states,
 *     written only by PaymentRequestService — never touched by
 *     PaymentRequestCheckoutService.
 *   - Paid: set ONLY by PaymentRequestCheckoutService::routeOperatingPayment(),
 *     and ONLY after ManualPaymentService::submit() has ALREADY
 *     returned a genuinely accepted Payment — meaning
 *     PaymentClassificationService accepted it, PaymentApplicationService
 *     applied it to its target, AND OperatingJournalRecorderService
 *     posted (or was legitimately not-applicable for) the accounting
 *     consequence. If any of those steps throws (including
 *     AccountingSetupIncompleteException under the atomic post-or-block
 *     policy), routeConfirmedPayment()'s own catch(Throwable) routes to
 *     PendingReview instead — Paid is never reached on a partial or
 *     failed application.
 *   - PendingReview: set for a Trust deposit (dual-control approval
 *     always required — see TrustDepositService), OR for a confirmed
 *     Operating payment that could not be routed into the canonical
 *     domain (a downstream throw). Money was confirmed by the provider,
 *     but the request's own accounting/Trust consequence is not yet
 *     final — a human resolves it through the normal service layer.
 *   - Failed: the provider itself declined/errored (an ordinary,
 *     expected outcome, never a state/programming error) — no money
 *     was ever confirmed at all.
 *
 * A provider-unavailable attempt (PaymentProviderUnavailableException,
 * item 1) is deliberately NOT one of the states above — it leaves the
 * request status entirely UNCHANGED (still Active if it was Active),
 * because "no provider is configured right now" is an environment
 * condition, not a fact about this specific request; the exact same
 * link must become payable again the moment a real provider is
 * configured, without needing a new request.
 */
enum PaymentRequestStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Paid = 'paid';
    case PendingReview = 'pending_review';
    case Failed = 'failed';
}
