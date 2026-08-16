<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRefundState;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\RefundCapacityExceededException;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * RefundReservationService — FirmsVault Pay Gate A2 (v1.4 §24-§28).
 * The only writer of payment_refunds. CERTIFICATION BLOCKING.
 *
 * ============================================================
 * THE CONCURRENCY MECHANISM (§25/§26) — stated explicitly
 * ============================================================
 * The invariant:
 *
 *     SUM(successful refunds + active reservations) <= captured amount
 *
 * cannot be a row-level CHECK (it is a cross-row aggregate) and this
 * codebase has a standing zero-trigger convention, so it is enforced by
 * a real PostgreSQL locking protocol:
 *
 *     BEGIN
 *       SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE   <-- (1)
 *       SELECT COALESCE(SUM(amount_cents), 0) FROM payment_refunds
 *         WHERE payment_attempt_id = ?
 *           AND state IN (<capacity-holding states>)             <-- (2)
 *       -- refuse if requested > captured - held
 *       INSERT INTO payment_refunds (...)                        <-- (3)
 *     COMMIT
 *
 * (1) is the whole mechanism. Every reserver for a given attempt must
 * take an exclusive row lock on THAT ONE attempt row before it may read
 * the sum, so reservations against the same attempt are fully
 * serialized. A second worker blocks at (1) until the first commits,
 * and then its (2) already includes the first worker's new reservation.
 * The read-then-insert window that would otherwise let two workers both
 * see "0 held" simply does not exist.
 *
 * This is deliberately NOT the pattern §25 forbids — "SELECT refundable
 * balance / PHP checks value / INSERT refund" with no lock. The lock is
 * taken before the balance is read, not after.
 *
 * Concurrency is proved against real PostgreSQL by FV-A2-051/052 using
 * two genuinely separate database connections.
 * ============================================================
 *
 * WHAT COUNTS AS HELD CAPACITY is defined in exactly one place:
 * App\Enums\PaymentRefundState::holdsRefundableCapacity(). Both this
 * service and its tests read that definition, so they can never drift.
 *
 * OUTCOME_UNKNOWN KEEPS THE HOLD (§28). A refund whose provider outcome
 * is undetermined still consumes capacity, and this class provides no
 * path that releases it or issues a second provider refund command for
 * the same refund (payment_refunds.provider_command_id is UNIQUE).
 */
class RefundReservationService
{
    /**
     * How long a reservation is honored before a sweeper may consider
     * it abandoned. Only a refund that never reached the provider can
     * legitimately expire — see expireIfUnsent().
     */
    public const DEFAULT_RESERVATION_SECONDS = 900;

    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly ProviderCommandService $commands,
        private readonly IntegrationOutboxEventService $outbox,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Atomically reserve refundable capacity. Returns the reserved
     * refund, or throws RefundCapacityExceededException.
     */
    public function reserve(
        PaymentAttempt $attempt,
        int $amountCents,
        ?string $reason = null,
        ?int $reservationSeconds = null,
    ): PaymentRefund {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException(
                'A refund amount must be greater than zero; got '.$amountCents.'.'
            );
        }

        $reservationSeconds ??= self::DEFAULT_RESERVATION_SECONDS;

        return $this->tenantContext->runWithFirmContext(
            (int) $attempt->firm_id,
            fn (): PaymentRefund => DB::transaction(function () use ($attempt, $amountCents, $reason, $reservationSeconds): PaymentRefund {
                // (1) Serialize every reserver for this attempt.
                /** @var PaymentAttempt $locked */
                $locked = PaymentAttempt::query()
                    ->whereKey($attempt->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->state !== PaymentAttemptState::Captured) {
                    throw new RefundCapacityExceededException(
                        (int) $locked->id,
                        0,
                        0,
                        $amountCents,
                    );
                }

                $captured = $locked->refundableCapacityCents();

                // (2) Now — and only now — read what is already held.
                $held = (int) PaymentRefund::query()
                    ->where('payment_attempt_id', $locked->id)
                    ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                    ->sum('amount_cents');

                if ($amountCents > $captured - $held) {
                    $this->audit->record(PayAuditRecorder::REFUND_CAPACITY_REFUSED, (int) $locked->firm_id, [
                        'payment_attempt_id' => $locked->id,
                        'captured_cents' => $captured,
                        'held_cents' => $held,
                        'requested_cents' => $amountCents,
                    ]);

                    throw new RefundCapacityExceededException(
                        (int) $locked->id,
                        $captured,
                        $held,
                        $amountCents,
                    );
                }

                // (3) Safe: the lock is still held, so this insert
                // cannot race another reserver's read.
                $refund = PaymentRefund::query()->create([
                    'firm_id' => $locked->firm_id,
                    'payment_attempt_id' => $locked->id,
                    'firm_integration_id' => $locked->firm_integration_id,
                    'state' => PaymentRefundState::Reserved,
                    'amount_cents' => $amountCents,
                    'currency' => $locked->currency,
                    'reason' => $reason,
                    'reserved_at' => now(),
                    'reservation_expires_at' => now()->addSeconds($reservationSeconds),
                ]);

                $this->audit->record(PayAuditRecorder::REFUND_RESERVED, (int) $locked->firm_id, [
                    'payment_refund_id' => $refund->id,
                    'payment_attempt_id' => $locked->id,
                    'amount_cents' => $amountCents,
                    'held_before_cents' => $held,
                    'captured_cents' => $captured,
                ]);

                return $refund;
            }),
        );
    }

    /**
     * Attach the economic instruction and its outbox dispatch row to a
     * reserved refund, atomically (§14).
     *
     * payment_refunds.provider_command_id is UNIQUE, so a refund can
     * never acquire a second provider command — the database half of
     * "no second provider refund command while the original outcome is
     * unresolved" (§28).
     */
    public function submitToProvider(PaymentRefund $refund, ?int $integrationProviderId = null): PaymentRefund
    {
        if (! $refund->state->canTransitionTo(PaymentRefundState::ProviderPending)) {
            throw new \LogicException(
                'Illegal refund transition ['.$refund->state->value.' -> provider_pending] for refund ['
                .$refund->id.'].'
            );
        }

        return $this->tenantContext->runWithFirmContext(
            (int) $refund->firm_id,
            fn (): PaymentRefund => DB::transaction(function () use ($refund, $integrationProviderId): PaymentRefund {
                $command = $this->commands->createOrReuse(
                    firmId: (int) $refund->firm_id,
                    commandType: ProviderCommandType::RefundPayment,
                    aggregateType: PaymentRefund::class,
                    aggregateId: (int) $refund->id,
                    idempotencyKey: 'refund:payment_refund:'.$refund->uuid,
                    canonicalPayload: [
                        'amount_cents' => (int) $refund->amount_cents,
                        'currency' => $refund->currency,
                        'payment_attempt_id' => (int) $refund->payment_attempt_id,
                    ],
                    firmIntegrationId: $refund->firm_integration_id,
                    integrationProviderId: $integrationProviderId,
                );

                $refund->provider_command_id = $command->id;
                $refund->state = PaymentRefundState::ProviderPending;
                $refund->submitted_at = now();
                $refund->save();

                $this->outbox->recordOnce(
                    firmId: (int) $refund->firm_id,
                    firmIntegrationId: $refund->firm_integration_id,
                    domainEventId: $command->uuid,
                    eventType: 'firmsvault_pay.provider_command.dispatch',
                );

                return $refund->refresh();
            }),
        );
    }

    /**
     * Record a provider outcome. The §28 rule lives here: moving to
     * OutcomeUnknown changes the state but NEVER touches the
     * reservation, so capacity stays held.
     */
    public function resolve(
        PaymentRefund $refund,
        PaymentRefundState $next,
        ?string $providerReference = null,
        ?string $failureReason = null,
    ): PaymentRefund {
        if (! $refund->state->canTransitionTo($next)) {
            throw new \LogicException(
                'Illegal refund transition ['.$refund->state->value.' -> '.$next->value.'] for refund ['
                .$refund->id.'].'
            );
        }

        return $this->tenantContext->runWithFirmContext(
            (int) $refund->firm_id,
            function () use ($refund, $next, $providerReference, $failureReason): PaymentRefund {
                $refund->state = $next;
                $refund->resolved_at = now();

                if ($providerReference !== null) {
                    $refund->provider_reference = $providerReference;
                }

                if ($failureReason !== null) {
                    $refund->failure_reason = $failureReason;
                }

                if ($next === PaymentRefundState::OutcomeUnknown) {
                    // Deliberately NOT clearing reserved_at or
                    // reservation_expires_at: the hold survives an
                    // undetermined outcome (§28).
                    $this->audit->record(PayAuditRecorder::OUTCOME_UNKNOWN, (int) $refund->firm_id, [
                        'payment_refund_id' => $refund->id,
                        'payment_attempt_id' => $refund->payment_attempt_id,
                    ]);
                }

                $refund->save();

                return $refund->refresh();
            },
        );
    }

    /**
     * Currently held capacity for an attempt. Read-only; shares the one
     * definition of "held" with reserve().
     */
    public function heldCapacityCents(PaymentAttempt $attempt): int
    {
        return (int) $this->tenantContext->runWithFirmContext(
            (int) $attempt->firm_id,
            fn () => PaymentRefund::query()
                ->where('payment_attempt_id', $attempt->id)
                ->whereIn('state', PaymentRefundState::capacityHoldingValues())
                ->sum('amount_cents'),
        );
    }

    /**
     * Release a reservation that provably never reached the provider.
     *
     * Only legal from Reserved — a refund that has been submitted, or
     * whose outcome is unknown, can never be expired, because expiring
     * it would free capacity for money that may already be gone.
     */
    public function expireIfUnsent(PaymentRefund $refund): bool
    {
        if ($refund->state !== PaymentRefundState::Reserved) {
            return false;
        }

        $this->resolve($refund, PaymentRefundState::ReservationExpired);

        return true;
    }
}
