<?php

declare(strict_types=1);

namespace App\Services\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\PaymentIntentNotExecutableException;
use App\Exceptions\Pay\TrustExecutionDisabledException;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * PaymentAttemptService — FirmsVault Pay Gate A2 (v1.4 §14/§19/§22/§23).
 * The only writer of payment_attempts.
 *
 * ATOMICITY (§14). open() performs, in ONE database transaction:
 *
 *     financial domain mutation (the PaymentAttempt row)
 *       + ProviderCommand   (the economic instruction)
 *       + Outbox row        (the dispatch trigger)
 *     COMMIT
 *
 * No provider network call happens anywhere in this class — the worker
 * makes it later, after the commit, having claimed the outbox row. That
 * ordering is what guarantees no provider call can ever precede local
 * commit.
 *
 * OUTBOX REUSE. The outbox row is created through the EXISTING
 * App\Integrations\Services\IntegrationOutboxEventService::recordOnce(),
 * unchanged, with `domain_event_id` set to the ProviderCommand's uuid.
 * Because `integration_outbox_events` already carries
 * UNIQUE (firm_id, domain_event_id), one economic instruction can
 * produce at most one outbox row, system-wide — the database, not this
 * code, is what makes duplicate dispatch impossible (FV-A2-007). No
 * second payment-specific outbox was created (§14).
 *
 * TRUST (§19). open() refuses outright if the intent carries ANY
 * trust-destined allocation. Trust value cannot reach command creation,
 * so there is no provider execution route to trust/IOLTA at all — in
 * addition to (never instead of) the repository's existing
 * unconditional block in PaymentClassificationService.
 */
class PaymentAttemptService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly PaymentIntentService $intents,
        private readonly ProviderCommandService $commands,
        private readonly IntegrationOutboxEventService $outbox,
        private readonly PayAuditRecorder $audit,
    ) {}

    /**
     * Open an attempt for a frozen, execution-eligible intent, together
     * with its immutable command and its outbox dispatch row.
     */
    public function open(PaymentIntent $intent, ?int $firmIntegrationId = null, ?int $integrationProviderId = null, ?string $methodToken = null): PaymentAttempt
    {
        $eligibility = $this->intents->executionEligibility($intent);

        if (! $eligibility['eligible']) {
            if ($eligibility['reason'] === 'trust_execution_disabled') {
                $this->audit->record(PayAuditRecorder::TRUST_EXECUTION_BLOCKED, (int) $intent->firm_id, [
                    'payment_intent_id' => $intent->id,
                    'trust_cents' => $eligibility['trust_cents'],
                    'operating_cents' => $eligibility['operating_cents'],
                ]);

                throw TrustExecutionDisabledException::forIntent((int) $intent->id, $eligibility['trust_cents']);
            }

            throw new PaymentIntentNotExecutableException(
                'PaymentIntent ['.$intent->id.'] is not executable: '.$eligibility['reason'].'.'
            );
        }

        $this->assertNoBlockingAttempt($intent);

        return $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            fn (): PaymentAttempt => DB::transaction(function () use ($intent, $firmIntegrationId, $integrationProviderId, $methodToken, $eligibility): PaymentAttempt {
                // Re-assert inside the transaction: another worker may
                // have opened an attempt between the check above and here.
                $this->assertNoBlockingAttempt($intent);

                $attempt = PaymentAttempt::query()->create([
                    'firm_id' => $intent->firm_id,
                    'payment_intent_id' => $intent->id,
                    'firm_integration_id' => $firmIntegrationId,
                    'state' => PaymentAttemptState::Created,
                    'amount_cents' => $eligibility['operating_cents'],
                    'currency' => $intent->currency,
                ]);

                $command = $this->commands->createOrReuse(
                    firmId: (int) $intent->firm_id,
                    commandType: ProviderCommandType::CapturePayment,
                    aggregateType: PaymentAttempt::class,
                    aggregateId: (int) $attempt->id,
                    idempotencyKey: 'capture:payment_attempt:'.$attempt->uuid,
                    canonicalPayload: [
                        'amount_cents' => (int) $attempt->amount_cents,
                        'currency' => $attempt->currency,
                        'payment_intent_uuid' => $intent->uuid,
                        'purpose' => $intent->purpose,
                        // Gate A3: opaque payment-method token/reference
                        // fixture (v1.4 §6). Part of the canonical payload,
                        // so a different token is a different economic
                        // instruction under the same key — by design.
                        'method_token' => $methodToken,
                    ],
                    firmIntegrationId: $firmIntegrationId,
                    integrationProviderId: $integrationProviderId,
                    paymentIntentId: (int) $intent->id,
                );

                $attempt->provider_command_id = $command->id;
                $attempt->save();

                // EXISTING outbox, unchanged. domain_event_id = command
                // uuid, so the existing UNIQUE (firm_id, domain_event_id)
                // makes a duplicate dispatch structurally impossible.
                $this->outbox->recordOnce(
                    firmId: (int) $intent->firm_id,
                    firmIntegrationId: $firmIntegrationId,
                    domainEventId: $command->uuid,
                    eventType: 'firmsvault_pay.provider_command.dispatch',
                );

                return $attempt->refresh();
            }),
        );
    }

    /**
     * Enforce the §22 transition matrix. Any transition not present in
     * PaymentAttemptState::transitionMatrix() is refused — including
     * every transition out of OutcomeUnknown, which has none.
     */
    public function transition(
        PaymentAttempt $attempt,
        PaymentAttemptState $next,
        ?string $providerReference = null,
        ?string $failureReason = null,
    ): PaymentAttempt {
        if (! $attempt->state->canTransitionTo($next)) {
            throw new \LogicException(
                'Illegal payment attempt transition ['.$attempt->state->value.' -> '.$next->value
                .'] for attempt ['.$attempt->id.'].'
            );
        }

        return $this->tenantContext->runWithFirmContext(
            (int) $attempt->firm_id,
            function () use ($attempt, $next, $providerReference, $failureReason): PaymentAttempt {
                $attempt->state = $next;

                if ($providerReference !== null) {
                    $attempt->provider_reference = $providerReference;
                }

                if ($failureReason !== null) {
                    $attempt->failure_reason = $failureReason;
                }

                if ($next === PaymentAttemptState::Submitted) {
                    $attempt->submitted_at = now();
                }

                if ($next->isTerminal()) {
                    $attempt->resolved_at = now();
                }

                if ($next === PaymentAttemptState::OutcomeUnknown) {
                    $this->audit->record(PayAuditRecorder::OUTCOME_UNKNOWN, (int) $attempt->firm_id, [
                        'payment_attempt_id' => $attempt->id,
                        'payment_intent_id' => $attempt->payment_intent_id,
                    ]);
                }

                $attempt->save();

                return $attempt->refresh();
            },
        );
    }

    /**
     * The §23 guarantee, expressed as a precondition rather than a
     * comment: an intent that already has a live or undetermined
     * attempt can never acquire another one.
     *
     * OutcomeUnknown is deliberately in this blocking set. That is the
     * whole point — "the economic outcome is uncertain" must never be
     * allowed to become "so let's charge again". The original attempt,
     * its original ProviderCommand and its original idempotency
     * identity are all retained, and provider-side recovery resolves
     * THAT attempt.
     */
    private function assertNoBlockingAttempt(PaymentIntent $intent): void
    {
        $blocking = [
            PaymentAttemptState::Created->value,
            PaymentAttemptState::Submitted->value,
            PaymentAttemptState::Captured->value,
            PaymentAttemptState::OutcomeUnknown->value,
        ];

        $existing = $this->tenantContext->runWithFirmContext(
            (int) $intent->firm_id,
            fn () => PaymentAttempt::query()
                ->where('payment_intent_id', $intent->id)
                ->whereIn('state', $blocking)
                ->first(),
        );

        if ($existing !== null) {
            throw new PaymentIntentNotExecutableException(
                'PaymentIntent ['.$intent->id.'] already has attempt ['.$existing->id.'] in state ['
                .$existing->state->value.']. A new charge may never be created while an existing attempt '
                .'is live or its economic outcome is undetermined.'
            );
        }
    }
}
