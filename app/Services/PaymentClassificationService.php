<?php

namespace App\Services;

use App\Enums\PaymentClassification;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\Firm;
use App\Models\Payment;
use App\Models\PaymentClassificationEvent;
use App\Models\User;
use App\ValueObjects\PaymentClassificationResult;

/**
 * PaymentClassificationService — the ONLY place a payment's
 * classification is decided and logged. Must be reused as-is by
 * Phase 6 Stripe flows and Phase 13 trust accounting (project rule) —
 * never forked or duplicated.
 *
 * Trust/IOLTA rule (project rule 6 / core rule 6): ANY requested
 * trust_iolta_payment classification is blocked in this codebase right
 * now, unconditionally, regardless of the firm's configured
 * firm_settings.payment_mode — Phase 13's trust accounting foundation
 * has not been built or accepted yet, so there is no configuration
 * that can legitimately unblock it. PaymentMode::OperatingAndTrust
 * only records what the firm is eventually ALLOWED to do once Phase 13
 * ships; it does not itself unblock anything today.
 */
class PaymentClassificationService
{
    /**
     * Pure decision logic — no database writes. Split out from
     * recordDecision() so classification can be reasoned about and
     * unit tested independently of persistence.
     */
    public function classify(Firm $firm, PaymentClassification $requested): PaymentClassificationResult
    {
        $paymentMode = $firm->firmSettings?->payment_mode;

        if ($paymentMode === PaymentMode::Blocked) {
            return new PaymentClassificationResult(
                resolvedClassification: PaymentClassification::BlockedPayment,
                status: PaymentStatus::Blocked,
                accepted: false,
                rejectionReason: 'Payments are disabled for this firm (payment_mode=blocked).',
            );
        }

        if ($requested === PaymentClassification::TrustIoltaPayment) {
            return new PaymentClassificationResult(
                resolvedClassification: PaymentClassification::BlockedPayment,
                status: PaymentStatus::Blocked,
                accepted: false,
                rejectionReason: 'Trust/IOLTA deposits are blocked until the Phase 13 trust accounting foundation is accepted.',
            );
        }

        if ($requested === PaymentClassification::BlockedPayment) {
            return new PaymentClassificationResult(
                resolvedClassification: PaymentClassification::BlockedPayment,
                status: PaymentStatus::Blocked,
                accepted: false,
                rejectionReason: 'Explicitly classified as blocked.',
            );
        }

        return new PaymentClassificationResult(
            resolvedClassification: PaymentClassification::OperatingPayment,
            status: PaymentStatus::Succeeded,
            accepted: true,
        );
    }

    /**
     * Persists the decision: updates the (already-created, Initiated-
     * status) Payment row in place, and writes an append-only
     * PaymentClassificationEvent — for BOTH accepted and blocked
     * outcomes, so every classification decision is auditable, not
     * just rejected ones.
     */
    public function recordDecision(
        Payment $payment,
        PaymentClassification $requested,
        PaymentClassificationResult $result,
        ?User $actor = null,
    ): PaymentClassificationEvent {
        // Deliberately NOT self-wrapped in runWithFirmContext(): this
        // method is always called from within a caller that has
        // already established firm context for the whole operation
        // (ManualPaymentService::submit(), TrustTransferRequestService
        // ::apply()) — a nested runWithFirmContext() call here would
        // clear that context in its own finally block the moment this
        // method returns, breaking the caller's own subsequent reads.
        $payment->update([
            'payment_classification' => $result->resolvedClassification,
            'status' => $result->status,
            'rejection_reason' => $result->rejectionReason,
        ]);

        return PaymentClassificationEvent::create([
            'firm_id' => $payment->firm_id,
            'payment_id' => $payment->id,
            'event_type' => $result->accepted ? 'classification_accepted' : 'classification_blocked',
            'requested_classification' => $requested,
            'resolved_classification' => $result->resolvedClassification,
            'reason' => $result->rejectionReason,
            'actor_user_id' => $actor?->id,
        ]);
    }
}
