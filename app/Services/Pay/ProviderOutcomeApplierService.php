<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderOutcome;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\ProviderCommand;
use App\Services\Pay\Data\ProviderResult;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * ProviderOutcomeApplierService — FirmsVault Pay Gate A3. THE single
 * place an authoritative provider outcome becomes an economic effect.
 *
 * ============================================================
 * WHY EXACTLY ONE APPLIER
 * ============================================================
 * Three independent paths can learn the same economic fact:
 *
 *     1. the synchronous adapter response (executor)
 *     2. the outcome-recovery lookup (recovery worker)
 *     3. an inbound provider event (ingestion)
 *
 * v1.4 §22/§23 requires that no combination of them — including
 * genuinely concurrent combinations — produces more than one attempt
 * transition, more than one journal effect, or more than one ownership
 * relationship. That is only provable if they all converge on ONE
 * function whose safety is mechanical:
 *
 *     BEGIN
 *       SELECT the attempt/refund row FOR UPDATE      <- serializes ALL
 *                                                        appliers per row
 *       already terminal?  -> AlreadyResolved, apply NOTHING
 *       apply state + ownership + journal atomically
 *     COMMIT
 *
 * The row lock serializes concurrent appliers; the terminal-state check
 * makes the loser a no-op; the journal's partial UNIQUE
 * (firm_id, idempotency_key) and the ownership index's partial UNIQUE
 * are the database backstops if anything ever bypassed this class.
 *
 * ============================================================
 * THE OUTCOME_UNKNOWN EXIT — why this bypasses transition()
 * ============================================================
 * Gate A2 froze OUTCOME_UNKNOWN with NO outgoing transitions in the
 * automated state machines (PaymentAttemptState / PaymentRefundState /
 * ProviderCommandStatus all return [] for it), and FV-A2-028/054 assert
 * exactly that. That rule means: no RETRY, no automated progression, no
 * new charge. It was never "unknown is unresolvable" — A2's own docs
 * say resolution comes from provider-side recovery resolving THIS
 * attempt (v1.4 §14/§15 make it mandatory).
 *
 * So this class is the ONE sanctioned exit from unknown, exactly
 * mirroring the existing at-most-once gate, where
 * `reconciliation_required` is terminal for every automated path and
 * `resolveReconciliation()` is the single authorized resolution. The
 * guarded direct writes below are legal only:
 *   - from OutcomeUnknown,
 *   - to a terminal economic outcome,
 *   - carrying an AUTHORITATIVE ProviderResult (lookup or event),
 *   - under the row lock.
 * Nothing here ever re-opens an attempt, re-sends a command, or turns
 * unknown into a second charge.
 */
class ProviderOutcomeApplierService
{
    public const APPLIED = 'applied';

    public const ALREADY_RESOLVED = 'already_resolved';

    public const STILL_UNKNOWN = 'still_unknown';

    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly ProviderResourceOwnershipService $ownership,
        private readonly ProviderPaymentJournalRecorderService $journal,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Apply an authoritative payment outcome to the attempt exactly
     * once. Returns APPLIED, ALREADY_RESOLVED or STILL_UNKNOWN.
     */
    public function applyPaymentOutcome(PaymentAttempt $attempt, ProviderResult $result): string
    {
        if (! $result->outcome->isEconomicOutcome()) {
            throw new \LogicException(
                'DuplicateRequiresLookup is an adapter-protocol signal, never an applicable economic outcome.'
            );
        }

        // A lookup that could not determine the result changes NOTHING:
        // the attempt stays unknown, reconciliation stays required, and
        // no new charge is ever created (v1.4 §17).
        if ($result->outcome === ProviderOutcome::OutcomeUnknown) {
            return self::STILL_UNKNOWN;
        }

        return $this->tenantContext->runWithFirmContext((int) $attempt->firm_id, function () use ($attempt, $result): string {
            return DB::transaction(function () use ($attempt, $result): string {
                // Re-read UNDER THE LOCK — the caller's copy may be stale
                // by the time this applier wins the row.
                /** @var PaymentAttempt $attempt */
                $attempt = PaymentAttempt::query()
                    ->whereKey($attempt->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($attempt->state === PaymentAttemptState::Created) {
                    throw new \LogicException(
                        'An outcome can never be applied to an attempt that was never submitted.'
                    );
                }

                if (in_array($attempt->state, [
                    PaymentAttemptState::Captured,
                    PaymentAttemptState::Declined,
                    PaymentAttemptState::Failed,
                    PaymentAttemptState::Cancelled,
                ], true)) {
                    return self::ALREADY_RESOLVED;
                }

                $target = match ($result->outcome) {
                    ProviderOutcome::Succeeded => PaymentAttemptState::Captured,
                    ProviderOutcome::Declined => PaymentAttemptState::Declined,
                    ProviderOutcome::Failed => PaymentAttemptState::Failed,
                    ProviderOutcome::Cancelled => PaymentAttemptState::Cancelled,
                    default => throw new \LogicException('Unreachable outcome.'),
                };

                if ($attempt->state === PaymentAttemptState::OutcomeUnknown) {
                    // The sanctioned recovery exit — see class docblock.
                    // Cancellation is not a recovery outcome: an unknown
                    // send can only be proven captured or proven failed.
                    if ($target === PaymentAttemptState::Cancelled) {
                        throw new \LogicException('An unknown outcome can never recover to Cancelled.');
                    }

                    if ($target === PaymentAttemptState::Declined) {
                        // Provider-neutral recovery semantics: a decline
                        // discovered by lookup is a definitive negative
                        // outcome; record it as Failed-with-reason so the
                        // recovery exit stays two-valued (proven money
                        // moved / proven it did not).
                        $target = PaymentAttemptState::Failed;
                        $attempt->failure_reason = 'declined';
                    }

                    $attempt->state = $target;
                    $attempt->resolved_at = now();
                    if ($result->providerResourceReference !== null) {
                        $attempt->provider_reference = $result->providerResourceReference;
                    }
                    $attempt->save();
                } else {
                    // Normal path (Submitted -> terminal) stays inside the
                    // frozen transition matrix.
                    if (! $attempt->state->canTransitionTo($target)) {
                        return self::ALREADY_RESOLVED;
                    }

                    $attempt->state = $target;
                    $attempt->resolved_at = now();
                    if ($result->providerResourceReference !== null) {
                        $attempt->provider_reference = $result->providerResourceReference;
                    }
                    $attempt->save();
                }

                $this->resolveCommand($attempt->provider_command_id, $result);

                if ($target === PaymentAttemptState::Captured) {
                    $this->recordCaptureEffects($attempt, $result);
                }

                $this->audit->record('pay.provider_outcome.applied', (int) $attempt->firm_id, [
                    'payment_attempt_id' => $attempt->id,
                    'outcome' => $result->outcome->value,
                    'provider_reference' => $result->providerResourceReference,
                ]);

                return self::APPLIED;
            });
        });
    }

    /**
     * Apply an authoritative refund outcome exactly once. The
     * reservation rules are the whole point (v1.4 §32-§34): unknown
     * KEEPS capacity held; only a proven outcome resolves it.
     */
    public function applyRefundOutcome(PaymentRefund $refund, ProviderResult $result): string
    {
        if (! $result->outcome->isEconomicOutcome()) {
            throw new \LogicException(
                'DuplicateRequiresLookup is an adapter-protocol signal, never an applicable economic outcome.'
            );
        }

        if ($result->outcome === ProviderOutcome::OutcomeUnknown) {
            return self::STILL_UNKNOWN;
        }

        return $this->tenantContext->runWithFirmContext((int) $refund->firm_id, function () use ($refund, $result): string {
            return DB::transaction(function () use ($refund, $result): string {
                /** @var PaymentRefund $refund */
                $refund = PaymentRefund::query()
                    ->whereKey($refund->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($refund->state, [
                    PaymentRefundState::Succeeded,
                    PaymentRefundState::ProviderFailed,
                    PaymentRefundState::Cancelled,
                    PaymentRefundState::ReservationExpired,
                ], true)) {
                    return self::ALREADY_RESOLVED;
                }

                $target = match ($result->outcome) {
                    ProviderOutcome::Succeeded => PaymentRefundState::Succeeded,
                    ProviderOutcome::Declined, ProviderOutcome::Failed => PaymentRefundState::ProviderFailed,
                    ProviderOutcome::Cancelled => PaymentRefundState::Cancelled,
                    default => throw new \LogicException('Unreachable outcome.'),
                };

                if ($refund->state === PaymentRefundState::OutcomeUnknown) {
                    if ($target === PaymentRefundState::Cancelled) {
                        throw new \LogicException('An unknown refund can never recover to Cancelled.');
                    }

                    $refund->state = $target;
                    $refund->resolved_at = now();
                    if ($result->providerResourceReference !== null) {
                        $refund->provider_reference = $result->providerResourceReference;
                    }
                    $refund->save();
                } else {
                    if (! $refund->state->canTransitionTo($target)) {
                        return self::ALREADY_RESOLVED;
                    }

                    $refund->state = $target;
                    $refund->resolved_at = now();
                    if ($result->providerResourceReference !== null) {
                        $refund->provider_reference = $result->providerResourceReference;
                    }
                    $refund->save();
                }

                $this->resolveCommand($refund->provider_command_id, $result);

                if ($target === PaymentRefundState::Succeeded) {
                    $firm = $refund->firm;

                    if ($result->providerResourceReference !== null
                        && $refund->firm_integration_id !== null) {
                        $command = $refund->providerCommand;
                        $this->ownership->establishOwnership(
                            (int) $refund->firm_id,
                            (int) $refund->firm_integration_id,
                            (int) $command->integration_provider_id,
                            'refund',
                            $result->providerResourceReference,
                        );
                    }

                    // Exactly-once by the journal's own partial UNIQUE
                    // (firm_id, idempotency_key).
                    $this->journal->recordProviderRefund($firm, $refund);
                }

                $this->audit->record('pay.provider_refund_outcome.applied', (int) $refund->firm_id, [
                    'payment_refund_id' => $refund->id,
                    'outcome' => $result->outcome->value,
                ]);

                return self::APPLIED;
            });
        });
    }

    // ------------------------------------------------------------------

    /**
     * Sync the command's execution metadata with the outcome. From
     * Dispatched this walks the frozen matrix; from OutcomeUnknown it is
     * the same sanctioned recovery exit as the attempt's (see class
     * docblock) — a guarded direct write, never a re-send.
     */
    private function resolveCommand(?int $commandId, ProviderResult $result): void
    {
        if ($commandId === null) {
            return;
        }

        /** @var ProviderCommand|null $command */
        $command = ProviderCommand::query()->whereKey($commandId)->lockForUpdate()->first();

        if ($command === null || $command->status === ProviderCommandStatus::Succeeded || $command->status === ProviderCommandStatus::Failed) {
            return;
        }

        $target = $result->outcome === ProviderOutcome::Succeeded
            ? ProviderCommandStatus::Succeeded
            : ProviderCommandStatus::Failed;

        $command->status = $target;
        $command->resolved_at = now();
        $command->reconciliation_required = false;
        if ($result->providerResourceReference !== null) {
            $command->provider_reference = $result->providerResourceReference;
        }
        if ($result->outcome !== ProviderOutcome::Succeeded) {
            $command->last_error = $result->outcome->value;
        }
        $command->save();
    }

    /**
     * The capture side-effects, inside the SAME locked transaction as
     * the state write so a racing applier can never interleave:
     *   - provider-resource ownership (idempotent for the same owner;
     *     the partial unique index is the cross-owner authority)
     *   - the clearing-account journal posting (idempotent via the
     *     journal's partial unique index)
     */
    private function recordCaptureEffects(PaymentAttempt $attempt, ProviderResult $result): void
    {
        if ($result->providerResourceReference !== null && $attempt->firm_integration_id !== null) {
            $command = $attempt->providerCommand;

            if ($command !== null && $command->integration_provider_id !== null) {
                $this->ownership->establishOwnership(
                    (int) $attempt->firm_id,
                    (int) $attempt->firm_integration_id,
                    (int) $command->integration_provider_id,
                    'payment',
                    $result->providerResourceReference,
                );
            }
        }

        // POC #1 recognizes the full captured amount as fee revenue
        // (v1.4 Gate A2 accounting contract): Dr ProcessorClearingOperating,
        // Cr LegalFeeRevenue — never OperatingCash (§38).
        $this->journal->recordProviderCapture(
            $attempt->firm,
            $attempt,
            feeCents: (int) $attempt->amount_cents,
            costCents: 0,
        );
    }
}
