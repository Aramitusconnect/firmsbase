<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Enums\PaymentReversalType;
use App\Enums\PaymentStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use Illuminate\Support\Facades\DB;

/**
 * OperatingPaymentRefundService — Phase G. The only writer of
 * voluntary, firm-client operating refunds (payment_reversals with
 * reversal_type=refund). Distinct from TrustRefundRequestService
 * (Trust domain, client trust funds, untouched) and
 * PlatformRefundService (SaaS subscription billing, untouched).
 *
 * Never mutates prior journal history: the accounting consequence is
 * always a NEW compensating posting (via OperatingJournalRecorderService
 * ::recordCashOut(), which itself calls the existing, unmodified
 * AccountingJournalPostingService::post() — never
 * AccountingJournalReversalService::reverse(), since the original
 * fee-earned entry is correct as posted; the refund is its own,
 * separate real event, not a correction of a mistake).
 */
class OperatingPaymentRefundService
{
    public function __construct(
        private readonly PaymentApplicationService $application,
        private readonly OperatingJournalRecorderService $journal,
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

            // recordCashOut() posts a single-leg reversal to Legal Fee
            // Revenue only. That is exactly correct for a fee-only
            // payment (the overwhelming common case) but would silently
            // misstate accounts for a payment whose recognized revenue
            // includes a cost-reimbursement bucket, or a partial refund
            // whose fee-vs-cost split is not otherwise determinable — the
            // same class of ambiguity the Mixed-Invoice Revenue
            // Allocation pass refuses to guess at going forward, applied
            // here to the reversal direction. Refused with a clear error
            // rather than guessed at; out of scope for this pass.
            if ($this->hasNonFeeOnlyRevenueAllocation($payment)) {
                throw new \RuntimeException('This payment recognized cost-reimbursement revenue; refunding it requires an explicit fee/cost split, which is not yet supported.');
            }

            $alreadyRefunded = (int) PaymentReversal::query()
                ->where('payment_id', $payment->id)
                ->where('reversal_type', PaymentReversalType::Refund->value)
                ->sum('amount_cents');

            if ($alreadyRefunded + $amountCents > $payment->amount_cents) {
                throw new \RuntimeException('Total refunds cannot exceed the original payment amount.');
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

    private function isSplitAllocated(Payment $payment): bool
    {
        // revenue_purpose IS NULL is the Phase F multi-target split
        // marker (applySplit()); a revenue_purpose-tagged row is the
        // Mixed-Invoice Revenue Allocation pass's own bucket tracking
        // for a SINGLE target and does not indicate a split payment.
        return PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('revenue_purpose')->exists();
    }

    private function hasNonFeeOnlyRevenueAllocation(Payment $payment): bool
    {
        $purposes = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->whereNotNull('revenue_purpose')
            ->distinct()
            ->pluck('revenue_purpose');

        return $purposes->isNotEmpty() && (
            $purposes->count() > 1
            || $purposes->first() !== ChartOfAccountPurpose::LegalFeeRevenue
        );
    }
}
