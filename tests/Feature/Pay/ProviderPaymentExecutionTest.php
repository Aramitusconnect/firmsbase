<?php

declare(strict_types=1);

namespace Tests\Feature\Pay;

use App\Enums\PaymentAttemptState;
use App\Enums\ProviderCommandStatus;
use App\Enums\ProviderCommandType;
use App\Exceptions\Pay\IdempotencyConflictException;
use App\Models\Firm;
use App\Models\PaymentAttempt;
use App\Models\ProviderCommand;
use App\Services\Pay\Contracts\PaymentProviderAdapter;
use App\Services\Pay\PaymentOutcomeRecoveryService;
use App\Services\Pay\ProviderCommandExecutorService;
use App\Services\Pay\ProviderCommandService;
use App\Services\Pay\ProviderOutcomeApplierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pay\Concerns\BuildsPayFixtures;
use Tests\TestCase;

/**
 * FV-A3-001 … FV-A3-014, FV-A3-023 — fake provider contract, payment
 * execution, outcome recovery, idempotency and duplicate delivery.
 * CERTIFICATION BLOCKING throughout.
 */
class ProviderPaymentExecutionTest extends TestCase
{
    use BuildsPayFixtures, RefreshDatabase;

    private function executor(): ProviderCommandExecutorService
    {
        return app(ProviderCommandExecutorService::class);
    }

    private function recovery(): PaymentOutcomeRecoveryService
    {
        return app(PaymentOutcomeRecoveryService::class);
    }

    /** FV-A3-001 — the contract initializes with zero provider-specific leakage. */
    public function test_fv_a3_001_contract_initializes_without_provider_specific_leakage(): void
    {
        $fake = $this->payFake();

        $this->assertInstanceOf(PaymentProviderAdapter::class, $fake);
        $this->assertSame(0, $fake->paymentCalls);
        $this->assertSame(0, $fake->refundCalls);

        // The contract surface itself names no provider.
        foreach ([
            app_path('Services/Pay/Contracts/PaymentProviderAdapter.php'),
            app_path('Services/Pay/Data/ProviderPaymentRequest.php'),
            app_path('Services/Pay/Data/ProviderResult.php'),
            app_path('Enums/ProviderOutcome.php'),
        ] as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/use\s+.*(Finix|Stripe|LawPay)/i',
                $source,
                basename($file).' must not import any provider-specific class.'
            );
        }
    }

    /** FV-A3-002 — fake payment success: the full §11 chain, exactly once. */
    public function test_fv_a3_002_successful_payment_flow_end_to_end(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        $command = $this->payCommandOf($attempt);

        $result = $this->executor()->execute($command);

        $this->assertSame(ProviderCommandExecutorService::RESULT_EXECUTED, $result);

        $this->runWithFirmContext($firm, function () use ($attempt, $command, $provider) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Captured, $fresh->state);
            $this->assertNotNull($fresh->provider_reference);

            $freshCommand = ProviderCommand::query()->findOrFail($command->id);
            $this->assertSame(ProviderCommandStatus::Succeeded, $freshCommand->status);

            // Exactly one ownership relationship for the provider resource.
            $this->assertSame(1, DB::table('integration_webhook_routing_index')
                ->where('integration_provider_id', $provider->id)
                ->where('provider_resource_type', 'payment')
                ->where('provider_resource_id', $fresh->provider_reference)
                ->count());

            // Exactly one journal entry, debiting clearing — never cash.
            $entries = DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->get();
            $this->assertCount(1, $entries);

            // Audit evidence exists.
            $this->assertGreaterThan(0, DB::table('security_events')
                ->where('category', 'firmsvault_pay')
                ->where('event_type', 'pay.provider_outcome.applied')
                ->count());
        });

        // Exactly one command, exactly one attempt.
        $this->runWithFirmContext($firm, function () {
            $this->assertSame(1, ProviderCommand::query()->count());
            $this->assertSame(1, PaymentAttempt::query()->count());
        });
    }

    /** FV-A3-003 — decline: DECLINED state, no posting, no second attempt. */
    public function test_fv_a3_003_payment_decline(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:decline');
        $this->executor()->execute($this->payCommandOf($attempt));

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Declined, $fresh->state);

            $this->assertSame(0, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(),
                'A decline must never produce a successful-payment posting.');

            $this->assertSame(1, PaymentAttempt::query()->count(),
                'A decline must not automatically create a second attempt.');
        });
    }

    /** FV-A3-004 — definitive failure is FAILED, not DECLINED, not UNKNOWN. */
    public function test_fv_a3_004_definitive_payment_failure_is_distinguished(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:fail');
        $this->executor()->execute($this->payCommandOf($attempt));

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Failed, $fresh->state);
            $this->assertNotSame(PaymentAttemptState::Declined, $fresh->state);
            $this->assertNotSame(PaymentAttemptState::OutcomeUnknown, $fresh->state);
        });
    }

    /**
     * FV-A3-005 — timeout: the EXISTING attempt becomes OUTCOME_UNKNOWN,
     * the original command and idempotency identity are retained, and no
     * new charge command exists (v1.4 §14).
     */
    public function test_fv_a3_005_payment_timeout_becomes_outcome_unknown(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-success');
        $command = $this->payCommandOf($attempt);
        $originalUuid = $command->uuid;
        $originalKey = $command->idempotency_key;

        $result = $this->executor()->execute($command);

        $this->assertSame(ProviderCommandExecutorService::RESULT_OUTCOME_UNKNOWN, $result);

        $this->runWithFirmContext($firm, function () use ($attempt, $originalUuid, $originalKey) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::OutcomeUnknown, $fresh->state);

            $this->assertSame(1, ProviderCommand::query()->count(), 'NO new charge command may exist.');
            $command = ProviderCommand::query()->firstOrFail();
            $this->assertSame($originalUuid, $command->uuid, 'The original command is retained.');
            $this->assertSame($originalKey, $command->idempotency_key, 'The original idempotency identity is retained.');
            $this->assertSame(ProviderCommandStatus::OutcomeUnknown, $command->status);
            $this->assertTrue((bool) $command->reconciliation_required);

            // No premature economic effect.
            $this->assertSame(0, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count());
        });

        // The provider DID process it — dispatch and outcome are
        // separate facts (v1.4 §10).
        $this->assertTrue($this->payFake()->hasResourceFor('fvpay:'.$originalUuid));
    }

    /** FV-A3-006 — UNKNOWN → SUCCESS recovery: captured exactly once. */
    public function test_fv_a3_006_unknown_payment_recovers_to_success_exactly_once(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-success');
        $this->executor()->execute($this->payCommandOf($attempt));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $this->assertSame(PaymentAttemptState::OutcomeUnknown, $unknown->state);

        $applied = $this->recovery()->recoverPayment($unknown);
        $this->assertSame(ProviderOutcomeApplierService::APPLIED, $applied);

        $this->runWithFirmContext($firm, function () use ($attempt, $provider) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Captured, $fresh->state);

            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(),
                'Exactly one economic posting.');

            $this->assertSame(1, DB::table('integration_webhook_routing_index')
                ->where('integration_provider_id', $provider->id)
                ->where('provider_resource_type', 'payment')
                ->where('provider_resource_id', $fresh->provider_reference)
                ->count(), 'Exactly one ownership relationship.');

            $this->assertSame(1, ProviderCommand::query()->count(), 'No duplicate charge.');
        });

        // A second recovery run is a no-op, never a second posting.
        $recovered = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $this->assertSame(ProviderOutcomeApplierService::ALREADY_RESOLVED, $this->recovery()->recoverPayment($recovered));
    }

    /** FV-A3-007 — UNKNOWN → definitive FAILURE recovery: no posting, no new charge. */
    public function test_fv_a3_007_unknown_payment_recovers_to_failure(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-fail');
        $this->executor()->execute($this->payCommandOf($attempt));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $applied = $this->recovery()->recoverPayment($unknown);

        $this->assertSame(ProviderOutcomeApplierService::APPLIED, $applied);

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Failed, $fresh->state);

            $this->assertSame(0, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count(),
                'A recovered failure must never produce a successful-payment posting.');

            $this->assertSame(1, ProviderCommand::query()->count(), 'No new charge command.');
        });
    }

    /** FV-A3-008 — UNKNOWN remains UNKNOWN safely (§17): no state change, no charge. */
    public function test_fv_a3_008_unknown_payment_remains_unknown_safely(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-unknown');
        $this->executor()->execute($this->payCommandOf($attempt));

        $unknown = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $stillUnknown = $this->recovery()->recoverPayment($unknown);

        $this->assertSame(ProviderOutcomeApplierService::STILL_UNKNOWN, $stillUnknown);

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::OutcomeUnknown, $fresh->state, 'The attempt stays unknown.');

            $command = ProviderCommand::query()->firstOrFail();
            $this->assertTrue((bool) $command->reconciliation_required, 'Reconciliation remains required.');
            $this->assertSame(1, ProviderCommand::query()->count(), 'NO second charge, ever.');
        });

        // Recovery may be retried by policy — still safely unknown.
        $again = $this->runWithFirmContext($firm, fn () => PaymentAttempt::query()->findOrFail($attempt->id));
        $this->assertSame(ProviderOutcomeApplierService::STILL_UNKNOWN, $this->recovery()->recoverPayment($again));
    }

    /**
     * FV-A3-009 / FV-A3-023 — duplicate outbox/command delivery is
     * economically idempotent: same logical command, no second send, no
     * second attempt, no second journal, no second ownership row.
     */
    public function test_fv_a3_009_duplicate_command_delivery_is_economically_idempotent(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:success');
        $command = $this->payCommandOf($attempt);

        $first = $this->executor()->execute($command);
        // The SAME command delivered again — at-least-once delivery.
        $redelivered = $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->findOrFail($command->id));
        $second = $this->executor()->execute($redelivered);

        $this->assertSame(ProviderCommandExecutorService::RESULT_EXECUTED, $first);
        $this->assertSame(ProviderCommandExecutorService::RESULT_DUPLICATE_DELIVERY_NOOP, $second);

        // The provider was reached exactly ONCE.
        $this->assertSame(1, $this->payFake()->paymentCalls);

        $this->runWithFirmContext($firm, function () use ($attempt, $provider) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);

            $this->assertSame(1, PaymentAttempt::query()->count());
            $this->assertSame(1, ProviderCommand::query()->count());
            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count());
            $this->assertSame(1, DB::table('integration_webhook_routing_index')
                ->where('integration_provider_id', $provider->id)
                ->where('provider_resource_id', $fresh->provider_reference)
                ->count());
        });

        // The at-most-once database fact.
        $gate = DB::table('provider_operation_attempts')
            ->where('logical_operation_key', 'fvpay:'.$command->uuid)->first();
        $this->assertNotNull($gate);
        $this->assertSame(1, (int) $gate->send_count, 'send_count may never exceed 1.');
    }

    /**
     * FV-A3-010 — the provider's own duplicate-idempotency answer
     * (DUPLICATE_REQUIRES_LOOKUP) triggers a lookup that reconciles the
     * ORIGINAL transaction — never a failure, never a new charge (§19).
     */
    public function test_fv_a3_010_provider_duplicate_idempotency_triggers_lookup_not_new_charge(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:duplicate-lookup');
        $command = $this->payCommandOf($attempt);

        $result = $this->executor()->execute($command);

        $this->assertSame(ProviderCommandExecutorService::RESULT_EXECUTED, $result);
        $this->assertSame(1, $this->payFake()->lookupCalls, 'The duplicate answer must trigger exactly one lookup.');

        $this->runWithFirmContext($firm, function () use ($attempt) {
            $fresh = PaymentAttempt::query()->findOrFail($attempt->id);
            $this->assertSame(PaymentAttemptState::Captured, $fresh->state, 'The ORIGINAL transaction is reconciled.');
            $this->assertSame(1, ProviderCommand::query()->count(), 'Never a second financial command.');
            $this->assertSame(1, DB::table('accounting_journal_entries')
                ->where('payment_attempt_id', $attempt->id)->count());
        });
    }

    /** FV-A3-011 — same identity + same payload cannot create a second logical command. */
    public function test_fv_a3_011_same_key_same_payload_is_safe_at_the_adapter_boundary(): void
    {
        $firm = Firm::factory()->create();

        $make = fn () => app(ProviderCommandService::class)->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 77,
            idempotencyKey: 'a3:samekey',
            canonicalPayload: ['amount_cents' => 5_000, 'currency' => 'USD', 'method_token' => 'fake:success'],
        );

        $first = $this->runWithFirmContext($firm, $make);
        $second = $this->runWithFirmContext($firm, $make);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->count()));
    }

    /**
     * FV-A3-012 / FV-A3-013 — same identity + DIFFERENT payload is
     * blocked, and the conflict NEVER reaches the provider adapter.
     */
    public function test_fv_a3_012_013_idempotency_conflict_is_blocked_before_the_adapter(): void
    {
        $firm = Firm::factory()->create();
        $fake = $this->payFake();
        $callsBefore = $fake->paymentCalls + $fake->refundCalls + $fake->lookupCalls;

        $this->runWithFirmContext($firm, fn () => app(ProviderCommandService::class)->createOrReuse(
            firmId: (int) $firm->id,
            commandType: ProviderCommandType::CapturePayment,
            aggregateType: PaymentAttempt::class,
            aggregateId: 78,
            idempotencyKey: 'a3:conflictkey',
            canonicalPayload: ['amount_cents' => 5_000, 'currency' => 'USD'],
        ));

        try {
            $this->runWithFirmContext($firm, fn () => app(ProviderCommandService::class)->createOrReuse(
                firmId: (int) $firm->id,
                commandType: ProviderCommandType::CapturePayment,
                aggregateType: PaymentAttempt::class,
                aggregateId: 78,
                idempotencyKey: 'a3:conflictkey',
                canonicalPayload: ['amount_cents' => 999_999, 'currency' => 'USD'],
            ));
            $this->fail('A different payload under the same key must be refused.');
        } catch (IdempotencyConflictException) {
            // expected
        }

        // NO adapter call, NO provider resource, NO second command,
        // NO economic posting (v1.4 §21).
        $this->assertSame($callsBefore, $fake->paymentCalls + $fake->refundCalls + $fake->lookupCalls,
            'An idempotency conflict must never reach the provider adapter.');

        $this->runWithFirmContext($firm, function () {
            $this->assertSame(1, ProviderCommand::query()->count());
            $this->assertSame(0, PaymentAttempt::query()->count());
            $this->assertSame(0, DB::table('accounting_journal_entries')->count());
        });
    }

    /** FV-A3-014 — a worker retry preserves the original command identity. */
    public function test_fv_a3_014_worker_retry_preserves_original_command_identity(): void
    {
        $firm = $this->payFirmWithAccounting();
        [$provider, $connection] = $this->payProviderConnection($firm);

        $attempt = $this->payOpenAttemptWithToken($firm, $provider, $connection, 'fake:timeout-success');
        $command = $this->payCommandOf($attempt);
        $originalUuid = $command->uuid;

        // First delivery times out; a retried delivery of the SAME
        // command must not re-send — it reports "requires recovery".
        $this->executor()->execute($command);
        $redelivered = $this->runWithFirmContext($firm, fn () => ProviderCommand::query()->findOrFail($command->id));
        $retry = $this->executor()->execute($redelivered);

        $this->assertSame(ProviderCommandExecutorService::RESULT_REQUIRES_RECOVERY, $retry);
        $this->assertSame(1, $this->payFake()->paymentCalls, 'The retry must NOT re-send.');

        $this->runWithFirmContext($firm, function () use ($originalUuid) {
            $this->assertSame(1, ProviderCommand::query()->count());
            $this->assertSame($originalUuid, ProviderCommand::query()->firstOrFail()->uuid);
        });

        $gate = DB::table('provider_operation_attempts')
            ->where('logical_operation_key', 'fvpay:'.$originalUuid)->first();
        $this->assertSame(1, (int) $gate->send_count);
    }
}
