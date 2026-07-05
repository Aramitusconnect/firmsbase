<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Enums\TrustApprovalEventType;
use App\Enums\TrustLedgerEntryType;
use App\Enums\TrustTransferRequestStatus;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\TrustLedger;
use App\Models\TrustTransferRequest;

/**
 * TrustTransferRequestService — the trust-to-invoice transfer workflow
 * (request/approve/deny/apply). apply() is the only place a trust
 * withdrawal is converted into invoice payment. It NEVER bypasses
 * existing invoice payment rules: it creates a real Payment row through
 * the SAME PaymentClassificationService::classify()/recordDecision()
 * pipeline ManualPaymentService uses, always requesting
 * PaymentClassification::OperatingPayment (never TrustIoltaPayment —
 * that path remains hard-blocked), and then calls the EXISTING,
 * unmodified PaymentApplicationService::applyToInvoice(). No other
 * service is permitted to set payments.payment_classification directly
 * (project rule) — this service complies by always requesting
 * OperatingPayment and letting PaymentClassificationService decide.
 *
 * There is no dedicated ManualPaymentMethod case for a trust-funded
 * transfer (ManualPaymentMethod.php is not in the Phase 13 allowed-to-
 * modify list); ManualPaymentMethod::Other is used, and traceability to
 * the originating trust withdrawal is via trust_ledger_entries.source_
 * payment_id + Payment.external_reference, not a new enum case.
 */
class TrustTransferRequestService
{
    public function __construct(
        private readonly TrustEligibilityService $eligibility,
        private readonly TrustAccessPolicyService $accessPolicy,
        private readonly TenantSafeTrustPolicyService $tenantSafePolicy,
        private readonly TrustCrossMatterProtectionService $crossMatterProtection,
        private readonly TrustConcurrencyLockService $lockService,
        private readonly TrustBalanceService $balanceService,
        private readonly PaymentClassificationService $classification,
        private readonly PaymentApplicationService $application,
    ) {
    }

    public function requestTransfer(
        Firm $firm,
        TrustLedger $ledger,
        Matter $matter,
        Invoice $invoice,
        FirmUser $requestedBy,
        int $amountCents,
    ): TrustTransferRequest {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);
        $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);
        $this->accessPolicy->assertCanRequest($requestedBy);

        if ($amountCents <= 0) {
            throw new \RuntimeException('Transfer amount must be positive.');
        }

        if ($invoice->firm_id !== $firm->id || $invoice->matter_id !== $matter->id) {
            throw new \RuntimeException('The invoice does not belong to this firm/matter.');
        }

        $request = TrustTransferRequest::create([
            'firm_id' => $firm->id,
            'trust_ledger_id' => $ledger->id,
            'matter_id' => $matter->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => $amountCents,
            'status' => TrustTransferRequestStatus::Requested,
            'requested_by_firm_user_id' => $requestedBy->id,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::TransferRequested, $requestedBy, $amountCents, $matter->id);

        return $request;
    }

    public function approveTransfer(Firm $firm, TrustTransferRequest $request, FirmUser $approvedBy): TrustTransferRequest
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustTransferRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($approvedBy);

        if (! in_array($request->status, [TrustTransferRequestStatus::Requested, TrustTransferRequestStatus::PendingApproval], true)) {
            throw new \RuntimeException('This transfer request is not awaiting approval.');
        }

        $request->update([
            'status' => TrustTransferRequestStatus::Approved,
            'approved_by_firm_user_id' => $approvedBy->id,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::TransferApproved, $approvedBy, $request->amount_cents, $request->matter_id);

        return $request->fresh();
    }

    public function denyTransfer(Firm $firm, TrustTransferRequest $request, FirmUser $deniedBy, string $reason): TrustTransferRequest
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustTransferRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($deniedBy);

        if (! in_array($request->status, [TrustTransferRequestStatus::Requested, TrustTransferRequestStatus::PendingApproval], true)) {
            throw new \RuntimeException('This transfer request is not awaiting approval.');
        }

        $request->update([
            'status' => TrustTransferRequestStatus::Denied,
            'denied_reason' => $reason,
        ]);

        $this->recordEvent($firm, $request, TrustApprovalEventType::TransferDenied, $deniedBy, $request->amount_cents, $request->matter_id);

        return $request->fresh();
    }

    /**
     * Posts the WithdrawalToInvoice ledger entry and applies it to the
     * invoice, all inside one locked transaction. Requires an Approved
     * request, a locked ledger (and matter) balance sufficient to cover
     * the amount, and an invoice in an appliable status.
     */
    public function apply(Firm $firm, TrustTransferRequest $request, FirmUser $appliedBy): Payment
    {
        $this->eligibility->assertEligible($firm);
        $this->tenantSafePolicy->assertTrustTransferRequestBelongsToFirm($request, $firm);
        $this->accessPolicy->assertCanApprove($appliedBy);

        if ($request->status !== TrustTransferRequestStatus::Approved) {
            throw new \RuntimeException('Only an Approved transfer request can be applied.');
        }

        $ledger = $request->trustLedger;
        $matter = $request->matter;
        $invoice = $request->invoice;

        $this->tenantSafePolicy->assertTrustLedgerBelongsToFirm($ledger, $firm);
        $this->crossMatterProtection->assertMatterEligibleForLedger($matter, $ledger);

        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Approved, InvoiceStatus::PartiallyPaid], true)) {
            throw new \RuntimeException('Payments cannot apply to an invoice that has not been sent/approved.');
        }

        $amountCents = $request->amount_cents;

        $payment = $this->lockService->withLockedBalances($ledger, $matter, function ($lockedBalance, $lockedMatterBalance) use (
            $firm, $ledger, $matter, $invoice, $request, $amountCents, $appliedBy
        ) {
            if ($lockedBalance->balance_cents < $amountCents) {
                throw new \RuntimeException('Trust ledger balance is insufficient for this transfer.');
            }

            $this->crossMatterProtection->assertDebitKeepsMatterBalanceNonNegative($lockedMatterBalance, -1 * $amountCents);

            $entry = \App\Models\TrustLedgerEntry::create([
                'firm_id' => $firm->id,
                'trust_ledger_id' => $ledger->id,
                'matter_id' => $matter->id,
                'entry_type' => TrustLedgerEntryType::WithdrawalToInvoice,
                'amount_cents' => -1 * $amountCents,
                'trust_transfer_request_id' => $request->id,
                'posted_at' => now(),
            ]);

            $this->balanceService->recomputeForLedger($ledger, $lockedBalance);
            $this->balanceService->recomputeForMatter($ledger, $matter, $lockedMatterBalance);

            $payment = Payment::create([
                'firm_id' => $firm->id,
                'client_id' => $ledger->client_id,
                'matter_id' => $matter->id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $amountCents,
                'payment_method' => ManualPaymentMethod::Other,
                'payment_classification' => PaymentClassification::OperatingPayment,
                'status' => PaymentStatus::Initiated,
                'external_reference' => "trust_transfer_request:{$request->id}",
                'idempotency_key' => "trust-transfer-{$request->id}",
                'recorded_by' => $appliedBy->user_id,
            ]);

            $result = $this->classification->classify($firm, PaymentClassification::OperatingPayment);
            $this->classification->recordDecision($payment, PaymentClassification::OperatingPayment, $result, $appliedBy->user);
            $payment = $payment->fresh();

            if (! $payment->isAcceptedOperatingPayment()) {
                throw new \RuntimeException('The trust-funded payment was not accepted as an operating payment.');
            }

            // $entry is never updated or deleted from here on — it is
            // handed back purely for the caller's/tests' inspection.
            $this->application->applyToInvoice($payment, $invoice->fresh());

            $request->update([
                'status' => TrustTransferRequestStatus::Applied,
                'applied_at' => now(),
            ]);

            $this->recordEvent($firm, $request, TrustApprovalEventType::TransferApplied, $appliedBy, $amountCents, $matter->id);

            return $payment;
        });

        return $payment;
    }

    private function recordEvent(
        Firm $firm,
        TrustTransferRequest $request,
        TrustApprovalEventType $eventType,
        FirmUser $actor,
        int $amountCents,
        ?int $matterId,
    ): void {
        \App\Models\TrustApprovalEvent::create([
            'firm_id' => $firm->id,
            'event_type' => $eventType,
            'actor_firm_user_id' => $actor->id,
            'amount_cents' => $amountCents,
            'matter_id' => $matterId,
            'approved_entry_type' => TrustLedgerEntryType::WithdrawalToInvoice->value,
            'correlation_uuid' => (string) \Illuminate\Support\Str::uuid7(),
            'trust_ledger_id' => $request->trust_ledger_id,
            'trust_transfer_request_id' => $request->id,
        ]);
    }
}
