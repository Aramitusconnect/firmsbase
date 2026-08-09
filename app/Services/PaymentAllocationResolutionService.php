<?php

namespace App\Services;

use App\Enums\PendingPaymentAllocationStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PendingPaymentAllocation;
use Illuminate\Support\Facades\DB;

/**
 * PaymentAllocationResolutionService — Mixed-Invoice Revenue
 * Allocation pass, item 3/8/9. The ONLY place a PendingPaymentAllocation
 * is resolved. An authorized Billing Staff/Firm Owner/Attorney
 * (PaymentAccessPolicyService::canResolvePaymentAllocation() — the
 * SAME ceiling as recording a payment, never a new role architecture)
 * supplies an explicit fee/cost split; this service validates it,
 * applies the payment to its target (PaymentApplicationService),
 * posts the journal entry (OperatingJournalRecorderService), records
 * the revenue-bucket PaymentAllocation rows, and marks the pending row
 * Resolved — all inside one transaction, so the resolution either
 * lands completely or not at all.
 *
 * Never re-opens or re-resolves an already-Resolved row (item 9 —
 * immutability/correction): once posted, a correction goes through the
 * existing refund/reversal architecture
 * (OperatingPaymentRefundService/AccountingJournalReversalService),
 * never by mutating this row's own resolution a second time.
 */
class PaymentAllocationResolutionService
{
    public function __construct(
        private readonly PaymentApplicationService $application,
        private readonly OperatingJournalRecorderService $journal,
        private readonly PaymentAccessPolicyService $accessPolicy,
    ) {}

    public function resolve(
        Firm $firm,
        PendingPaymentAllocation $pending,
        FirmUser $resolvedBy,
        int $feeCents,
        int $costCents,
        ?string $notes = null,
    ): PendingPaymentAllocation {
        if (! $this->accessPolicy->canResolvePaymentAllocation($resolvedBy->role)) {
            throw new \RuntimeException('This user is not authorized to resolve a payment allocation.');
        }

        if ((int) $pending->firm_id !== (int) $firm->id || (int) $resolvedBy->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This pending allocation does not belong to this firm.');
        }

        if ($feeCents < 0 || $costCents < 0) {
            throw new \InvalidArgumentException('Fee and cost amounts must be non-negative.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $pending, $resolvedBy, $feeCents, $costCents, $notes) {
            return DB::transaction(function () use ($firm, $pending, $resolvedBy, $feeCents, $costCents, $notes) {
                // Row lock — the concurrency-safe guard against two
                // firm users resolving the same pending allocation at
                // once.
                $locked = PendingPaymentAllocation::query()->whereKey($pending->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isPending()) {
                    throw new \RuntimeException('This payment allocation has already been resolved.');
                }

                if ($feeCents + $costCents !== $locked->amount_cents) {
                    throw new \InvalidArgumentException(
                        "Fee ({$feeCents}) plus cost ({$costCents}) must exactly equal the pending amount ({$locked->amount_cents}) — a resolution can never leave part of the payment unaccounted for."
                    );
                }

                $invoice = $locked->invoice()->firstOrFail();
                $payment = $locked->payment()->firstOrFail();
                $installment = $locked->paymentPlanInstallment;

                // Re-validate against CURRENT remaining balances — other
                // payments may have landed since this row was created.
                // Never clamped or silently reduced: an over-allocation
                // is rejected outright, same as the automatic
                // purpose-constrained path.
                $remaining = $this->application->invoiceRevenueRemaining($invoice);

                if ($feeCents > $remaining['fee_remaining_cents']) {
                    throw new \RuntimeException("Fee amount ({$feeCents}) exceeds the invoice's current remaining legal-fee balance ({$remaining['fee_remaining_cents']}).");
                }

                if ($costCents > $remaining['cost_remaining_cents']) {
                    throw new \RuntimeException("Cost amount ({$costCents}) exceeds the invoice's current remaining cost-reimbursement balance ({$remaining['cost_remaining_cents']}).");
                }

                if ($installment !== null) {
                    $this->application->applyToInstallment($payment, $installment);
                    $this->journal->recordInstallmentPaymentApplied($firm, $payment->fresh(), $installment->fresh(), $feeCents, $costCents);
                    $this->application->recordRevenueAllocation($firm, $payment->fresh(), $invoice, $feeCents, $costCents, installment: $installment->fresh());
                } else {
                    $this->application->applyToInvoice($payment, $invoice);
                    $this->journal->recordInvoicePaymentApplied($firm, $payment->fresh(), $invoice->fresh(), $feeCents, $costCents);
                    $this->application->recordRevenueAllocation($firm, $payment->fresh(), $invoice->fresh(), $feeCents, $costCents);
                }

                $locked->update([
                    'status' => PendingPaymentAllocationStatus::Resolved,
                    'resolved_by_firm_user_id' => $resolvedBy->id,
                    'resolved_at' => now(),
                    'resolved_fee_cents' => $feeCents,
                    'resolved_cost_cents' => $costCents,
                    'resolution_notes' => $notes,
                ]);

                return $locked->fresh();
            });
        });
    }
}
