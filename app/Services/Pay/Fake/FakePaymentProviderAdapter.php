<?php

declare(strict_types=1);

namespace App\Services\Pay\Fake;

use App\Enums\ProviderFeeDirection;
use App\Enums\ProviderOutcome;
use App\Exceptions\Pay\ProviderCommunicationTimeoutException;
use App\Exceptions\Pay\ProviderConnectionMismatchException;
use App\Exceptions\Pay\ProviderEnvironmentMismatchException;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\Data\ProviderFeeEvidence;
use App\Services\Pay\Data\ProviderPaymentRequest;
use App\Services\Pay\Data\ProviderResult;

/**
 * FakePaymentProviderAdapter — FirmsVault Pay Gate A3 (v1.4 §9/§10).
 * The deterministic provider implementation Gate A certification runs
 * against. NO network calls, NO randomness, NO wall-clock dependence.
 *
 * ============================================================
 * SCENARIO SELECTION — deterministic, through the REAL payload
 * ============================================================
 * The scenario is chosen by the payment-method token (payments) or the
 * refund reason (refunds) carried in the command's own canonical
 * payload — the same field a real integration would use for a
 * provider-issued token. No global mutable test state and no
 * out-of-band scripting decide behavior; the durable command IS the
 * scenario (mirrors FakeStripeGateway's determinism, upgraded to flow
 * through the real command payload).
 *
 *   fake:success               -> SUCCEEDED
 *   fake:decline               -> DECLINED (resource recorded, declined)
 *   fake:fail                  -> FAILED definitively
 *   fake:timeout-success       -> provider PROCESSES the payment, then
 *                                 the caller experiences a timeout;
 *                                 later lookup returns SUCCEEDED
 *   fake:timeout-fail          -> provider REFUSES, caller times out;
 *                                 later lookup returns FAILED
 *   fake:timeout-unknown       -> caller times out; lookup itself
 *                                 cannot determine the result yet
 *                                 (STILL_UNKNOWN, v1.4 §17)
 *   fake:duplicate-lookup      -> provider recognizes the idempotency
 *                                 identity as already received and
 *                                 answers DUPLICATE_REQUIRES_LOOKUP
 *                                 (v1.4 §19); the underlying resource
 *                                 succeeded
 *   fake:connection-mismatch   -> ProviderConnectionMismatchException
 *   fake:environment-mismatch  -> ProviderEnvironmentMismatchException
 *
 * ============================================================
 * BEHAVIORAL REALISM (v1.4 §10)
 * ============================================================
 * Request DISPATCH and economic OUTCOME are separate facts. For every
 * timeout scenario the fake records its side of the transaction FIRST
 * (the provider did or did not process the money), and only then
 * throws the timeout — exactly the window in which a real processor
 * has acted but the caller cannot know it. getPaymentOutcome() then
 * answers from that recorded state, so "UNKNOWN → later SUCCEEDED
 * without a second charge" is a real behavior, not a canned boolean.
 *
 * At-least-once realism: a SECOND createCardPayment() for a logical
 * operation the provider already holds returns
 * DUPLICATE_REQUIRES_LOOKUP rather than charging again — the
 * provider-side idempotency a real processor provides.
 *
 * This class is TEST INFRASTRUCTURE (v1.4 §48): no onboarding, no
 * hosted checkout, no ACH, no disputes, no pricing engine.
 */
class FakePaymentProviderAdapter implements PaymentProviderAdapter
{
    public const ENVIRONMENT = 'sandbox';

    /**
     * The provider-side ledger: logical_operation_key => resource state.
     *
     * @var array<string, array{ref: string, eventual: ProviderOutcome, amount: int, currency: string, firm_integration_id: ?int, environment: string, kind: string}>
     */
    private array $resources = [];

    /** @var array<string, string> resource ref => logical_operation_key */
    private array $refIndex = [];

    private int $sequence = 0;

    /** Call counters — let tests prove the adapter was NEVER reached. */
    public int $paymentCalls = 0;

    public int $refundCalls = 0;

    public int $lookupCalls = 0;

    public function createCardPayment(ProviderPaymentRequest $request): ProviderResult
    {
        $this->paymentCalls++;

        return $this->create($request, $request->methodToken ?? 'fake:success', 'payment', 'fpr');
    }

    public function refundPayment(ProviderPaymentRequest $request): ProviderResult
    {
        $this->refundCalls++;

        return $this->create($request, $request->methodToken ?? 'fake:success', 'refund', 'frr');
    }

    public function getPaymentOutcome(ProviderPaymentRequest $request): ProviderResult
    {
        return $this->lookup($request);
    }

    public function getRefundOutcome(ProviderPaymentRequest $request): ProviderResult
    {
        return $this->lookup($request);
    }

    /**
     * @return array{gross_cents: int, net_cents: int, fees: list<ProviderFeeEvidence>, provider_metadata: array<string, mixed>}
     */
    public function getSettlementEvidence(string $providerResourceReference): array
    {
        $key = $this->refIndex[$providerResourceReference] ?? null;
        $gross = $key === null ? 0 : $this->resources[$key]['amount'];
        $fees = $this->getFeeEvidence($providerResourceReference);

        $net = $gross;
        foreach ($fees as $fee) {
            $net += $fee->direction === ProviderFeeDirection::Credit ? $fee->amountCents : -$fee->amountCents;
        }

        return [
            'gross_cents' => $gross,
            'net_cents' => $net,
            'fees' => $fees,
            'provider_metadata' => ['fake' => true, 'resource' => $providerResourceReference],
        ];
    }

    /**
     * Deterministic fee evidence: one processing DEBIT, one promotional
     * CREDIT with no category (UNKNOWN permitted, v1.4 §36).
     *
     * @return list<ProviderFeeEvidence>
     */
    public function getFeeEvidence(string $providerResourceReference): array
    {
        return [
            new ProviderFeeEvidence(350, ProviderFeeDirection::Debit, 'processing', ['fake_line' => 'fee_1']),
            new ProviderFeeEvidence(50, ProviderFeeDirection::Credit, null, ['fake_line' => 'fee_2']),
        ];
    }

    /** Test hook: does the provider hold a resource for this logical key? */
    public function hasResourceFor(string $logicalOperationKey): bool
    {
        return isset($this->resources[$logicalOperationKey]);
    }

    public function resourceReferenceFor(string $logicalOperationKey): ?string
    {
        return $this->resources[$logicalOperationKey]['ref'] ?? null;
    }

    // ------------------------------------------------------------------

    private function create(ProviderPaymentRequest $request, string $token, string $kind, string $refPrefix): ProviderResult
    {
        if ($token === 'fake:connection-mismatch') {
            throw new ProviderConnectionMismatchException(
                'Fake provider: the referenced resource belongs to a different provider account than this request.'
            );
        }

        if ($token === 'fake:environment-mismatch') {
            throw new ProviderEnvironmentMismatchException(
                'Fake provider: sandbox resource presented through a mismatched environment context.'
            );
        }

        if ($request->environment !== self::ENVIRONMENT) {
            throw new ProviderEnvironmentMismatchException(
                'Fake provider: request environment ['.$request->environment.'] does not match ['.self::ENVIRONMENT.'].'
            );
        }

        // Provider-side idempotency (v1.4 §19): a logical operation the
        // provider already holds is NEVER executed twice.
        if (isset($this->resources[$request->logicalOperationKey])) {
            return $this->result($request, null, ProviderOutcome::DuplicateRequiresLookup, ['duplicate' => true]);
        }

        [$eventual, $timeout] = match ($token) {
            'fake:success' => [ProviderOutcome::Succeeded, false],
            'fake:decline' => [ProviderOutcome::Declined, false],
            'fake:fail' => [ProviderOutcome::Failed, false],
            'fake:timeout-success' => [ProviderOutcome::Succeeded, true],
            'fake:timeout-fail' => [ProviderOutcome::Failed, true],
            'fake:timeout-unknown' => [ProviderOutcome::OutcomeUnknown, true],
            'fake:duplicate-lookup' => [ProviderOutcome::Succeeded, false],
            default => throw new \InvalidArgumentException('Unknown fake provider scenario token ['.$token.'].'),
        };

        // The provider records ITS side of the transaction first —
        // dispatch and outcome are separate facts (v1.4 §10).
        $ref = $refPrefix.'_'.str_pad((string) ++$this->sequence, 6, '0', STR_PAD_LEFT);
        $this->resources[$request->logicalOperationKey] = [
            'ref' => $ref,
            'eventual' => $eventual,
            'amount' => $request->amountCents,
            'currency' => $request->currency,
            'firm_integration_id' => $request->firmIntegrationId,
            'environment' => self::ENVIRONMENT,
            'kind' => $kind,
        ];
        $this->refIndex[$ref] = $request->logicalOperationKey;

        if ($timeout) {
            throw new ProviderCommunicationTimeoutException(
                'Fake provider: simulated transport timeout after the provider may have processed the command.'
            );
        }

        if ($token === 'fake:duplicate-lookup') {
            // The provider processed it, but ANSWERS as a duplicate —
            // forcing the executor down the lookup path (v1.4 §19).
            return $this->result($request, null, ProviderOutcome::DuplicateRequiresLookup, ['duplicate' => true]);
        }

        return $this->result($request, $ref, $eventual, ['scenario' => $token]);
    }

    private function lookup(ProviderPaymentRequest $request): ProviderResult
    {
        $this->lookupCalls++;

        $resource = $this->resources[$request->logicalOperationKey] ?? null;

        if ($resource === null) {
            // The provider never received the command at all — positive
            // knowledge that no money moved.
            return $this->result($request, null, ProviderOutcome::Failed, ['lookup' => 'no_such_operation']);
        }

        if ($resource['firm_integration_id'] !== null
            && $request->firmIntegrationId !== null
            && $resource['firm_integration_id'] !== $request->firmIntegrationId) {
            throw new ProviderConnectionMismatchException(
                'Fake provider: resource is owned by a different provider account than the requesting one.'
            );
        }

        if ($request->environment !== $resource['environment']) {
            throw new ProviderEnvironmentMismatchException(
                'Fake provider: environment context does not match the resource environment.'
            );
        }

        $outcome = $resource['eventual'];

        // STILL_UNKNOWN (v1.4 §17): the provider itself cannot yet say.
        $ref = $outcome === ProviderOutcome::OutcomeUnknown ? null : $resource['ref'];

        return $this->result($request, $ref, $outcome, ['lookup' => true]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function result(ProviderPaymentRequest $request, ?string $ref, ProviderOutcome $outcome, array $metadata): ProviderResult
    {
        return new ProviderResult(
            providerCommandUuid: $request->commandUuid,
            providerResourceReference: $ref,
            outcome: $outcome,
            amountCents: $outcome === ProviderOutcome::Succeeded ? $request->amountCents : null,
            currency: $request->currency,
            occurredAt: new \DateTimeImmutable('now'),
            evidenceReference: $ref === null ? null : 'fake-evidence:'.$ref,
            providerMetadata: $metadata + ['fake' => true],
        );
    }
}
