<?php

declare(strict_types=1);

namespace App\Services\Pay\Contracts;

use App\Services\Pay\Data\ProviderFeeEvidence;
use App\Services\Pay\Data\ProviderPaymentRequest;
use App\Services\Pay\Data\ProviderResult;

/**
 * PaymentProviderAdapter — FirmsVault Pay Gate A3 (v1.4 §5). THE
 * provider-neutral contract between PaymentCore and any payment
 * provider. Deliberately small: exactly the operations POC #1 requires,
 * never "one method per endpoint a future provider might expose".
 *
 * WHY THE EXISTING StripeGateway WAS NOT REUSED (Gate A1/A2 finding,
 * reaffirmed here): it is a 2-method fake-only stub with no command
 * identity, no idempotency semantics, no outcome-lookup operation and
 * no unknown-outcome concept — it structurally cannot express the
 * v3.1 recovery model. It remains untouched for its existing platform/
 * checkout callers; this contract replaces it ONLY for the new
 * FirmsVault Pay execution flow.
 *
 * CONTRACT RULES every implementation (fake now, Finix in Gate B) must
 * honor:
 *
 *   - Input is ProviderPaymentRequest ONLY — never a provider-native
 *     object (v1.4 §6).
 *   - Output is ProviderResult with a CANONICAL outcome — provider
 *     native states are mapped inside the adapter (v1.4 §7/§8).
 *   - A transport-uncertain send throws
 *     ProviderCommunicationTimeoutException — the adapter must NOT
 *     guess an outcome it cannot prove.
 *   - A recognized duplicate idempotency identity returns
 *     ProviderOutcome::DuplicateRequiresLookup — never a new charge,
 *     never an error (v1.4 §19).
 *   - getPaymentOutcome()/getRefundOutcome() are AUTHORITATIVE,
 *     side-effect-free lookups against the ORIGINAL logical operation.
 *     They are safe to repeat and never create provider resources.
 *   - Wrong connection / wrong environment throw the dedicated
 *     mismatch exceptions and perform nothing (v1.4 §28/§29).
 */
interface PaymentProviderAdapter
{
    public function createCardPayment(ProviderPaymentRequest $request): ProviderResult;

    /**
     * Authoritative, repeatable, side-effect-free outcome lookup for a
     * previously submitted payment command. May return OutcomeUnknown
     * (STILL_UNKNOWN) when the provider itself cannot yet determine the
     * result — the caller must then leave the attempt unknown (§17).
     */
    public function getPaymentOutcome(ProviderPaymentRequest $request): ProviderResult;

    public function refundPayment(ProviderPaymentRequest $request): ProviderResult;

    public function getRefundOutcome(ProviderPaymentRequest $request): ProviderResult;

    /**
     * Settlement evidence for a provider resource, in provider-neutral
     * shape: gross/net magnitudes plus fee evidence lines. Gate A3 only
     * proves representability — nothing posts from this (v1.4 §38).
     *
     * @return array{gross_cents: int, net_cents: int, fees: list<ProviderFeeEvidence>, provider_metadata: array<string, mixed>}
     */
    public function getSettlementEvidence(string $providerResourceReference): array;

    /**
     * @return list<ProviderFeeEvidence>
     */
    public function getFeeEvidence(string $providerResourceReference): array;
}
