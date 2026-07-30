<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Billing\ProviderNormalizedOutcome;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationUsageRecord;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderOperationDefaultPolicy;
use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\TestCase;

/**
 * ProviderBillableCallPipelineReservationGateTest — regression proof for
 * the Critical double-billing defect: step 12's `reserve()` was
 * idempotent for the ledger ROW, but step 13 fired the REAL outbound
 * call unconditionally afterwards, including when `reserve()` had merely
 * SELECT-fallen-back onto a reservation an earlier attempt had already
 * created (and may already have been billed for).
 *
 * Every provider call here is a plain counting closure — no Plaid, no
 * network, no credentials. The assertion that matters throughout is the
 * INVOCATION COUNT of that closure, plus the reservation's own final
 * status and the number of `integration_usage_records` rows written.
 */
class ProviderBillableCallPipelineReservationGateTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private ProviderBillableCallPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();
        $this->pipeline = app(ProviderBillableCallPipeline::class);
        Cache::flush();
    }

    // ------------------------------------------------------------
    // Fixtures (mirroring ProviderBillableCallPipelineTest's own
    // durable-audit-connection discipline exactly — see that file's
    // firmWithEntitlement() docblock for why the Firm must be
    // committed on 'pgsql_audit').
    // ------------------------------------------------------------

    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });

        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function execute(Firm $firm, FirmIntegration $connection, Closure $providerCall, string $key)
    {
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
    private function countingThrowingCall(Closure $thrower): array
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        return [function () use ($counter, $thrower) {
            $counter->calls++;

            throw $thrower();
        }, $counter];
    }

    private function reservationFor(Firm $firm, string $key): ProviderBillableCallReservation
    {
        return $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('idempotency_key', $key)
            ->firstOrFail());
    }

    private function reservationCount(Firm $firm, string $key): int
    {
        return $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('idempotency_key', $key)
            ->count());
    }

    private function usageRecordCount(Firm $firm): int
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function forceReservationState(Firm $firm, string $key, array $attributes): void
    {
        $this->runWithFirmContext($firm, function () use ($key, $attributes) {
            DB::table('provider_billable_call_reservations')
                ->where('idempotency_key', $key)
                ->update($attributes);
        });
    }

    /**
     * CHECKPOINT 8.2 (§A5). The pipeline now has TWO gates, and the
     * DURABLE operation gate (step 12d) is deliberately consulted first,
     * because the ambient reservation this suite exercises lives inside
     * the caller's transaction and can be missing, stale, or outright
     * contradicted by what really happened.
     *
     * That leaves the ambient gate with one job it still owns
     * exclusively, and it is the job this suite tests: a reservation that
     * has NO durable counterpart — i.e. one created before Checkpoint 8.2
     * shipped, or by a code path that predates the durable ledger. This
     * helper produces exactly that state by deleting the durable row for
     * the logical operation, leaving the ambient reservation untouched.
     *
     * Nothing is being weakened here. The scenarios below (a live peer
     * reservation, an already-finalized reservation, a stale one, an
     * abandoned one) are still driven end to end through the real
     * pipeline and still assert the same invocation counts, statuses and
     * usage-record counts as before. What changed is only that the
     * premise "no durable record exists for this reservation" is now
     * stated explicitly instead of being true by default — and the
     * complementary case, where durable evidence overrules a
     * contradictory reservation, is proven directly in
     * ProviderBillableCallPipelineDurableGateTest.
     */
    private function simulateReservationWithNoDurableRecord(string $key): void
    {
        DB::connection('pgsql_audit')
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', 'like', '%:'.$key)
            ->delete();
    }

    // ------------------------------------------------------------
    // 1. Retry inside the same wall-clock minute
    // ------------------------------------------------------------

    public function test_the_same_logical_operation_retried_in_the_same_minute_calls_the_provider_once(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call, $counter] = $this->countingCall();
        $key = 'renew:deterministic:same-minute';

        $first = $this->execute($firm, $connection, $call, $key);
        $second = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls, 'The real provider call must fire exactly once for one logical operation.');
        $this->assertSame(1, $this->reservationCount($firm, $key), 'Exactly one reservation (one customer-charge allocation) may exist.');
        $this->assertSame(1, $this->usageRecordCount($firm), 'Exactly one integration_usage_records row (one provider-cost entry) may exist.');

        $this->assertTrue($first->outcome->billable);
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $second->outcome->category);
        $this->assertTrue($second->outcome->servedWithoutProviderCall());
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $second->reservation->status);
    }

    // ------------------------------------------------------------
    // 2. Retry AFTER a minute rollover — the core regression test.
    //    Against the old `now()->format('YmdHi')` key logic the second
    //    attempt computed a DIFFERENT key, inserted a second
    //    reservation, and re-fired the real call.
    // ------------------------------------------------------------

    public function test_the_same_logical_operation_retried_after_a_minute_rollover_still_calls_the_provider_once(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call, $counter] = $this->countingCall();
        $key = 'renew:deterministic:minute-rollover';

        $this->execute($firm, $connection, $call, $key);

        $this->travel(90)->seconds();

        $second = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls);
        $this->assertSame(1, $this->reservationCount($firm, $key));
        $this->assertSame(1, $this->usageRecordCount($firm));
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $second->outcome->category);
    }

    // ------------------------------------------------------------
    // 3. The full RenewGraphSubscriptionJob backoff sequence
    //    (5 attempts, 30/60/120/240s apart).
    // ------------------------------------------------------------

    public function test_the_full_thirty_sixty_one_twenty_two_forty_backoff_sequence_calls_the_provider_once(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call, $counter] = $this->countingCall();
        $key = 'renew:deterministic:backoff-sequence';

        $this->execute($firm, $connection, $call, $key);

        foreach ([30, 60, 120, 240] as $backoffSeconds) {
            $this->travel($backoffSeconds)->seconds();
            $result = $this->execute($firm, $connection, $call, $key);
            $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $result->outcome->category);
        }

        $this->assertSame(1, $counter->calls, 'All five attempts must collapse onto one real provider call.');
        $this->assertSame(1, $this->reservationCount($firm, $key));
        $this->assertSame(1, $this->usageRecordCount($firm));
    }

    // ------------------------------------------------------------
    // 4. Worker crash between a returned provider call and finalize().
    // ------------------------------------------------------------

    public function test_a_crash_after_the_provider_call_started_is_treated_as_uncertain_and_never_re_fires_the_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$call, $counter] = $this->countingCall();
        $key = 'renew:deterministic:crash-after-call';

        // Attempt 1 completes normally, then is rewound to exactly the
        // state a worker killed between step 13 and step 15 leaves
        // behind: still `reserved`, TTL elapsed, outbound call already
        // started, no usage record.
        $this->execute($firm, $connection, $call, $key);
        $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->delete());
        $this->forceReservationState($firm, $key, [
            'status' => ProviderBillableCallReservation::STATUS_RESERVED,
            'finalized_at' => null,
            'usage_record_id' => null,
            'provider_call_started_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinutes(5),
        ]);
        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls, 'A crash with a call already in flight must never re-fire the real call.');
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $result->outcome->category);
        $this->assertSame(
            ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN,
            $this->reservationFor($firm, $key)->status,
            'The abandoned reservation must be parked terminally as uncertain — never left stuck in `reserved`, never billed.',
        );
        $this->assertSame(0, $this->usageRecordCount($firm), 'An uncertain outcome must never write a usage record.');
    }

    // ------------------------------------------------------------
    // 5-8. Exception handling — finalize() must always run.
    // ------------------------------------------------------------

    public function test_a_timeout_exception_still_finalizes_the_reservation_as_uncertain(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'exception:timeout';
        [$call, $counter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'renewSubscription'),
        );

        try {
            $this->execute($firm, $connection, $call, $key);
            $this->fail('Expected the original SanitizedProviderHttpException to be rethrown.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_TIMEOUT, $e->category());
        }

        $this->assertSame(1, $counter->calls);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN, $this->reservationFor($firm, $key)->status);
        $this->assertSame(0, $this->usageRecordCount($firm));
    }

    public function test_a_connection_reset_exception_still_finalizes_the_reservation_as_uncertain(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'exception:connection-reset';
        [$call, $counter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, null, 'renewSubscription'),
        );

        try {
            $this->execute($firm, $connection, $call, $key);
            $this->fail('Expected the original SanitizedProviderHttpException to be rethrown.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, $e->category());
        }

        $this->assertSame(1, $counter->calls);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN, $this->reservationFor($firm, $key)->status);
    }

    public function test_an_already_handled_sanitized_provider_rejection_behaves_exactly_as_before(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'exception:sanitized-rejection';
        [$call, $counter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, 400, 'renewSubscription'),
        );

        try {
            $this->execute($firm, $connection, $call, $key);
            $this->fail('Expected the original SanitizedProviderHttpException to be rethrown.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED, $e->category());
            $this->assertSame(400, $e->statusCode());
        }

        $this->assertSame(1, $counter->calls);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_NON_BILLABLE, $this->reservationFor($firm, $key)->status);
        $this->assertSame(0, $this->usageRecordCount($firm));

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.call_finalized_non_billable')
            ->first());
        $this->assertNotNull($event);
    }

    public function test_an_unexpected_runtime_exception_still_finalizes_the_reservation_and_rethrows_unchanged(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'exception:unexpected-runtime';
        [$call, $counter] = $this->countingThrowingCall(
            fn () => new RuntimeException('boom: a raw failure that never passed the sanitizing boundary'),
        );

        try {
            $this->execute($firm, $connection, $call, $key);
            $this->fail('Expected the original RuntimeException to be rethrown unchanged.');
        } catch (SanitizedProviderHttpException $e) {
            $this->fail('The original throwable must be rethrown unchanged, never reclassified into a sanitized one.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom: a raw failure that never passed the sanitizing boundary', $e->getMessage());
        }

        $this->assertSame(1, $counter->calls);
        $this->assertSame(
            ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN,
            $this->reservationFor($firm, $key)->status,
            'Before the widened catch this reservation stayed stuck in `reserved` with no usage record at all.',
        );
        $this->assertSame(0, $this->usageRecordCount($firm));
    }

    public function test_an_unsanitized_throwables_message_never_reaches_the_audit_trail(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'exception:redaction';
        $secret = 'access-token-super-secret-value';
        [$call] = $this->countingThrowingCall(fn () => new RuntimeException("provider said: {$secret}"));

        try {
            $this->execute($firm, $connection, $call, $key);
        } catch (RuntimeException) {
            // expected
        }

        $events = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->get());

        foreach ($events as $event) {
            $this->assertStringNotContainsString($secret, json_encode($event->getAttributes()));
        }
    }

    // ------------------------------------------------------------
    // 9-10. Existing-reservation states.
    // ------------------------------------------------------------

    public function test_a_live_reserved_row_from_another_in_flight_attempt_is_refused_without_calling_the_provider(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$seedCall] = $this->countingCall();
        $key = 'gate:live-reserved';

        $this->execute($firm, $connection, $seedCall, $key);
        $this->forceReservationState($firm, $key, [
            'status' => ProviderBillableCallReservation::STATUS_RESERVED,
            'finalized_at' => null,
            'usage_record_id' => null,
            'provider_call_started_at' => now(),
            'expires_at' => now()->addSeconds(120),
        ]);
        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        [$call, $counter] = $this->countingCall();

        $this->expectException(ProviderDuplicateRequestInFlightException::class);

        try {
            $this->execute($firm, $connection, $call, $key);
        } finally {
            $this->assertSame(0, $counter->calls, 'A live reservation held by another attempt must never re-fire the call.');
        }
    }

    public function test_two_concurrent_workers_with_the_identical_key_produce_exactly_one_real_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'gate:concurrent-workers';
        $counter = new class
        {
            public int $calls = 0;
        };

        // Worker B re-enters the pipeline with the identical idempotency
        // key from INSIDE worker A's own provider call — i.e. while
        // worker A's reservation is still live and un-finalized, the
        // tightest possible interleaving.
        $workerA = function () use (&$firm, &$connection, $key, $counter) {
            $counter->calls++;

            try {
                $this->execute($firm, $connection, function () use ($counter) {
                    $counter->calls++;

                    return ['statement_id' => 'stmt_b'];
                }, $key);
            } catch (ProviderDuplicateRequestInFlightException) {
                // expected — worker B is correctly refused
            }

            return ['statement_id' => 'stmt_a'];
        };

        $this->execute($firm, $connection, $workerA, $key);

        $this->assertSame(1, $counter->calls);
        $this->assertSame(1, $this->reservationCount($firm, $key));
        $this->assertSame(1, $this->usageRecordCount($firm));
    }

    public function test_an_existing_finalized_billable_row_serves_the_existing_outcome_with_no_new_call_or_usage_record(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$seedCall] = $this->countingCall();
        $key = 'gate:finalized-billable';

        $this->execute($firm, $connection, $seedCall, $key);
        $this->assertSame(1, $this->usageRecordCount($firm));
        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        [$call, $counter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(0, $counter->calls);
        $this->assertSame(1, $this->usageRecordCount($firm));
        $this->assertNull($result->response, 'A reservation records what a call cost, never what it returned.');
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $result->outcome->category);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $result->reservation->status);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.call_served_from_existing_reservation')
            ->first());
        $this->assertNotNull($event);
    }

    public function test_an_existing_finalized_uncertain_row_never_re_fires_the_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'gate:finalized-uncertain';
        [$throwingCall] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'renewSubscription'),
        );

        try {
            $this->execute($firm, $connection, $throwingCall, $key);
        } catch (SanitizedProviderHttpException) {
            // expected
        }

        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        [$call, $counter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(0, $counter->calls, 'An uncertain prior outcome may have already been billed — never re-fire it.');
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $result->outcome->category);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN, $result->reservation->status);
        $this->assertSame(0, $this->usageRecordCount($firm));
    }

    // ------------------------------------------------------------
    // 11-13. Re-claimable states.
    // ------------------------------------------------------------

    public function test_a_genuinely_stale_expired_reservation_is_treated_as_fresh_and_allows_one_new_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$seedCall] = $this->countingCall();
        $key = 'gate:expired-never-called';

        $this->execute($firm, $connection, $seedCall, $key);
        $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->delete());

        // Exactly what ExpireStaleProviderReservationsJob leaves behind
        // for a worker killed BETWEEN reserve() and the outbound call.
        $this->forceReservationState($firm, $key, [
            'status' => ProviderBillableCallReservation::STATUS_EXPIRED,
            'finalized_at' => now()->subMinutes(5),
            'usage_record_id' => null,
            'provider_call_started_at' => null,
            'expires_at' => now()->subMinutes(6),
        ]);
        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        [$call, $counter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls, 'The provider provably was never contacted, so one fresh attempt is safe.');
        $this->assertSame(1, $this->reservationCount($firm, $key), 'The unique index forbids a second row — the existing one is re-claimed.');
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $result->reservation->status);
        $this->assertSame(1, $this->usageRecordCount($firm));

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.reservation_reclaimed')
            ->first());
        $this->assertNotNull($event);
    }

    public function test_an_expired_reservation_whose_call_had_already_started_is_never_re_fired(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$seedCall] = $this->countingCall();
        $key = 'gate:expired-call-started';

        $this->execute($firm, $connection, $seedCall, $key);
        $this->runWithFirmContext($firm, fn () => IntegrationUsageRecord::query()->delete());
        $this->forceReservationState($firm, $key, [
            'status' => ProviderBillableCallReservation::STATUS_EXPIRED,
            'finalized_at' => now()->subMinutes(5),
            'usage_record_id' => null,
            'provider_call_started_at' => now()->subMinutes(7),
            'expires_at' => now()->subMinutes(6),
        ]);
        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        [$call, $counter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(0, $counter->calls);
        $this->assertSame(ProviderNormalizedOutcome::CATEGORY_SERVED_FROM_EXISTING_RESERVATION, $result->outcome->category);
        $this->assertSame(ProviderBillableCallReservation::STATUS_EXPIRED, $result->reservation->status);
        $this->assertSame(0, $this->usageRecordCount($firm));
    }

    public function test_a_finalized_non_billable_reservation_is_re_claimable_so_a_transient_rejection_stays_retryable(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $key = 'gate:finalized-non-billable';
        [$rejectingCall, $rejectingCounter] = $this->countingThrowingCall(
            fn () => new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'renewSubscription'),
        );

        try {
            $this->execute($firm, $connection, $rejectingCall, $key);
        } catch (SanitizedProviderHttpException) {
            // expected
        }

        $this->assertSame(1, $rejectingCounter->calls);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_NON_BILLABLE, $this->reservationFor($firm, $key)->status);
        $this->assertSame(0, $this->usageRecordCount($firm));

        // CHECKPOINT 8.2 (§A5): this scenario is a reservation with no
        // durable counterpart — the case the ambient gate still owns. See
        // simulateReservationWithNoDurableRecord()'s docblock.
        $this->simulateReservationWithNoDurableRecord($key);

        // A rate-limited rejection is positive knowledge that no
        // billable work happened, so the queue retry must still be able
        // to recover — refusing it would swallow exactly the transient
        // failures retries exist for.
        [$call, $counter] = $this->countingCall();
        $result = $this->execute($firm, $connection, $call, $key);

        $this->assertSame(1, $counter->calls);
        $this->assertSame(1, $this->reservationCount($firm, $key));
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $result->reservation->status);
        $this->assertSame(1, $this->usageRecordCount($firm), 'Exactly one charge overall: the rejected attempt was never billed.');
    }

    // ------------------------------------------------------------
    // 14. The other enforcement layers are untouched.
    // ------------------------------------------------------------

    public function test_the_reservation_gate_never_runs_before_the_kill_switch_cooldown_and_limit_layers(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        [$seedCall] = $this->countingCall();
        $key = 'gate:layer-ordering';

        $this->execute($firm, $connection, $seedCall, $key);

        // A hard limit set AFTER the reservation exists must still deny
        // at step 11, before the step-12b gate is ever consulted.
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'hard_limit_quantity' => 0,
        ]);

        [$call, $counter] = $this->countingCall();

        $this->expectException(ProviderHardLimitExceededException::class);

        try {
            $this->execute($firm, $connection, $call, $key);
        } finally {
            $this->assertSame(0, $counter->calls);
        }
    }
}
