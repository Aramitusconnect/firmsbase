<?php

namespace App\Services;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\PaymentBlockedException;
use App\Models\Firm;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use App\Models\TrustLedger;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\Facades\DB;

/**
 * PaymentRequestCheckoutService — Payment Link / QR Routing phase. The
 * ONLY place a payer's "Pay now" submission is processed. This is an
 * ENTRY CHANNEL orchestrator, never a second ledger/payment system:
 * every real decision is delegated to the existing canonical services
 * exactly as the phase's own "do not duplicate" rule requires —
 *
 *   provider confirms payment (StripeGateway — reused as-is, never a
 *   second gateway abstraction)
 *     -> PaymentClassificationService (via ManualPaymentService for
 *        Operating-classified purposes; via TrustDepositService's own
 *        request/approve/post workflow for Trust deposits)
 *     -> PaymentApplicationService / OperatingJournalRecorderService
 *        (both invoked internally by ManualPaymentService — never
 *        duplicated here)
 *
 * The provider never decides Trust vs Operating, earned vs unearned,
 * invoice allocation, or ledger postings — it only collects and
 * confirms; FirmsVault decides everything downstream of that using the
 * exact same services staff-recorded manual payments already use.
 *
 * A gateway-confirmed payment that cannot be routed into the canonical
 * domain (a downstream service throws — e.g. no trust ledger exists
 * for this client, or the journal is atomically blocked per the
 * Accounting Integrity Hardening Pass) NEVER disappears silently: the
 * request is left/moved to PendingReview with a recorded Failed event
 * carrying the reason, for a human to resolve through the normal
 * service layer — never auto-retried, never auto-corrected here.
 */
class PaymentRequestCheckoutService
{
    public function __construct(
        private readonly PaymentRequestService $paymentRequests,
        private readonly ManualPaymentService $manualPayments,
        private readonly TrustDepositService $trustDeposits,
        private readonly StripeGateway $gateway,
    ) {}

    /**
     * @return PaymentRequest the fresh, post-attempt row — check
     *                        ->status to learn the outcome; this method
     *                        never throws for a provider decline (that
     *                        is a normal, expected outcome recorded as
     *                        Failed), only for a genuine programming/
     *                        state error (wrong firm, not payable, bad
     *                        amount).
     */
    public function submitPayment(PaymentRequest $paymentRequest, int $submittedAmountCents, ?string $ipAddress = null): PaymentRequest
    {
        $firm = $paymentRequest->firm;

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $paymentRequest, $submittedAmountCents, $ipAddress) {
            return DB::transaction(function () use ($firm, $paymentRequest, $submittedAmountCents, $ipAddress) {
                // Row lock — the concurrency-safe guard against a payer
                // double-clicking "Pay" or a replayed submission racing
                // a genuine first attempt. Whichever request wins the
                // lock re-checks isPayable() itself; the loser sees the
                // now-non-Active status and is rejected below.
                $locked = PaymentRequest::query()->whereKey($paymentRequest->id)->lockForUpdate()->firstOrFail();

                if (! $locked->isPayable()) {
                    throw new \RuntimeException('This payment request is no longer payable.');
                }

                $amountCents = $this->paymentRequests->validatePayableAmount($locked, $submittedAmountCents);

                $this->recordEvent($firm, $locked, PaymentRequestEventType::PaymentAttempted, amountCents: $amountCents, ipAddress: $ipAddress);

                $providerResponse = $this->gateway->createPaymentIntent(
                    $amountCents,
                    $locked->currency,
                    ['payment_request_uuid' => $locked->uuid, 'firm_id' => $firm->id],
                );

                if (($providerResponse['status'] ?? null) !== 'succeeded') {
                    $this->recordEvent(
                        $firm, $locked, PaymentRequestEventType::ProviderFailed,
                        amountCents: $amountCents,
                        providerTransactionId: $providerResponse['id'] ?? null,
                        providerResponse: $this->redactProviderResponse($providerResponse),
                    );

                    $locked->update([
                        'status' => PaymentRequestStatus::Failed,
                        'failure_reason' => $providerResponse['failure_reason'] ?? 'Provider declined the payment.',
                    ]);

                    return $locked->fresh();
                }

                $providerTransactionId = $providerResponse['id'];

                $this->recordEvent(
                    $firm, $locked, PaymentRequestEventType::ProviderConfirmed,
                    amountCents: $amountCents,
                    providerTransactionId: $providerTransactionId,
                    providerResponse: $this->redactProviderResponse($providerResponse),
                );

                return $this->routeConfirmedPayment($firm, $locked, $amountCents, $providerTransactionId);
            });
        });
    }

    /**
     * Idempotency, by construction: every downstream write this method
     * triggers is keyed off "payment_request:{uuid}:{provider_transaction_id}"
     * — ManualPaymentService::submit()'s own idempotency_key mechanism
     * means a retried confirmation (e.g. a future real webhook redelivery)
     * for the SAME provider transaction against the SAME request
     * returns the original Payment rather than creating a duplicate.
     * The payment_requests.provider_transaction_id unique-per-firm
     * index is the second, independent line of defense — a provider
     * transaction id can never be attributed to two different requests.
     */
    private function routeConfirmedPayment(
        Firm $firm,
        PaymentRequest $paymentRequest,
        int $amountCents,
        string $providerTransactionId,
    ): PaymentRequest {
        $idempotencyKey = "payment_request:{$paymentRequest->uuid}:{$providerTransactionId}";

        try {
            if ($paymentRequest->purpose === PaymentRequestPurpose::TrustDeposit) {
                return $this->routeTrustDeposit($firm, $paymentRequest, $amountCents, $providerTransactionId);
            }

            return $this->routeOperatingPayment($firm, $paymentRequest, $amountCents, $providerTransactionId, $idempotencyKey);
        } catch (PaymentBlockedException $e) {
            $this->markPendingReview($firm, $paymentRequest, "Payment was classified Blocked: {$e->getMessage()}");

            return $paymentRequest->fresh();
        } catch (\Throwable $e) {
            $this->markPendingReview($firm, $paymentRequest, 'Confirmed by the provider but could not be routed into the accounting domain: '.$e->getMessage());

            return $paymentRequest->fresh();
        }
    }

    /**
     * Earned fee, filing/cost reimbursement, and payment-plan
     * installment purposes all resolve to PaymentClassification::Operating
     * via the SAME ManualPaymentService::submit() every staff-recorded
     * manual payment already uses — internally: canonical Payment row
     * -> PaymentClassificationService -> PaymentApplicationService
     * -> OperatingJournalRecorderService, atomically, per the
     * Accounting Integrity Hardening Pass's own post-or-block policy
     * (no journal failure is ever silently ignored — a failure here
     * throws and is caught by routeConfirmedPayment() above, landing
     * the request in PendingReview rather than a false "Paid").
     *
     * filing_cost_reimbursement changes the PAYER-FACING wording
     * (PaymentRequestPurpose::payerFacingDescription()); the actual
     * posted accounting entry depends on the target invoice's own
     * line composition — see OperatingJournalRecorderService::
     * recordInvoicePaymentApplied()/resolveFeeCostSplitForFullyPaidInvoice()'s
     * own docblocks (Payment-Channel Safety Hardening pass, item 4/5).
     * A mixed invoice (fee lines + ReimbursableExpense lines) paid off
     * in full by its own first payment now correctly splits between
     * LegalFeeRevenue and CostReimbursementRevenue — no longer silently
     * misclassified as 100% fee revenue. A mixed invoice funded by more
     * than one payment still posts entirely to LegalFeeRevenue — that
     * allocation policy remains genuinely undefined by this codebase
     * (documented, not silently guessed); see the phase's final report.
     */
    private function routeOperatingPayment(
        Firm $firm,
        PaymentRequest $paymentRequest,
        int $amountCents,
        string $providerTransactionId,
        string $idempotencyKey,
    ): PaymentRequest {
        $payment = $this->manualPayments->submit(
            $firm,
            $paymentRequest->client,
            $amountCents,
            ManualPaymentMethod::PaymentLink,
            PaymentClassification::OperatingPayment,
            $idempotencyKey,
            matter: $paymentRequest->matter,
            invoice: $paymentRequest->invoice,
            installment: $paymentRequest->paymentPlanInstallment,
            recordedBy: $paymentRequest->createdBy?->user,
            externalReference: "payment_request:{$paymentRequest->uuid}",
            methodReference: $providerTransactionId,
            notes: 'Collected via payment request link/QR code.',
        );

        $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::ClassificationDecided, note: $payment->payment_classification->value);
        $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::PostedToAccounting);

        $paymentRequest->update([
            'status' => PaymentRequestStatus::Paid,
            'provider_transaction_id' => $providerTransactionId,
            'paid_amount_cents' => $amountCents,
            'paid_at' => now(),
            'payment_id' => $payment->id,
        ]);

        return $paymentRequest->fresh();
    }

    /**
     * Trust deposits NEVER skip the existing dual-control approval
     * workflow — a confirmed provider payment only ever creates a
     * DepositRequested TrustApprovalEvent (via
     * TrustDepositService::requestDeposit(), using the firm user who
     * created THIS payment request as the requester), landing in the
     * exact same Firm review queue a manually-initiated deposit request
     * would. It is never auto-approved, never auto-posted as a
     * TrustLedgerEntry, and never auto-recognized as revenue —
     * PaymentRequestStatus::PendingReview is the correct terminal state
     * here (money confirmed, deposit request filed, awaiting a
     * DIFFERENT firm user's approval + TrustDepositService::post()).
     */
    private function routeTrustDeposit(
        Firm $firm,
        PaymentRequest $paymentRequest,
        int $amountCents,
        string $providerTransactionId,
    ): PaymentRequest {
        $ledger = TrustLedger::query()
            ->where('firm_id', $firm->id)
            ->where('client_id', $paymentRequest->client_id)
            ->first();

        if ($ledger === null) {
            $this->markPendingReview($firm, $paymentRequest, 'Confirmed by the provider, but this client has no trust ledger to request a deposit against.');

            $paymentRequest->update(['provider_transaction_id' => $providerTransactionId, 'paid_amount_cents' => $amountCents, 'paid_at' => now()]);

            return $paymentRequest->fresh();
        }

        $depositRequestedEvent = $this->trustDeposits->requestDeposit(
            $firm,
            $ledger,
            $paymentRequest->createdBy,
            $amountCents,
            $paymentRequest->matter,
        );

        $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::TrustDepositRequested, amountCents: $amountCents, note: (string) $depositRequestedEvent->id);

        $paymentRequest->update([
            'status' => PaymentRequestStatus::PendingReview,
            'provider_transaction_id' => $providerTransactionId,
            'paid_amount_cents' => $amountCents,
            'paid_at' => now(),
        ]);

        return $paymentRequest->fresh();
    }

    private function markPendingReview(Firm $firm, PaymentRequest $paymentRequest, string $reason): void
    {
        $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::Failed, note: $reason);

        $paymentRequest->update([
            'status' => PaymentRequestStatus::PendingReview,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * The provider's raw response is never persisted as-is —
     * FakeStripeGateway's own shape carries no secret today, but a real
     * Stripe response would (client_secret, raw card metadata); this
     * allowlist is the durable guard against that ever leaking into
     * provider_response_json regardless of what a future real
     * connector's response shape adds.
     */
    private function redactProviderResponse(array $response): array
    {
        return array_intersect_key($response, array_flip(['status', 'id', 'failure_reason']));
    }

    private function recordEvent(
        Firm $firm,
        PaymentRequest $paymentRequest,
        PaymentRequestEventType $eventType,
        ?int $amountCents = null,
        ?string $providerTransactionId = null,
        ?array $providerResponse = null,
        ?string $note = null,
        ?string $ipAddress = null,
    ): void {
        PaymentRequestEvent::create([
            'firm_id' => $firm->id,
            'payment_request_id' => $paymentRequest->id,
            'event_type' => $eventType,
            'amount_cents' => $amountCents,
            'provider_transaction_id' => $providerTransactionId,
            'provider_response_json' => $providerResponse,
            'note' => $note,
            'ip_address' => $ipAddress,
        ]);
    }
}
