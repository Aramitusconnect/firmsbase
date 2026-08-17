<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Exceptions\ProviderOperationOwnershipLostException;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\Data\ProviderPaymentRequest;
use App\Services\TenantContextService;

/**
 * PaymentOutcomeRecoveryService — FirmsVault Pay Gate A3
 * (v1.4 §14-§17, §32-§34). Resolves OUTCOME_UNKNOWN attempts and
 * refunds by asking the provider the AUTHORITATIVE question — "what
 * happened to the ORIGINAL logical operation?" — and applying the
 * answer to the EXISTING aggregate.
 *
 * What recovery NEVER does:
 *   - create a new charge or refund command
 *   - re-send the original command
 *   - release a refund reservation whose outcome is still unknown
 *   - grant the at-most-once gate a new send permission
 *
 * Gate-row settlement uses the gate's OWN sanctioned path only:
 * `provider_outcome_uncertain -> reconciliation_required ->
 * resolveReconciliation(LocalProcessingComplete)`. The resolution is
 * always LocalProcessingComplete — whether the lookup proved SUCCEEDED
 * or proved FAILED, THIS logical operation is settled either way.
 * RetryAllowed is deliberately never produced here: that exit remains
 * operator-only, exactly as the Gate A2 security review froze it. A
 * later fresh charge is a NEW intent with a NEW command identity, not a
 * resurrection of this one.
 *
 * A lookup that cannot determine the result (STILL_UNKNOWN, §17)
 * changes nothing: the aggregate stays unknown, the reservation stays
 * held, the gate row stays uncertain, and recovery may simply run
 * again later.
 */
class PaymentOutcomeRecoveryService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly PaymentProviderAdapter $adapter,
        private readonly ProviderOutcomeApplierService $applier,
        private readonly ProviderOperationAttemptService $sendGate,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * @return string ProviderOutcomeApplierService::APPLIED | ALREADY_RESOLVED | STILL_UNKNOWN
     */
    public function recoverPayment(PaymentAttempt $attempt): string
    {
        if ($attempt->state !== PaymentAttemptState::OutcomeUnknown) {
            return ProviderOutcomeApplierService::ALREADY_RESOLVED;
        }

        $command = $this->commandOf($attempt->provider_command_id, (int) $attempt->firm_id);
        $result = $this->adapter->getPaymentOutcome(
            ProviderPaymentRequest::fromCommand($command, ProviderCommandExecutorService::ENVIRONMENT),
        );

        $applied = $this->applier->applyPaymentOutcome($attempt, $result);

        if ($applied === ProviderOutcomeApplierService::APPLIED) {
            $this->settleGateRow($command, 'authoritative_lookup:'.$result->outcome->value);
        }

        $this->audit->record('pay.outcome_recovery.payment', (int) $attempt->firm_id, [
            'payment_attempt_id' => $attempt->id,
            'result' => $applied,
            'outcome' => $result->outcome->value,
        ]);

        return $applied;
    }

    /**
     * @return string ProviderOutcomeApplierService::APPLIED | ALREADY_RESOLVED | STILL_UNKNOWN
     */
    public function recoverRefund(PaymentRefund $refund): string
    {
        if ($refund->state !== PaymentRefundState::OutcomeUnknown) {
            return ProviderOutcomeApplierService::ALREADY_RESOLVED;
        }

        $command = $this->commandOf($refund->provider_command_id, (int) $refund->firm_id);
        $result = $this->adapter->getRefundOutcome(
            ProviderPaymentRequest::fromCommand($command, ProviderCommandExecutorService::ENVIRONMENT),
        );

        $applied = $this->applier->applyRefundOutcome($refund, $result);

        if ($applied === ProviderOutcomeApplierService::APPLIED) {
            $this->settleGateRow($command, 'authoritative_lookup:'.$result->outcome->value);
        }

        $this->audit->record('pay.outcome_recovery.refund', (int) $refund->firm_id, [
            'payment_refund_id' => $refund->id,
            'result' => $applied,
            'outcome' => $result->outcome->value,
        ]);

        return $applied;
    }

    // ------------------------------------------------------------------

    private function commandOf(?int $commandId, int $firmId): ProviderCommand
    {
        if ($commandId === null) {
            throw new \LogicException('An unknown outcome without a ProviderCommand cannot be recovered.');
        }

        return $this->tenantContext->runWithFirmContext(
            $firmId,
            fn (): ProviderCommand => ProviderCommand::query()->findOrFail($commandId),
        );
    }

    /**
     * Settle the durable at-most-once row through its own sanctioned
     * exit. Idempotent under races: every step is a compare-and-set on
     * the source state, so a concurrent settler simply no-ops.
     */
    private function settleGateRow(ProviderCommand $command, string $reason): void
    {
        $row = $this->sendGate->findByLogicalKey($command->logicalOperationKey(), (int) $command->firm_id);

        if ($row === null) {
            return;
        }

        try {
            if ($row->attempt_state === ProviderOperationAttemptState::ProviderOutcomeUncertain) {
                $row = $this->sendGate->markReconciliationRequired($row, $reason);
            }

            if ($row->attempt_state === ProviderOperationAttemptState::ReconciliationRequired) {
                $this->sendGate->resolveReconciliation(
                    $row,
                    ProviderOperationAttemptState::LocalProcessingComplete,
                    $reason,
                );
            }
        } catch (ProviderOperationOwnershipLostException) {
            // A concurrent recovery settled it first — the exactly-once
            // effect already happened through the applier's row lock.
        }
    }
}
