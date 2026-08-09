<?php

namespace App\Services;

use App\Enums\PaymentRequestAmountRule;
use App\Enums\PaymentRequestEventType;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\PaymentPlanInstallment;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestEvent;
use Illuminate\Support\Facades\URL;

/**
 * PaymentRequestService — Payment Link / QR Routing phase. The ONLY
 * writer of payment_requests/payment_request_events lifecycle rows
 * (create/activate/revoke/resolve-by-link). This service is an ENTRY
 * CHANNEL orchestrator only — it never decides PaymentClassification,
 * never posts an accounting entry, and never writes a TrustLedgerEntry;
 * see PaymentRequestCheckoutService for what happens once a payer
 * actually submits a payment, which delegates every one of those
 * decisions to the existing canonical services.
 */
class PaymentRequestService
{
    private const DEFAULT_EXPIRY_DAYS = 30;

    public function create(
        Firm $firm,
        Client $client,
        PaymentRequestPurpose $purpose,
        PaymentRequestAmountRule $amountRule,
        FirmUser $createdBy,
        ?int $requestedAmountCents = null,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
        ?PaymentPlanInstallment $installment = null,
        ?\DateTimeInterface $expiresAt = null,
    ): PaymentRequest {
        if ((int) $createdBy->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('The creating user does not belong to this firm.');
        }

        if ((int) $client->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This client does not belong to this firm.');
        }

        $this->assertPurposeTargetConsistency($purpose, $invoice, $installment);
        $this->assertAmountRuleConsistency($amountRule, $requestedAmountCents, $invoice, $installment);

        return (new TenantContextService)->runWithFirmContext($firm, function () use (
            $firm, $client, $purpose, $amountRule, $createdBy, $requestedAmountCents, $matter, $invoice, $installment, $expiresAt
        ) {
            $paymentRequest = PaymentRequest::create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter?->id,
                'invoice_id' => $invoice?->id,
                'payment_plan_installment_id' => $installment?->id,
                'purpose' => $purpose,
                'amount_rule' => $amountRule,
                'requested_amount_cents' => $requestedAmountCents,
                'status' => PaymentRequestStatus::Draft,
                'expires_at' => $expiresAt,
                'created_by_firm_user_id' => $createdBy->id,
            ]);

            $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::Created, actor: $createdBy);

            return $paymentRequest;
        });
    }

    /**
     * A Draft becomes Active — the only status a public link is ever
     * payable in (PaymentRequest::isPayable()). expires_at defaults to
     * 30 days out when the creator did not supply one at create() time.
     */
    public function activate(Firm $firm, PaymentRequest $paymentRequest, FirmUser $activatedBy): PaymentRequest
    {
        if ((int) $paymentRequest->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This payment request does not belong to this firm.');
        }

        if ($paymentRequest->status !== PaymentRequestStatus::Draft) {
            throw new \RuntimeException('Only a Draft payment request can be activated.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $paymentRequest, $activatedBy) {
            $paymentRequest->update([
                'status' => PaymentRequestStatus::Active,
                'activated_at' => now(),
                'expires_at' => $paymentRequest->expires_at ?? now()->addDays(self::DEFAULT_EXPIRY_DAYS),
            ]);

            $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::Activated, actor: $activatedBy);

            return $paymentRequest->fresh();
        });
    }

    /**
     * Revocation is the DB-side control that works independently of
     * the signed URL's own cryptographic expiry — a firm can revoke a
     * link immediately even though its signature remains
     * mathematically valid until expires_at. Every page load and every
     * payment attempt re-checks PaymentRequest::isPayable() (status,
     * never merely the signature) — see PublicPaymentPage's own
     * docblock.
     */
    public function revoke(Firm $firm, PaymentRequest $paymentRequest, FirmUser $revokedBy, string $reason): PaymentRequest
    {
        if ((int) $paymentRequest->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This payment request does not belong to this firm.');
        }

        if (in_array($paymentRequest->status, [PaymentRequestStatus::Paid, PaymentRequestStatus::Revoked], true)) {
            throw new \RuntimeException('A paid or already-revoked payment request cannot be revoked.');
        }

        if (trim($reason) === '') {
            throw new \RuntimeException('A reason is required to revoke a payment request.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $paymentRequest, $revokedBy, $reason) {
            $paymentRequest->update([
                'status' => PaymentRequestStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by_firm_user_id' => $revokedBy->id,
                'revoke_reason' => $reason,
            ]);

            $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::Revoked, actor: $revokedBy, note: $reason);

            return $paymentRequest->fresh();
        });
    }

    /**
     * The one signed, expiring, tamper-proof URL a QR code/shared link
     * ever encodes. Laravel's own temporarySignedRoute() — the exact
     * mechanism FirmOwnerInvitationNotification::resetUrl() already
     * uses for password-reset links — is reused as-is, never a custom
     * signing scheme. The ONLY thing carried in the URL is the opaque
     * uuid; firm/client/matter/purpose/amount/classification are never
     * query parameters — they are read server-side from the stored
     * PaymentRequest row, so no query-string tampering can ever change
     * them (a modified signature simply fails ValidateSignature).
     */
    public function signedUrl(PaymentRequest $paymentRequest): string
    {
        $expiration = $paymentRequest->expires_at ?? now()->addDays(self::DEFAULT_EXPIRY_DAYS);

        return URL::temporarySignedRoute('public.payment-requests.show', $expiration, ['uuid' => $paymentRequest->uuid]);
    }

    /**
     * The RLS bootstrap read for the public page — see
     * TenantContextService::withPaymentRequestSelfLookupContext()'s own
     * docblock. Returns null (never throws) on a genuinely unknown
     * uuid, matching this codebase's "collapse to false, never
     * disclose why" convention for anything a public, unauthenticated
     * visitor can probe.
     */
    public function resolveByUuid(string $uuid): ?PaymentRequest
    {
        return (new TenantContextService)->withPaymentRequestSelfLookupContext(
            $uuid,
            fn () => PaymentRequest::query()->where('uuid', $uuid)->first(),
        );
    }

    /**
     * Recorded from the public side (no FirmUser actor) every time the
     * link is loaded — required by the phase's own audit-trail item.
     * Runs inside the SAME firm context the caller has already
     * established (via resolveByUuid()'s self-lookup, followed by a
     * normal runWithFirmContext() once the firm is known).
     */
    public function recordLinkAccessed(Firm $firm, PaymentRequest $paymentRequest, ?string $ipAddress): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $paymentRequest, $ipAddress) {
            $this->recordEvent($firm, $paymentRequest, PaymentRequestEventType::LinkAccessed, ipAddress: $ipAddress);
        });
    }

    /**
     * The ONLY place a payer-submitted amount is validated. Never
     * trusts the browser: FIXED ignores whatever was submitted and
     * always returns requested_amount_cents; UP_TO clamps to (0,
     * targetRemainingCents()]; CUSTOM_ALLOWED accepts any positive
     * amount. Throws on anything else — a caller must never fall back
     * to "use whatever the client sent" on any error path.
     */
    public function validatePayableAmount(PaymentRequest $paymentRequest, int $submittedAmountCents): int
    {
        return match ($paymentRequest->amount_rule) {
            PaymentRequestAmountRule::Fixed => $paymentRequest->requested_amount_cents,
            PaymentRequestAmountRule::UpTo => $this->validateUpToAmount($paymentRequest, $submittedAmountCents),
            PaymentRequestAmountRule::CustomAllowed => $this->validatePositiveAmount($submittedAmountCents),
        };
    }

    private function validateUpToAmount(PaymentRequest $paymentRequest, int $submittedAmountCents): int
    {
        $remaining = $paymentRequest->targetRemainingCents();

        if ($remaining === null) {
            throw new \RuntimeException('This payment request has no target balance to validate an UP_TO amount against.');
        }

        $amount = $this->validatePositiveAmount($submittedAmountCents);

        if ($amount > $remaining) {
            throw new \RuntimeException("The submitted amount ({$amount}) exceeds the remaining balance ({$remaining}).");
        }

        return $amount;
    }

    private function validatePositiveAmount(int $amountCents): int
    {
        if ($amountCents <= 0) {
            throw new \RuntimeException('The payment amount must be positive.');
        }

        return $amountCents;
    }

    private function assertPurposeTargetConsistency(PaymentRequestPurpose $purpose, ?Invoice $invoice, ?PaymentPlanInstallment $installment): void
    {
        if ($purpose === PaymentRequestPurpose::PaymentPlanInstallment && $installment === null) {
            throw new \InvalidArgumentException('A payment_plan_installment purpose requires a target installment.');
        }

        if ($installment !== null && $invoice !== null) {
            throw new \InvalidArgumentException('A payment request may target an invoice or an installment, never both.');
        }
    }

    private function assertAmountRuleConsistency(
        PaymentRequestAmountRule $amountRule,
        ?int $requestedAmountCents,
        ?Invoice $invoice,
        ?PaymentPlanInstallment $installment,
    ): void {
        if ($amountRule === PaymentRequestAmountRule::Fixed && ($requestedAmountCents === null || $requestedAmountCents <= 0)) {
            throw new \InvalidArgumentException('A fixed amount rule requires a positive requested_amount_cents.');
        }

        if ($amountRule === PaymentRequestAmountRule::UpTo && $invoice === null && $installment === null) {
            throw new \InvalidArgumentException('An up_to amount rule requires an invoice or installment target to compute the remaining balance against.');
        }
    }

    /**
     * @internal shared by every write above — never called directly by
     * anything outside this service.
     */
    private function recordEvent(
        Firm $firm,
        PaymentRequest $paymentRequest,
        PaymentRequestEventType $eventType,
        ?FirmUser $actor = null,
        ?int $amountCents = null,
        ?string $providerTransactionId = null,
        ?array $providerResponse = null,
        ?string $note = null,
        ?string $ipAddress = null,
    ): PaymentRequestEvent {
        return PaymentRequestEvent::create([
            'firm_id' => $firm->id,
            'payment_request_id' => $paymentRequest->id,
            'event_type' => $eventType,
            'actor_firm_user_id' => $actor?->id,
            'amount_cents' => $amountCents,
            'provider_transaction_id' => $providerTransactionId,
            'provider_response_json' => $providerResponse,
            'note' => $note,
            'ip_address' => $ipAddress,
        ]);
    }
}
