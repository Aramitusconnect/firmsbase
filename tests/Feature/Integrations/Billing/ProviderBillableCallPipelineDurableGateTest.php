<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Events\ProviderBillableCallCompleted;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderOperationAttempt;
use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * ProviderBillableCallPipelineDurableGateTest — Checkpoint 8.2 §A5. The
 * end-to-end proof that `ProviderBillableCallPipeline::execute()` is
 * phased CLAIM -> CALL -> APPLY -> RECOVER around evidence the caller's
 * transaction cannot erase.
 *
 * Its sibling `ProviderBillableCallPipelineReservationGateTest` covers the
 * AMBIENT reservation-state gate (step 12b), which is still the first line
 * of defense inside a single transaction. This file covers what that gate
 * structurally cannot do: survive a rollback, tell a local failure apart
 * from a provider failure, and refuse to guess when a provider's outcome
 * is unknown.
 *
 * Every provider call here is a plain counting closure — no Plaid, no
 * network, no credentials. The assertion that matters is the INVOCATION
 * COUNT, plus the durable row's own state.
 */
class ProviderBillableCallPipelineDurableGateTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    private ProviderBillableCallPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        $this->pipeline = app(ProviderBillableCallPipeline::class);
        Cache::flush();
    }

    /**
     * Mirrors ProviderBillableCallPipelineReservationGateTest's own
     * fixture discipline exactly: the pipeline's audit events are written
     * on the independent connection (`independentOfAmbientTransaction`),
     * and `timeline_events` has a real foreign key to `firms`, so the Firm
     * must be committed there. That requirement predates this checkpoint
     * and is unrelated to the gate table, which has no foreign keys at
     * all.
     */
    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection(self::DURABLE_CONNECTION);

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on(self::DURABLE_CONNECTION)->where('firm_id', $firm->id)->delete();
            });

            Firm::on(self::DURABLE_CONNECTION)->where('id', $firm->id)->delete();
        });

        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function execute(
        Firm $firm,
        FirmIntegration $connection,
        Closure $providerCall,
        string $key,
        ?Closure $redactResultForRecovery = null,
        ?string $localProcessingState = null,
    ) {
        return $this->pipeline->execute(
            providerKey: ProviderKey::Plaid,
            connection: $connection,
            firm: $firm,
            actor: null,
            product: 'statements',
            billingOperation: 'download',
            environment: 'production',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            providerCall: $providerCall,
            usageIdempotencyKey: $key,
            redactResultForRecovery: $redactResultForRecovery,
            localProcessingState: $localProcessingState,
        );
    }

    /** @return array{0: Closure, 1: object} */
    private function countingCall(mixed $return = ['statement_id' => 'stmt_1']): array
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        return [function () use ($counter, $return) {
            $counter->calls++;

            return $return;
        }, $counter];
    }

    /** @return array{0: Closure, 1: object} */
    private function countingThrowingCall(Closure $exceptionFactory): array
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        return [function () use ($counter, $exceptionFactory) {
            $counter->calls++;

            throw $exceptionFactory();
        }, $counter];
    }

    private function logicalKey(Firm $firm, string $usageIdempotencyKey): string
    {
        return implode(':', ['firm_'.$firm->id, 'plaid', 'statements', 'download', 'production', $usageIdempotencyKey]);
    }

    private function durableRow(Firm $firm, string $usageIdempotencyKey): ?ProviderOperationAttempt
    {
        return ProviderOperationAttempt::on(self::DURABLE_CONNECTION)
            ->where('firm_id', $firm->id)
            ->where('logical_operation_key', $this->logicalKey($firm, $usageIdempotencyKey))
            ->first();
    }

    // ------------------------------------------------------------------
    // CLAIM / CALL ordering
    // ------------------------------------------------------------------

    public function test_the_send_is_recorded_durably_before_the_provider_call_is_made(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:ordering';
        $observed = null;

        $this->execute($firm, $connection, function () use ($firm, $key, &$observed) {
            // Inside the real provider call: the durable row must ALREADY
            // say the request left the process. If it did not, a crash
            // right here would be indistinguishable from "never sent".
            $observed = $this->durableRow($firm, $key);

            return ['statement_id' => 'stmt_1'];
        }, $key);

        $this->assertNotNull($observed);
        $this->assertSame(ProviderOperationAttemptState::AttemptStarted, $observed->attempt_state);
        $this->assertSame(1, (int) $observed->send_count);
        $this->assertNotNull($observed->provider_started_at);
    }

    public function test_the_pipeline_holds_no_transaction_or_durable_session_state_across_the_provider_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $levelBefore = DB::transactionLevel();
        $observedAmbient = null;
        $observedDurable = null;

        $this->execute($firm, $connection, function () use (&$observedAmbient, &$observedDurable) {
            $observedAmbient = DB::transactionLevel();
            $observedDurable = DB::connection(self::DURABLE_CONNECTION)->transactionLevel();

            return ['statement_id' => 'stmt_1'];
        }, 'durable:no-open-transaction');

        $this->assertSame($levelBefore, $observedAmbient, 'The pipeline must not open a transaction around the provider call.');
        $this->assertSame(0, $observedDurable, 'The durable connection must have no open transaction during the call.');
    }

    public function test_a_completed_call_settles_the_logical_operation_end_to_end(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call, $counter] = $this->countingCall();

        $result = $this->execute($firm, $connection, $call, 'durable:happy-path');

        $this->assertSame(1, $counter->calls);
        $this->assertSame(1, (int) $this->durableRow($firm, 'durable:happy-path')->send_count);
        $this->assertSame(
            ProviderOperationAttemptState::LocalProcessingComplete,
            $this->durableRow($firm, 'durable:happy-path')->attempt_state
        );
        $this->assertTrue($result->isAlreadySettled());
        $this->assertFalse($result->mustResumeLocalProcessing());
        $this->assertNotNull($result->operationOwnerToken);
    }

    // ------------------------------------------------------------------
    // The C3 defect, at pipeline level
    // ------------------------------------------------------------------

    public function test_a_successful_call_whose_caller_transaction_rolls_back_is_never_re_sent(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:success-then-rollback';

        // The exact original defect: the provider does the work, then the
        // caller's own transaction rolls back, taking the reservation and
        // its usage record with it.
        DB::beginTransaction();
        [$firstCall, $firstCounter] = $this->countingCall();
        $this->execute($firm, $connection, $firstCall, $key);
        DB::rollBack();

        $this->assertSame(1, $firstCounter->calls);
        $this->assertSame(0, $this->reservationCount($firm, $key), 'The ambient reservation really is gone.');

        // The retry. Nothing on the ambient connection remembers the call.
        [$secondCall, $secondCounter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $secondCall, $key);

        $this->assertSame(0, $secondCounter->calls, 'The provider must never be called twice for one logical operation.');
        $this->assertTrue($result->outcome->servedWithoutProviderCall());
        $this->assertNotNull($result->operationAttempt);
        $this->assertSame(1, (int) $result->operationAttempt->send_count);
    }

    public function test_ambient_state_that_claims_the_call_never_happened_cannot_overrule_durable_evidence(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:ambient-fiction';
        [$seedCall, $seedCounter] = $this->countingCall();

        $this->execute($firm, $connection, $seedCall, $key);
        $this->assertSame(1, $seedCounter->calls);

        // Rewrite the ambient reservation to assert — falsely — that the
        // provider was never contacted. This is the shape a corrupted or
        // hand-edited reservation row would have.
        $this->runWithFirmContext($firm, function () use ($key) {
            DB::table('provider_billable_call_reservations')
                ->where('idempotency_key', $key)
                ->update([
                    'status' => ProviderBillableCallReservation::STATUS_EXPIRED,
                    'provider_call_started_at' => null,
                    'usage_record_id' => null,
                    'expires_at' => now()->subMinutes(6),
                    'finalized_at' => now()->subMinutes(5),
                ]);
        });

        [$call, $counter] = $this->countingCall();
        $this->execute($firm, $connection, $call, $key);

        $this->assertSame(0, $counter->calls, 'Durable evidence of a completed send must win over ambient state that contradicts it.');
    }

    // ------------------------------------------------------------------
    // Local failure vs provider failure
    // ------------------------------------------------------------------

    public function test_a_local_apply_failure_after_provider_success_is_recorded_as_local_and_resumed_not_re_sent(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:local-apply-throws';

        // Make the LOCAL apply phase fail after the provider succeeded, by
        // throwing from a listener on the pipeline's own step-17
        // observability event. That is a real local failure at a real
        // point in the APPLY phase, and needs no mocking: this service
        // layer is deliberately `final`.
        Event::listen(ProviderBillableCallCompleted::class, function () {
            throw new RuntimeException('local materializer exploded');
        });

        [$call, $counter] = $this->countingCall();

        try {
            $this->execute($firm, $connection, $call, $key, localProcessingState: 'page-1');
            $this->fail('The local failure must propagate to the caller unchanged.');
        } catch (RuntimeException $e) {
            $this->assertSame('local materializer exploded', $e->getMessage());
        }

        $this->assertSame(1, $counter->calls);

        $row = $this->durableRow($firm, $key);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingFailed, $row->attempt_state);
        $this->assertStringContainsString('pipeline_local_apply_threw:', (string) $row->state_reason);
        $this->assertSame('page-1', $row->local_processing_state);
        $this->assertSame(1, (int) $row->send_count);

        // The retry, with a healthy local layer, must resume rather than
        // call the provider again.
        Event::forget(ProviderBillableCallCompleted::class);
        Cache::flush();

        [$retryCall, $retryCounter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $retryCall, $key);

        $this->assertSame(0, $retryCounter->calls);
        $this->assertTrue($result->mustResumeLocalProcessing());
        $this->assertNotNull($result->operationOwnerToken, 'The resuming worker needs ownership to record completion.');
    }

    public function test_a_definite_provider_rejection_stays_retryable_so_transient_failures_still_recover(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:rejected-then-retried';
        [$rejecting, $rejectCounter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, null, 'downloadStatement'),
        );

        try {
            $this->execute($firm, $connection, $rejecting, $key);
        } catch (SanitizedProviderHttpException) {
            // expected — rethrown unchanged for the caller's own backoff.
        }

        $this->assertSame(1, $rejectCounter->calls);
        $this->assertSame(ProviderOperationAttemptState::ProviderRejected, $this->durableRow($firm, $key)->attempt_state);

        [$call, $counter] = $this->countingCall();
        $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls, 'A definite pre-billing rejection must remain retryable.');

        $row = $this->durableRow($firm, $key);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete, $row->attempt_state);
        $this->assertSame(1, (int) $row->send_count, 'The new generation sent exactly once.');
        $this->assertSame(2, (int) $row->total_send_count, 'Both sends remain on the record.');
    }

    public function test_an_uncertain_provider_outcome_demands_reconciliation_and_refuses_every_further_attempt(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:uncertain';
        [$timingOut, $timeoutCounter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'downloadStatement'),
        );

        try {
            $this->execute($firm, $connection, $timingOut, $key);
        } catch (SanitizedProviderHttpException) {
            // expected
        }

        $this->assertSame(1, $timeoutCounter->calls);

        $row = $this->durableRow($firm, $key);
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired, $row->attempt_state);
        $this->assertStringContainsString('uncertain_provider_outcome:', (string) $row->reconciliation_reason);

        [$call, $counter] = $this->countingCall();

        try {
            $this->execute($firm, $connection, $call, $key);
            $this->fail('An operation with an unknown outcome must not be attempted again.');
        } catch (ProviderOperationRequiresReconciliationException $e) {
            $this->assertSame($this->logicalKey($firm, $key), $e->logicalOperationKey);
        }

        $this->assertSame(0, $counter->calls);
    }

    public function test_a_peer_holding_a_live_claim_is_refused_with_the_existing_duplicate_signal(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'durable:peer-in-flight';

        // A peer worker claimed this exact logical operation moments ago
        // and still holds its lease.
        DB::connection(self::DURABLE_CONNECTION)->table('provider_operation_attempts')->insert([
            'uuid' => (string) Str::uuid7(),
            'logical_operation_key' => $this->logicalKey($firm, $key),
            'provider_key' => 'plaid',
            'firm_id' => $firm->id,
            'firm_integration_id' => $connection->id,
            'operation_type' => 'statements.download',
            'operation_version' => 1,
            'attempt_state' => ProviderOperationAttemptState::Claimed->value,
            'send_count' => 0,
            'total_send_count' => 0,
            'reclaim_count' => 0,
            'owner_token' => 'peer-owner-token',
            'lease_expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$call, $counter] = $this->countingCall();

        $this->expectException(ProviderDuplicateRequestInFlightException::class);

        try {
            $this->execute($firm, $connection, $call, $key);
        } finally {
            $this->assertSame(0, $counter->calls, 'Two workers must never call the provider for one logical operation.');
        }
    }

    // ------------------------------------------------------------------
    // Redaction (§A8 preconditions)
    // ------------------------------------------------------------------

    public function test_no_provider_payload_is_stored_by_default_only_a_one_way_digest(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call] = $this->countingCall(['account_number' => '000123456789', 'statement_id' => 'stmt_secret']);

        $this->execute($firm, $connection, $call, 'durable:redaction-default');

        $row = $this->durableRow($firm, 'durable:redaction-default');
        $this->assertNull($row->redacted_result_metadata, 'Without an explicit redactor, nothing from the response is kept.');
        $this->assertStringStartsWith('sha256:', (string) $row->result_checksum);

        $serialized = json_encode($row->toArray());
        $this->assertStringNotContainsString('000123456789', $serialized);
        $this->assertStringNotContainsString('stmt_secret', $serialized);
    }

    public function test_only_the_callers_own_redacted_summary_is_stored_when_one_is_supplied(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call] = $this->countingCall(['account_number' => '000123456789', 'transactions' => [1, 2, 3]]);

        $this->execute(
            $firm,
            $connection,
            $call,
            'durable:redaction-custom',
            redactResultForRecovery: fn (mixed $response) => 'transactions='.count($response['transactions']),
        );

        $row = $this->durableRow($firm, 'durable:redaction-custom');
        $this->assertSame('transactions=3', $row->redacted_result_metadata);
        $this->assertStringNotContainsString('000123456789', (string) $row->redacted_result_metadata);
    }

    private function reservationCount(Firm $firm, string $key): int
    {
        return $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('idempotency_key', $key)
            ->count());
    }
}
