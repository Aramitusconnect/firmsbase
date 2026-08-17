<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Enums\ProviderOutcome;
use App\Exceptions\Pay\ProviderCommunicationTimeoutException;
use App\Exceptions\Pay\ProviderConnectionMismatchException;
use App\Exceptions\Pay\ProviderEnvironmentMismatchException;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\Data\ProviderPaymentRequest;
use App\Services\Pay\Data\ProviderResult;
use App\Services\TenantContextService;

/**
 * ProviderCommandExecutorService — FirmsVault Pay Gate A3. The
 * worker-side execution of ONE ProviderCommand through the
 * provider-neutral adapter, guarded by the EXISTING durable
 * at-most-once send gate (v1.4 §4 — reused, never duplicated):
 *
 *     Outbox delivery (at-least-once)
 *         ↓
 *     ProviderOperationAttemptService::claim(fvpay:<command uuid>)
 *         ↓                       ← the at-most-once boundary
 *     PaymentProviderAdapter
 *         ↓
 *     ProviderOutcomeApplierService  ← the exactly-once effect boundary
 *
 * Duplicate outbox delivery therefore CANNOT duplicate economic
 * execution (v1.4 §18): the second delivery's claim() returns
 * AlreadyComplete/InFlightElsewhere/ReconciliationRequired — never a
 * second Proceed for a command that already left the process. The
 * send_count <= 1 invariant is the database fact behind that.
 *
 * DUPLICATE_REQUIRES_LOOKUP (v1.4 §19): when the provider recognizes
 * the command's idempotency identity, this executor never treats it as
 * a failure and never re-sends — it performs the authoritative outcome
 * lookup and reconciles the ORIGINAL transaction.
 *
 * Environment context is fixed to 'sandbox' for the whole POC; real
 * environment resolution (ProviderEnvironmentResolver) is Gate B
 * wiring.
 */
class ProviderCommandExecutorService
{
    public const ENVIRONMENT = 'sandbox';

    public const PROVIDER_KEY = 'fake-pay';

    public const RESULT_EXECUTED = 'executed';

    public const RESULT_DUPLICATE_DELIVERY_NOOP = 'duplicate_delivery_noop';

    public const RESULT_IN_FLIGHT = 'in_flight_elsewhere';

    public const RESULT_REQUIRES_RECOVERY = 'requires_recovery';

    public const RESULT_OUTCOME_UNKNOWN = 'outcome_unknown';

    public const RESULT_FAILED_CLOSED = 'failed_closed';

    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly ProviderOperationAttemptService $sendGate,
        private readonly PaymentProviderAdapter $adapter,
        private readonly ProviderOutcomeApplierService $applier,
        private readonly ProviderCommandService $commands,
        private readonly PaymentAttemptService $attempts,
        private readonly RefundReservationService $refunds,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Execute one command. Safe under at-least-once delivery: every
     * non-Proceed claim decision is a documented no-op or a recovery
     * signal, never a second send.
     */
    public function execute(ProviderCommand $command): string
    {
        $claim = $this->sendGate->claim(
            $command->logicalOperationKey(),
            self::PROVIDER_KEY,
            (int) $command->firm_id,
            $command->firm_integration_id === null ? null : (int) $command->firm_integration_id,
            $command->command_type->value,
        );

        switch ($claim->decision) {
            case ProviderOperationClaimDecision::AlreadyComplete:
                return self::RESULT_DUPLICATE_DELIVERY_NOOP;

            case ProviderOperationClaimDecision::InFlightElsewhere:
                return self::RESULT_IN_FLIGHT;

            case ProviderOperationClaimDecision::ReconciliationRequired:
                // The send happened (or is uncertain); only recovery may
                // proceed — NEVER another send (v1.4 §14).
                return self::RESULT_REQUIRES_RECOVERY;

            case ProviderOperationClaimDecision::ResumeLocalProcessing:
                // Provider work is done; finish the local half via the
                // authoritative lookup, without any new send.
                $result = $this->lookup($command);
                $this->applyToAggregate($command, $result);
                $this->sendGate->markLocalProcessingComplete($claim->attempt, $claim->ownerTokenOrFail());

                return self::RESULT_EXECUTED;

            case ProviderOperationClaimDecision::Proceed:
                return $this->sendAndApply($command, $claim->attempt, $claim->ownerTokenOrFail());
        }

        throw new \LogicException('Unhandled claim decision.');
    }

    // ------------------------------------------------------------------

    private function sendAndApply(ProviderCommand $command, $gateRow, string $ownerToken): string
    {
        $request = $this->buildRequest($command);

        // Mark the domain side "in flight" BEFORE the wire call.
        $this->tenantContext->runWithFirmContext((int) $command->firm_id, function () use ($command) {
            if ($command->status === ProviderCommandStatus::Pending) {
                $this->commands->transition($command, ProviderCommandStatus::Dispatched);
            }

            $aggregate = $this->aggregateOf($command);

            if ($aggregate instanceof PaymentAttempt && $aggregate->state === PaymentAttemptState::Created) {
                $this->attempts->transition($aggregate, PaymentAttemptState::Submitted);
            }
            // A refund is already ProviderPending when its command exists.
        });

        // Durably record the send BEFORE it happens — the at-most-once
        // fact (send_count 0 -> 1, compare-and-set).
        $this->sendGate->markAttemptStarted($gateRow, $ownerToken);

        try {
            $result = $command->command_type === ProviderCommandType::CapturePayment
                ? $this->adapter->createCardPayment($request)
                : $this->adapter->refundPayment($request);
        } catch (ProviderCommunicationTimeoutException $e) {
            // The ONLY correct reaction: OUTCOME_UNKNOWN on the existing
            // aggregate; original command and idempotency identity
            // retained; no retry, no new charge (v1.4 §14).
            $this->sendGate->recordProviderOutcomeUncertain($gateRow, $ownerToken, 'transport_timeout');
            $this->markUnknown($command);

            return self::RESULT_OUTCOME_UNKNOWN;
        } catch (ProviderConnectionMismatchException|ProviderEnvironmentMismatchException $e) {
            // Fail CLOSED: definitive refusal before any billable work —
            // no financial mutation of any kind (v1.4 §28/§29).
            $this->sendGate->recordProviderRejected($gateRow, $ownerToken, $e instanceof ProviderConnectionMismatchException ? 'provider_connection_mismatch' : 'environment_mismatch');
            $this->failClosed($command, $e->getMessage());

            return self::RESULT_FAILED_CLOSED;
        }

        if ($result->outcome === ProviderOutcome::DuplicateRequiresLookup) {
            // v1.4 §19 — the provider already holds this command. Never a
            // new charge, never a failure: authoritative lookup, then
            // reconcile the ORIGINAL transaction.
            $this->audit->record('pay.provider_duplicate_requires_lookup', (int) $command->firm_id, [
                'provider_command_id' => $command->id,
            ]);

            $result = $this->lookup($command);
        }

        if ($result->outcome === ProviderOutcome::OutcomeUnknown) {
            $this->sendGate->recordProviderOutcomeUncertain($gateRow, $ownerToken, 'provider_reported_unknown');
            $this->markUnknown($command);

            return self::RESULT_OUTCOME_UNKNOWN;
        }

        if ($result->outcome === ProviderOutcome::Succeeded) {
            $this->sendGate->recordProviderSucceeded(
                $gateRow,
                $ownerToken,
                providerOutcome: $result->outcome->value,
                redactedResultMetadata: 'resource='.($result->providerResourceReference ?? 'none'),
            );
        } else {
            // Declined/Failed BEFORE billable work — positive knowledge
            // that no money moved.
            $this->sendGate->recordProviderRejected($gateRow, $ownerToken, $result->outcome->value, $result->outcome->value);
        }

        $this->applyToAggregate($command, $result);

        if ($result->outcome === ProviderOutcome::Succeeded) {
            $this->sendGate->markLocalProcessingComplete($gateRow, $ownerToken);
        }

        return self::RESULT_EXECUTED;
    }

    private function lookup(ProviderCommand $command): ProviderResult
    {
        $request = $this->buildRequest($command);

        return $command->command_type === ProviderCommandType::CapturePayment
            ? $this->adapter->getPaymentOutcome($request)
            : $this->adapter->getRefundOutcome($request);
    }

    private function buildRequest(ProviderCommand $command): ProviderPaymentRequest
    {
        $parentReference = null;

        if ($command->command_type === ProviderCommandType::RefundPayment) {
            $parentReference = $this->tenantContext->runWithFirmContext(
                (int) $command->firm_id,
                fn () => PaymentRefund::query()
                    ->where('provider_command_id', $command->id)
                    ->first()?->paymentAttempt?->provider_reference,
            );
        }

        return ProviderPaymentRequest::fromCommand($command, self::ENVIRONMENT, $parentReference);
    }

    private function applyToAggregate(ProviderCommand $command, ProviderResult $result): void
    {
        $aggregate = $this->aggregateOf($command);

        if ($aggregate instanceof PaymentAttempt) {
            $this->applier->applyPaymentOutcome($aggregate, $result);

            return;
        }

        if ($aggregate instanceof PaymentRefund) {
            $this->applier->applyRefundOutcome($aggregate, $result);

            return;
        }

        throw new \LogicException('Provider command ['.$command->id.'] has no executable aggregate.');
    }

    private function aggregateOf(ProviderCommand $command): PaymentAttempt|PaymentRefund|null
    {
        return $this->tenantContext->runWithFirmContext((int) $command->firm_id, function () use ($command) {
            if ($command->command_type === ProviderCommandType::CapturePayment) {
                return PaymentAttempt::query()->where('provider_command_id', $command->id)->first();
            }

            return PaymentRefund::query()->where('provider_command_id', $command->id)->first();
        });
    }

    private function markUnknown(ProviderCommand $command): void
    {
        $this->tenantContext->runWithFirmContext((int) $command->firm_id, function () use ($command) {
            if ($command->status->canTransitionTo(ProviderCommandStatus::OutcomeUnknown)) {
                $this->commands->transition($command, ProviderCommandStatus::OutcomeUnknown);
            }

            $aggregate = $this->aggregateOf($command);

            if ($aggregate instanceof PaymentAttempt
                && $aggregate->state->canTransitionTo(PaymentAttemptState::OutcomeUnknown)) {
                $this->attempts->transition($aggregate, PaymentAttemptState::OutcomeUnknown);
            }

            if ($aggregate instanceof PaymentRefund
                && $aggregate->state->canTransitionTo(PaymentRefundState::OutcomeUnknown)) {
                $this->refunds->resolve($aggregate, PaymentRefundState::OutcomeUnknown);
            }
        });
    }

    private function failClosed(ProviderCommand $command, string $reason): void
    {
        $this->tenantContext->runWithFirmContext((int) $command->firm_id, function () use ($command, $reason) {
            if ($command->status->canTransitionTo(ProviderCommandStatus::Failed)) {
                $this->commands->transition($command, ProviderCommandStatus::Failed, lastError: mb_substr($reason, 0, 200));
            }

            $aggregate = $this->aggregateOf($command);

            if ($aggregate instanceof PaymentAttempt
                && $aggregate->state->canTransitionTo(PaymentAttemptState::Failed)) {
                $this->attempts->transition($aggregate, PaymentAttemptState::Failed, failureReason: mb_substr($reason, 0, 200));
            }

            if ($aggregate instanceof PaymentRefund
                && $aggregate->state->canTransitionTo(PaymentRefundState::ProviderFailed)) {
                $this->refunds->resolve($aggregate, PaymentRefundState::ProviderFailed, failureReason: mb_substr($reason, 0, 200));
            }
        });
    }
}
