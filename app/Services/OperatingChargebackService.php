<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\PaymentReversalType;
use App\Enums\PaymentStatus;
use App\Enums\PendingPaymentAllocationStatus;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use App\Models\PendingPaymentAllocation;
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
 *
 * Pending-Cash Accounting pass — a payment still awaiting fee/cost
 * allocation is charged back via AccountingJournalReversalService::reverse()
 * against its exact UnappliedFundsReceived entry (no revenue was ever
 * posted for it to begin with), and its PendingPaymentAllocation is
 * cancelled rather than left dangling — see
 * chargebackWhilePending()'s own docblock. An already-resolved mixed
 * payment reverses each bucket it actually recognized (never guessing
 * 100% Legal Fee Revenue) — see revenueReversalComposition()'s own
 * docblock — unless a prior partial reversal already exists, in which
 * case it is refused rather than guessed at.
 */
class OperatingChargebackService
{
    public function __construct(
        private readonly PaymentApplicationService $application,
        private readonly OperatingJournalRecorderService $journal,
        private readonly AccountingJournalReversalService $reversal,
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
            $openPending = PendingPaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->where('status', PendingPaymentAllocationStatus::Pending)
                ->first();

            if ($openPending !== null) {
                return $this->chargebackWhilePending($firm, $payment, $openPending, $amountCents, $reason, $reportedBy);
            }

            // revenue_purpose IS NULL is the Phase F multi-target split
            // marker (applySplit()); a revenue_purpose-tagged row is the
            // Mixed-Invoice Revenue Allocation pass's own bucket tracking
            // for a SINGLE target and does not indicate a split payment.
            if (PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('revenue_purpose')->exists()) {
                throw new \RuntimeException('This payment was split-allocated across multiple targets; charging back a split payment is not supported.');
            }

            $alreadyReversed = (int) PaymentReversal::query()
                ->where('payment_id', $payment->id)
                ->sum('amount_cents');

            $remaining = $payment->amount_cents - $alreadyReversed;

            if ($amountCents !== $remaining) {
                throw new \RuntimeException('Chargeback amount must exactly match the payment\'s remaining amount; partial chargebacks are not supported.');
            }

            // Pending-Cash Accounting pass, section 6 — see
            // OperatingPaymentRefundService's matching guard. Chargeback
            // already only ever covers the full remaining amount
            // (partial chargebacks are never supported at all), so the
            // only remaining ambiguity is a chargeback following a
            // PRIOR partial reversal against a mixed-revenue payment —
            // refused, never guessed at.
            [$feeCents, $costCents] = $this->revenueReversalComposition($payment);

            if ($costCents > 0 && $alreadyReversed > 0) {
                throw new \RuntimeException('This payment recognized cost-reimbursement revenue and was already partially reversed; charging back the remainder cannot be reversed unambiguously by bucket.');
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
                feeCents: $costCents > 0 ? $feeCents : null,
                costCents: $costCents > 0 ? $costCents : null,
            );

            // Returns the already-updated in-memory model rather than
            // ->fresh() — see OperatingPaymentRefundService::refund()'s
            // matching comment for why a re-query here is both
            // unnecessary and unsafe immediately after recordCashOut()'s
            // own nested runWithFirmContext() call.
            return $payment;
        }));
    }

    /**
     * Pending-Cash Accounting pass, section 5 — mirrors
     * OperatingPaymentRefundService::refundWhilePending() exactly, for
     * the chargeback direction. No separate "must be the full pending
     * amount" check is needed here: this method is only ever reached
     * after report()'s own pre-existing "amountCents must exactly equal
     * the payment's remaining (not-already-reversed) amount" check,
     * and a still-Pending payment has no prior reversal, so remaining
     * always equals payment.amount_cents — which always equals
     * $pending->amount_cents by construction (a payment is deferred in
     * full or not at all).
     */
    private function chargebackWhilePending(Firm $firm, Payment $payment, PendingPaymentAllocation $pending, int $amountCents, string $reason, ?FirmUser $reportedBy): Payment
    {
        $alreadyReversed = (int) PaymentReversal::query()
            ->where('payment_id', $payment->id)
            ->sum('amount_cents');

        $remaining = $payment->amount_cents - $alreadyReversed;

        if ($amountCents !== $remaining) {
            throw new \RuntimeException('Chargeback amount must exactly match the payment\'s remaining amount; partial chargebacks are not supported.');
        }

        $receiptEntry = AccountingJournalEntry::query()
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsReceived->value)
            ->first();

        if ($receiptEntry !== null) {
            $this->reversal->reverse(
                $firm,
                $receiptEntry,
                "Chargeback before allocation — payment #{$payment->id}: {$reason}",
                $reportedBy,
            );
        }

        PaymentReversal::create([
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

        $pending->update([
            'status' => PendingPaymentAllocationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $payment;
    }

    /**
     * @return array{0:int, 1:int} [feeCents, costCents] — see
     *                             OperatingPaymentRefundService::revenueReversalComposition()'s
     *                             own docblock; identical query,
     *                             duplicated rather than shared since
     *                             both services are otherwise
     *                             independent and neither currently
     *                             depends on the other.
     */
    private function revenueReversalComposition(Payment $payment): array
    {
        $rows = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->whereNotNull('revenue_purpose')
            ->selectRaw('revenue_purpose, SUM(amount_cents) as total_cents')
            ->groupBy('revenue_purpose')
            ->pluck('total_cents', 'revenue_purpose');

        return [
            (int) ($rows[ChartOfAccountPurpose::LegalFeeRevenue->value] ?? 0),
            (int) ($rows[ChartOfAccountPurpose::CostReimbursementRevenue->value] ?? 0),
        ];
    }
}
