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
 * OperatingPaymentRefundService — Phase G. The only writer of
 * voluntary, firm-client operating refunds (payment_reversals with
 * reversal_type=refund). Distinct from TrustRefundRequestService
 * (Trust domain, client trust funds, untouched) and
 * PlatformRefundService (SaaS subscription billing, untouched).
 *
 * Never mutates prior journal history: the accounting consequence is
 * always a NEW compensating posting. For an ordinary (already-applied)
 * payment this is via OperatingJournalRecorderService::recordCashOut()
 * — never AccountingJournalReversalService::reverse(), since the
 * original fee-earned entry is correct as posted; the refund is its
 * own, separate real event, not a correction of a mistake.
 *
 * Pending-Cash Accounting pass — the ONE exception: a payment still
 * awaiting fee/cost allocation (PendingPaymentAllocation, still
 * Pending) never had revenue posted at all, only cash received
 * (recordUnappliedFundsReceived()). Refunding it in full genuinely IS
 * a correction — undoing a receipt that never resolved into anything
 * — so this ONE case does use AccountingJournalReversalService::reverse()
 * against that exact receipt entry, and cancels the pending row
 * (never resolves it, since no split was ever decided for money that
 * was given back). See refundWhilePending()'s own docblock.
 *
 * A payment whose fee/cost split HAS already been recognized (fully
 * applied, not pending) reverses each bucket it actually recognized —
 * see revenueReversalComposition()'s own docblock — but ONLY when this
 * is a single, first, full-amount refund; a partial refund of a mixed
 * payment, or a refund following a prior partial reversal, is refused
 * with a clear error rather than guessed at (the exact same class of
 * ambiguity forward partial payments refuse to invent a policy for).
 */
class OperatingPaymentRefundService
{
    public function __construct(
        private readonly PaymentApplicationService $application,
        private readonly OperatingJournalRecorderService $journal,
        private readonly AccountingJournalReversalService $reversal,
    ) {}

    public function refund(Firm $firm, Payment $payment, int $amountCents, string $reason, ?FirmUser $refundedBy = null): Payment
    {
        if ((int) $payment->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This payment does not belong to this firm.');
        }

        if (! $payment->isAcceptedOperatingPayment() && $payment->status !== PaymentStatus::PartiallyRefunded) {
            throw new \RuntimeException('Only an accepted operating payment (or one already partially refunded) can be refunded.');
        }

        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }

        // Every remaining check reads payment_reversals, which has
        // permanent FORCE ROW LEVEL SECURITY — under no context a SELECT
        // silently sees zero rows rather than erroring, so the
        // over-refund check below is only correct INSIDE the firm
        // context wrap, not before it.
        return (new TenantContextService)->runWithFirmContext($firm, fn () => DB::transaction(function () use ($firm, $payment, $amountCents, $reason, $refundedBy) {
            $openPending = PendingPaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->where('status', PendingPaymentAllocationStatus::Pending)
                ->first();

            if ($openPending !== null) {
                return $this->refundWhilePending($firm, $payment, $openPending, $amountCents, $reason, $refundedBy);
            }

            // Payment allocation splitting (Phase F) support is
            // intentionally out of scope here: a split payment funds
            // multiple targets and refunding it would require choosing
            // which allocation(s) to relieve, an ambiguity the master
            // accounting prompt does not resolve. Refunding a split
            // payment is refused with a clear error rather than guessed
            // at.
            if ($this->isSplitAllocated($payment)) {
                throw new \RuntimeException('This payment was split-allocated across multiple targets; refunding a split payment is not supported.');
            }

            $alreadyRefunded = (int) PaymentReversal::query()
                ->where('payment_id', $payment->id)
                ->where('reversal_type', PaymentReversalType::Refund->value)
                ->sum('amount_cents');

            if ($alreadyRefunded + $amountCents > $payment->amount_cents) {
                throw new \RuntimeException('Total refunds cannot exceed the original payment amount.');
            }

            // Pending-Cash Accounting pass, section 6 — a payment whose
            // recognized revenue spans a cost-reimbursement bucket is
            // only reversible unambiguously when this is the FIRST and
            // ONLY reversal covering the ENTIRE original amount: in
            // that one case, the payment's own PaymentAllocation rows
            // ARE the unambiguous basis for exactly how to reverse it
            // (Dr each recognized bucket for its own recognized
            // amount). A prior partial reversal, or a refund that
            // itself doesn't cover the full amount, reintroduces the
            // same "which bucket does this slice belong to" ambiguity
            // forward partial payments refuse to guess at — refused
            // here too, never invented.
            [$feeCents, $costCents] = $this->revenueReversalComposition($payment);

            if ($costCents > 0 && ($alreadyRefunded > 0 || $amountCents !== $payment->amount_cents)) {
                throw new \RuntimeException('This payment recognized cost-reimbursement revenue; only a single, full refund of the entire original amount can be reversed unambiguously by bucket. A partial refund (or a refund following a prior partial reversal) is not supported.');
            }

            $reversal = PaymentReversal::create([
                'firm_id' => $firm->id,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'payment_plan_installment_id' => $payment->payment_plan_installment_id,
                'reversal_type' => PaymentReversalType::Refund,
                'amount_cents' => $amountCents,
                'reason' => $reason,
                'actor_firm_user_id' => $refundedBy?->id,
                'created_at' => now(),
            ]);

            $newTotal = $alreadyRefunded + $amountCents;
            $payment->update([
                'status' => $newTotal >= $payment->amount_cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ]);

            if ($payment->invoice_id !== null) {
                $this->application->reverseAmountFromInvoice($payment->invoice, $amountCents);
            } elseif ($payment->payment_plan_installment_id !== null) {
                $this->application->reverseAmountFromInstallment($payment->paymentPlanInstallment, $amountCents);
            }

            $this->journal->recordCashOut(
                $firm,
                AccountingJournalSourceType::Refund,
                "Refund — payment #{$payment->id}: {$reason}",
                $amountCents,
                "refund:payment_reversal:{$reversal->id}",
                paymentId: $payment->id,
                invoiceId: $payment->invoice_id,
                feeCents: $costCents > 0 ? $feeCents : null,
                costCents: $costCents > 0 ? $costCents : null,
            );

            // Returns the already-updated in-memory model rather than
            // ->fresh(): a re-query here would run after recordCashOut()'s
            // own nested runWithFirmContext() has already restored/
            // narrowed the local (transaction-scoped) Postgres session
            // setting on its way out, and $payment->update() above has
            // already mutated every attribute this method promises the
            // caller — nothing further needs a fresh DB round trip.
            return $payment;
        }));
    }

    /**
     * Pending-Cash Accounting pass, section 5 — a payment whose
     * fee/cost allocation is still Pending never had revenue posted at
     * all (only recordUnappliedFundsReceived()'s cash entry), and was
     * never applied to its invoice/installment
     * (invoices.amount_paid_cents/payment_plan_installments.paid_amount_cents
     * were both left untouched — see ManualPaymentService::applyOrDeferInvoice()/
     * applyOrDeferInstallment()). So this refund path:
     *   - accepts ONLY a full refund of the entire pending amount — a
     *     PARTIAL refund while pending would require deciding which
     *     part of the still-undetermined fee/cost split it relieves, an
     *     even more fundamental ambiguity than a partial refund AFTER
     *     allocation; refused with a clear error rather than guessed
     *     at, same as every other ambiguity this mission refuses to
     *     invent a policy for.
     *   - reverses the cash-received entry itself
     *     (AccountingJournalReversalService::reverse() against the
     *     exact UnappliedFundsReceived entry — never a fresh
     *     recordCashOut(), since no revenue exists to reverse).
     *   - never calls reverseAmountFromInvoice()/reverseAmountFromInstallment():
     *     nothing was ever applied there to reverse; calling it anyway
     *     would wrongly decrement amount_paid_cents by money other,
     *     already-resolved payments against the SAME invoice
     *     legitimately contributed.
     *   - cancels the pending row (PendingPaymentAllocationStatus::Cancelled)
     *     rather than resolving it — no split was ever decided for
     *     money that was given back.
     */
    private function refundWhilePending(Firm $firm, Payment $payment, PendingPaymentAllocation $pending, int $amountCents, string $reason, ?FirmUser $refundedBy): Payment
    {
        if ($amountCents !== $pending->amount_cents) {
            throw new \RuntimeException(
                "This payment's fee/cost allocation is still pending (amount: {$pending->amount_cents}); only a full refund of the entire pending amount is supported before allocation is resolved. Resolve the allocation first if a partial refund is needed."
            );
        }

        $receiptEntry = AccountingJournalEntry::query()
            ->where('payment_id', $payment->id)
            ->where('source_type', AccountingJournalSourceType::UnappliedFundsReceived->value)
            ->first();

        if ($receiptEntry === null) {
            // Accounting is not applicable for this firm (never
            // enabled) — recordUnappliedFundsReceived() itself returned
            // null rather than posting anything, so there is nothing to
            // reverse either; proceed with the reversal-row bookkeeping
            // only, exactly as every other method in this class treats
            // an accounting-not-applicable firm.
        } else {
            $this->reversal->reverse(
                $firm,
                $receiptEntry,
                "Refund before allocation — payment #{$payment->id}: {$reason}",
                $refundedBy,
            );
        }

        PaymentReversal::create([
            'firm_id' => $firm->id,
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'payment_plan_installment_id' => $payment->payment_plan_installment_id,
            'reversal_type' => PaymentReversalType::Refund,
            'amount_cents' => $amountCents,
            'reason' => $reason,
            'actor_firm_user_id' => $refundedBy?->id,
            'created_at' => now(),
        ]);

        $payment->update(['status' => PaymentStatus::Refunded]);

        $pending->update([
            'status' => PendingPaymentAllocationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $payment;
    }

    private function isSplitAllocated(Payment $payment): bool
    {
        // revenue_purpose IS NULL is the Phase F multi-target split
        // marker (applySplit()); a revenue_purpose-tagged row is the
        // Mixed-Invoice Revenue Allocation pass's own bucket tracking
        // for a SINGLE target and does not indicate a split payment.
        return PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('revenue_purpose')->exists();
    }

    /**
     * @return array{0:int, 1:int} [feeCents, costCents] actually
     *                             recognized against this payment,
     *                             summed from its own revenue_purpose-
     *                             tagged PaymentAllocation rows. Both
     *                             zero for a payment with no such rows
     *                             at all (accounting not applicable,
     *                             or a target-less/standalone payment)
     *                             — recordCashOut() falls back to its
     *                             own pre-existing single-leg
     *                             LegalFeeRevenue behavior in that
     *                             case, unchanged from before this
     *                             pass.
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
