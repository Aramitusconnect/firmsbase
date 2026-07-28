<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\ProviderCooldownActiveException;
use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Exceptions\ProviderHardLimitExceededException;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Models\ProviderFirmOperationPolicy;
use App\Integrations\Models\ProviderKillSwitch;
use App\Integrations\Models\ProviderOperationDefaultPolicy;
use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderBillableCallPipelineTest — end-to-end proof that the real,
 * live `ProviderBillableCallPipeline::execute()` (checkpoint4-design-cost-control.md
 * §2's 17-step orchestrator) actually wires every one of its
 * constituent services together correctly, not merely that each
 * service works in isolation (the unit-level tests elsewhere in this
 * directory already prove that). Covers kill switches (product-level
 * and firm optional-operation-suspension), soft/hard limits, cache
 * hit, cooldown enforcement, concurrent-duplicate-request denial, and
 * the uncertain-outcome path — each proven through the pipeline's own
 * public `execute()` entry point.
 */
class ProviderBillableCallPipelineTest extends TestCase
{
    use RefreshDatabase;

    private ProviderBillableCallPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = app(ProviderBillableCallPipeline::class);
        Cache::flush();
    }

    /**
     * Every pipeline step that reserves, denies, or finalizes a call
     * fires at least one `TimelineEventRecorder::record(...,
     * independentOfAmbientTransaction: true)` event — which durably
     * writes on the SEPARATE 'pgsql_audit' connection precisely so a
     * denial/finalize event survives the throw that follows it
     * (TimelineEventRecorder's own docblock). That durable write can
     * only see a Firm row genuinely COMMITTED in another database
     * session — a Firm created on the default, RefreshDatabase-wrapped
     * connection is never committed for this test's whole duration, so
     * it must be created for real via
     * Firm::factory()->connection('pgsql_audit')->create() instead,
     * mirroring IntegrationAccessPolicyServiceTest's own established
     * `cleanUpDurableFirmAuditTrailAfterRollback()` pattern exactly.
     */
    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function cleanUpDurableFirmAuditTrailAfterRollback(Firm $firm): void
    {
        $this->beforeApplicationDestroyed(function () use ($firm) {
            $connection = DB::connection('pgsql_audit');

            $connection->transaction(function () use ($connection, $firm) {
                $connection->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                TimelineEvent::on('pgsql_audit')->where('firm_id', $firm->id)->delete();
            });

            Firm::on('pgsql_audit')->where('id', $firm->id)->delete();
        });
    }

    private function connection(Firm $firm): FirmIntegration
    {
        return $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
    }

    private function execute(
        Firm $firm,
        FirmIntegration $connection,
        \Closure $providerCall,
        string $product = 'statements',
        string $billingOperation = 'download',
        ?string $accountId = null,
    ) {
        return $this->pipeline->execute(
            providerKey: ProviderKey::Plaid,
            connection: $connection,
            firm: $firm,
            actor: null,
            product: $product,
            billingOperation: $billingOperation,
            environment: 'production',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            providerCall: $providerCall,
            usageIdempotencyKey: 'test:'.Str::uuid7(),
            accountId: $accountId,
        );
    }

    private function successCall(): \Closure
    {
        return fn () => ['statement_id' => 'stmt_123'];
    }

    // ------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------

    public function test_a_successful_call_finalizes_billable_and_returns_the_response(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);

        $result = $this->execute($firm, $connection, $this->successCall());

        $this->assertSame(['statement_id' => 'stmt_123'], $result->response);
        $this->assertTrue($result->outcome->certain);
        $this->assertTrue($result->outcome->billable);
        $this->assertNotNull($result->reservation);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $result->reservation->status);
        $this->assertFalse($result->softLimitExceeded);
    }

    // ------------------------------------------------------------
    // Kill switches
    // ------------------------------------------------------------

    public function test_a_platform_product_kill_switch_blocks_the_call_and_records_an_audit_event(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'statements',
            'suspended' => true,
        ]);

        try {
            $this->execute($firm, $connection, $this->successCall());
            $this->fail('Expected ProviderKillSwitchActiveException.');
        } catch (ProviderKillSwitchActiveException $e) {
            // expected
        }

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.kill_switch_denied')
            ->first());
        $this->assertNotNull($event, 'Expected a kill_switch_denied audit event to be recorded.');

        $reservationCount = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->count());
        $this->assertSame(0, $reservationCount);
    }

    public function test_a_firms_own_optional_operation_suspension_blocks_the_call_and_records_an_audit_event(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $this->createWithFirmContext($firm, fn () => ProviderFirmOperationPolicy::query()->create([
            'firm_id' => $firm->id,
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'optional_operation_suspended' => true,
        ]));

        try {
            $this->execute($firm, $connection, $this->successCall());
            $this->fail('Expected ProviderOptionalOperationSuspendedException.');
        } catch (ProviderOptionalOperationSuspendedException $e) {
            // expected
        }

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.kill_switch_denied')
            ->first());
        $this->assertNotNull($event, 'Expected a kill_switch_denied audit event to be recorded for the firm suspension path too.');
    }

    // ------------------------------------------------------------
    // Soft / hard limits
    // ------------------------------------------------------------

    public function test_exceeding_the_soft_limit_proceeds_but_flags_the_result_and_audits(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'soft_limit_quantity' => 0,
            'hard_limit_quantity' => 5,
        ]);

        $result = $this->execute($firm, $connection, $this->successCall());

        $this->assertTrue($result->softLimitExceeded);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $result->reservation->status);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.soft_limit_exceeded')
            ->first());
        $this->assertNotNull($event);
    }

    public function test_exceeding_the_hard_limit_blocks_the_call_and_creates_no_reservation(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'hard_limit_quantity' => 0,
        ]);

        try {
            $this->execute($firm, $connection, $this->successCall());
            $this->fail('Expected ProviderHardLimitExceededException.');
        } catch (ProviderHardLimitExceededException $e) {
            // expected
        }

        $reservationCount = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()->count());
        $this->assertSame(0, $reservationCount);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.hard_limit_denied')
            ->first());
        $this->assertNotNull($event);
    }

    // ------------------------------------------------------------
    // Cache — a served-from-cache result is distinguishable from a real call
    // ------------------------------------------------------------

    public function test_a_second_call_for_a_cacheable_operation_is_served_from_cache_with_no_new_reservation(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'cache_ttl_seconds' => 300,
        ]);

        $callCount = 0;
        $providerCall = function () use (&$callCount) {
            $callCount++;

            return ['statement_id' => 'stmt_cached'];
        };

        // Both calls MUST use identical cache-key context, since the
        // response cache key includes it — pipeline callers do not
        // vary cacheKeyContext between calls for the same logical
        // operation.
        $first = $this->pipeline->execute(
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
            usageIdempotencyKey: 'cache-test-1:'.Str::uuid7(),
        );

        $second = $this->pipeline->execute(
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
            usageIdempotencyKey: 'cache-test-2:'.Str::uuid7(),
        );

        $this->assertSame(1, $callCount, 'The real provider call must only run once — the second call must be served from cache.');
        $this->assertNotNull($first->reservation);
        $this->assertNull($second->reservation, 'A cache-served call must never carry a reservation.');
        $this->assertSame('served_from_cache', $second->outcome->category);
        $this->assertTrue($second->outcome->certain);
        $this->assertFalse($second->outcome->billable);
        $this->assertSame($first->response, $second->response);
    }

    // ------------------------------------------------------------
    // Cooldown
    // ------------------------------------------------------------

    public function test_cooldown_starts_on_a_successful_call_and_blocks_an_immediate_second_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'cooldown_seconds' => 120,
        ]);

        $first = $this->execute($firm, $connection, $this->successCall(), 'statements', 'download', 'account-1');
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $first->reservation->status);

        try {
            $this->execute($firm, $connection, $this->successCall(), 'statements', 'download', 'account-1');
            $this->fail('Expected ProviderCooldownActiveException.');
        } catch (ProviderCooldownActiveException $e) {
            $this->assertGreaterThan(0, $e->remainingSeconds);
        }
    }

    public function test_a_non_billable_outcome_never_starts_a_cooldown(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        ProviderOperationDefaultPolicy::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'product' => 'statements',
            'environment' => 'production',
            'cooldown_seconds' => 120,
        ]);

        $rejecting = function () {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED, 422, 'downloadStatement');
        };

        try {
            $this->execute($firm, $connection, $rejecting);
            $this->fail('Expected SanitizedProviderHttpException to propagate.');
        } catch (SanitizedProviderHttpException $e) {
            // expected — the pipeline finalizes then rethrows unchanged.
        }

        // A second call for the same capability must NOT be blocked by
        // a cooldown, since the first outcome was non-billable.
        $second = $this->execute($firm, $connection, $this->successCall());
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $second->reservation->status);
    }

    // ------------------------------------------------------------
    // Uncertain outcome
    // ------------------------------------------------------------

    public function test_a_timeout_produces_an_uncertain_reservation_and_rethrows_the_original_exception(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);

        $timingOut = function () {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'downloadStatement');
        };

        $reservationId = null;

        try {
            $this->execute($firm, $connection, $timingOut);
            $this->fail('Expected SanitizedProviderHttpException to propagate.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_TIMEOUT, $e->category());
        }

        $reservation = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('firm_id', $firm->id)->latest('id')->first());

        $this->assertNotNull($reservation);
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_UNCERTAIN, $reservation->status);
        $this->assertNull($reservation->usage_record_id, 'An uncertain outcome must never write a real usage record.');
    }

    // ------------------------------------------------------------
    // Concurrent duplicate request denial — pipeline-level proof
    // ------------------------------------------------------------

    public function test_a_reentrant_call_for_the_identical_capability_while_the_first_still_holds_the_lock_is_denied(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);

        // The outer call's own providerCall closure recursively invokes
        // the pipeline AGAIN for the identical (connection, product,
        // billingOperation) capability, from inside the exact window
        // (steps 10-15) where the outer call's pre-flight lock is still
        // held — precisely the shape a genuine second concurrent
        // request (double-click, second browser tab) would race into.
        $reentrantCall = function () use ($firm, $connection) {
            $this->execute($firm, $connection, $this->successCall());

            return ['statement_id' => 'outer'];
        };

        $this->expectException(ProviderDuplicateRequestInFlightException::class);

        $this->execute($firm, $connection, $reentrantCall);
    }

    // ------------------------------------------------------------
    // Confirmation-required classification
    // ------------------------------------------------------------

    public function test_balance_get_without_a_confirmation_token_is_rejected_before_any_provider_call(): void
    {
        $firm = $this->firmWithEntitlement();
        $connection = $this->connection($firm);
        $called = false;

        $this->expectException(RuntimeException::class);

        try {
            $this->execute($firm, $connection, function () use (&$called) {
                $called = true;

                return [];
            }, 'balance', 'get');
        } finally {
            $this->assertFalse($called, 'The provider call must never run when a required confirmation token is missing.');
        }
    }
}
