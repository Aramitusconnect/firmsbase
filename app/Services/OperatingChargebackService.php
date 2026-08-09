<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\PaymentReversalType;
use App\Enums\PaymentStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use Illuminate\Support\Facades\DB;

/**
 * OperatingChargebackService — Phase G. The only writer of forced,
 * processor-initiated operating chargebacks (payment_reversals with
 * reversal_type=chargeback). Distinct from TrustChargebackService
 * (Trust domain, untouched).
 *
 * Mirrors TrustChargebackService's own established rule in this exact
 * codebase (fixed earlier this session): the chargeback amount must
 * EXACTLY match the payment's remaining (not-already-reversed)
 * amount — partial chargebacks are not supported, for the same reason
 * TrustChargebackService doesn't support them (a card network
 * chargeback is an all-or-nothing dispute outcome on the disputed
 * transaction amount, not a partial negotiation) — "chargeback
 * amounts must reconcile to actual reversed amounts" is trivially true
 * by construction here rather than merely asserted after the fact.
 */
class OperatingChargebackService
{
    public function __construct(
        private readonly PaymentApplicationService $application,
        private readonly OperatingJournalRecorderService $journal,
    ) {}

    public function report(Firm $firm, Payment $payment, int $amountCents, string $reason, ?FirmUser $reportedBy = null): Payment
    {
        if ((int) $payment->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This payment does not belong to this firm.');
        }

        if (! $payment->isAcceptedOperatingPayment()) {
            throw new \RuntimeException('Only an accepted operating payment can be charged back.');
        }

        // Every remaining check reads payment_allocations/payment_reversals,
        // both permanent FORCE ROW LEVEL SECURITY tables — under no
        // context a SELECT silently sees zero rows rather than erroring,
        // so both checks below are only correct INSIDE the firm context
        // wrap, not before it.
        return (new TenantContextService)->runWithFirmContext($firm, fn () => DB::transaction(function () use ($firm, $payment, $amountCents, $reason, $reportedBy) {
            if (PaymentAllocation::query()->where('payment_id', $payment->id)->exists()) {
                throw new \RuntimeException('This payment was split-allocated across multiple targets; charging back a split payment is not supported.');
            }

            $alreadyReversed = (int) PaymentReversal::query()
                ->where('payment_id', $payment->id)
                ->sum('amount_cents');

            $remaining = $payment->amount_cents - $alreadyReversed;

            if ($amountCents !== $remaining) {
                throw new \RuntimeException('Chargeback amount must exactly match the payment\'s remaining amount; partial chargebacks are not supported.');
            }

            $reversal = PaymentReversal::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'payment_plan_installment_id' => $payment->payment_plan_installment_id,
                'reversal_type' => PaymentReversalType::Chargeback,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'actor_firm_user_id' => $reportedBy?->id,
                'created_at' => now(),
            ]);

            $payment->update(['status' => PaymentStatus::Reversed]);

            if ($payment->invoice_id !== null) {
                $this->application->reverseAmountFromInvoice($payment->invoice, $amountCents);
            } elseif ($payment->payment_plan_installment_id !== null) {
                $this->application->reverseAmountFromInstallment($payment->paymentPlanInstallment, $amountCents);
            }

            $this->journal->recordCashOut(
                $firm,
                AccountingJournalSourceType::Chargeback,
                "Chargeback — payment #{$payment->id}: {$reason}",
                $amountCents,
                "chargeback:payment_reversal:{$reversal->id}",
                paymentId: $payment->id,
                invoiceId: $payment->invoice_id,
            );

            // Returns the already-updated in-memory model rather than
            // ->fresh() — see OperatingPaymentRefundService::refund()'s
            // matching comment for why a re-query here is both
            // unnecessary and unsafe immediately after recordCashOut()'s
            // own nested runWithFirmContext() call.
            return $payment;
        }));
    }
}
