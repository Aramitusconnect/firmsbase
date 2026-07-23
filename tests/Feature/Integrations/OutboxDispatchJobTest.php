<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationOutboxEvent;
use App\Integrations\Outbox\OutboxEventHandlerRegistry;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationOutboxEventService;
use App\Jobs\OutboxDispatchJob;
use App\Models\Firm;
use App\Services\WebhookRetryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OutboxDispatchJobTest — Checkpoint 8 (agent-8b-outbox-dispatch-design.md;
 * agent-8h-architecture-security-review.md §4.2). Claim-and-dispatch
 * happy path via the registered TestResourcePushHandler; unmapped
 * event_type -> UnknownOutboxEventTypeException handled as a
 * categorized permanent failure; a handler throwing
 * OutboxHandlerTransientException -> fail() with a retryable category;
 * a handler throwing OutboxHandlerPermanentException -> immediate
 * dead-letter via the new $category param; a handler throwing
 * OutboxHandlerReleaseException -> release() not fail(); the
 * HealthStateService call-site fires with the correct mapped method
 * whenever firm_integration_id is non-null, and does NOT fire when
 * it's null (8B's confirmed-nullable-column finding).
 */
class OutboxDispatchJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    private function connection(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    private function pushRetryEvent(Firm $firm, ?FirmIntegration $connection, array $fields = []): IntegrationOutboxEvent
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->when($connection !== null, fn ($f) => $f->forFirmIntegration($connection))
            ->when($connection === null, fn ($f) => $f->withoutFirmIntegration()->state(['firm_id' => $firm->id]))
            ->create([
                'event_type' => 'test.resource.push_retry',
                'max_attempts' => 10,
                'payload_json' => [
                    'resource_type' => 'contact',
                    'resource_id' => null,
                    'fields' => array_merge(['local_type' => 'App\\Models\\Contact', 'local_id' => 123], $fields),
                ],
            ]));
    }

    private function runJob(Firm $firm): void
    {
        $job = new OutboxDispatchJob($firm->id, 25);
        $job->handle(app(OutboxEventHandlerRegistry::class), app(IntegrationOutboxEventService::class), app(HealthStateService::class));
    }

    private function eventStatus(Firm $firm, int $eventId): object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_outbox_events')->where('id', $eventId)->first());
    }

    private function healthRow(Firm $firm, int $connectionId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connectionId)->first());
    }

    // ------------------------------------------------------------
    // Happy path — claim-and-dispatch via the registered
    // TestResourcePushHandler
    // ------------------------------------------------------------

    public function test_a_valid_push_retry_event_is_claimed_dispatched_and_completed(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_a_successful_dispatch_records_an_external_mapping(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 123)
            ->count());

        $this->assertSame(1, $mappingCount);
    }

    public function test_a_successful_dispatch_does_not_record_a_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        // recordHealthSignal() is only wired into the failure branches
        // of dispatchOne() — a successful dispatch calls complete()
        // only, never any HealthStateService method.
        $this->assertNull($this->healthRow($firm, $connection->id));
    }

    // ------------------------------------------------------------
    // Unmapped event_type -> UnknownOutboxEventTypeException ->
    // categorized permanent failure (dead-letter at attempts=1)
    // ------------------------------------------------------------

    public function test_an_unmapped_event_type_is_dead_lettered_immediately_as_a_permanent_failure(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->create(['event_type' => 'no.such.handler.registered', 'max_attempts' => 50]));

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('dead_lettered', $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts, 'An unmapped event_type must dead-letter on the FIRST attempt, never burn attempts against a handler that can never exist.');
        $this->assertSame('unknown_outbox_event_type', $fresh->last_error);
    }

    public function test_an_unmapped_event_type_does_not_record_a_health_signal(): void
    {
        // dispatchOne()'s UnknownOutboxEventTypeException catch branch
        // calls fail() only — it does NOT call recordHealthSignal() at
        // all (confirmed by direct read of OutboxDispatchJob::dispatchOne()).
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->runWithFirmContext($firm, fn () => IntegrationOutboxEvent::factory()
            ->forFirmIntegration($connection)
            ->create(['event_type' => 'no.such.handler.registered']));

        $this->runJob($firm);

        $this->assertNull($this->healthRow($firm, $connection->id));
    }

    // ------------------------------------------------------------
    // Handler throws OutboxHandlerTransientException -> fail() with a
    // retryable (non-terminal) category
    // ------------------------------------------------------------

    public function test_a_transient_provider_failure_is_retried_not_dead_lettered(): void
    {
        $this->assertNotContains('provider_rejected', WebhookRetryPolicyService::TERMINAL_CATEGORIES);

        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->pushRetryEvent($firm, $connection, ['__simulate_failure' => TestProvider::FAILURE_SENTINEL]);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('pending', $fresh->status, 'A transient (non-terminal) category must retry, never dead-letter on the first attempt.');
        $this->assertNull($fresh->dead_lettered_at);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertStringContainsString('provider_rejected', $fresh->last_error);
    }

    public function test_a_transient_failure_records_a_health_signal_mapped_to_provider_error(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->pushRetryEvent($firm, $connection, ['__simulate_failure' => TestProvider::FAILURE_SENTINEL]);

        $this->runJob($firm);

        $health = $this->healthRow($firm, $connection->id);
        $this->assertNotNull($health);
        $this->assertSame('provider_error', $health->last_failure_category, 'provider_rejected falls through recordHealthSignal()\'s match(true) default arm -> recordProviderError().');
    }

    // ------------------------------------------------------------
    // Handler throws OutboxHandlerPermanentException -> immediate
    // dead-letter via the new $category param
    // ------------------------------------------------------------

    public function test_a_malformed_payload_is_dead_lettered_immediately_as_permanent(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        // local_id as a non-int (string) triggers the handler's
        // malformed-payload guard -> OutboxHandlerPermanentException(validation_failed).
        $event = $this->pushRetryEvent($firm, $connection, ['local_id' => 'not-an-integer']);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('dead_lettered', $fresh->status);
        $this->assertSame(1, (int) $fresh->attempts);
        $this->assertStringContainsString('test_resource_push_malformed_payload', $fresh->last_error);
    }

    public function test_a_permanent_failure_records_a_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->pushRetryEvent($firm, $connection, ['local_id' => 'not-an-integer']);

        $this->runJob($firm);

        $health = $this->healthRow($firm, $connection->id);
        $this->assertNotNull($health);
        $this->assertSame('provider_error', $health->last_failure_category, 'validation_failed is not rate_limited/credential/scope -> falls to the default recordProviderError() arm.');
    }

    public function test_a_permanent_failure_with_no_connection_at_all_is_dead_lettered_and_records_no_health_signal(): void
    {
        // firm_integration_id === null on the outbox row itself ->
        // handler throws OutboxHandlerPermanentException(configuration_error)
        // immediately, before ever attempting a connection lookup, AND
        // recordHealthSignal() must short-circuit (8B's confirmed-
        // nullable-column finding) since $event->firm_integration_id is null.
        $firm = Firm::factory()->create();
        $event = $this->pushRetryEvent($firm, null);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('dead_lettered', $fresh->status);
        $this->assertStringContainsString('test_resource_push_requires_a_connection', $fresh->last_error);

        $anyHealthRows = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $anyHealthRows, 'When firm_integration_id is null, recordHealthSignal() must never call any HealthStateService method — 8B\'s confirmed-nullable-column finding.');
    }

    // ------------------------------------------------------------
    // Handler throws OutboxHandlerReleaseException -> release(), not
    // fail()
    // ------------------------------------------------------------

    public function test_a_non_active_connection_releases_the_event_rather_than_failing_it(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm, ConnectionStatus::Pending);
        $event = $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('pending', $fresh->status, 'release() re-enters the pool as pending, same terminal-string as fail()\'s retry branch, but via a DIFFERENT code path — verified below by the absence of last_error/attempts-cost distinguishing signals.');
        $this->assertNull($fresh->lock_token);
        $this->assertNull($fresh->last_error, 'release() never records an error — a hallmark distinguishing it from fail()\'s retry branch, which always sets last_error.');
        $this->assertSame(1, (int) $fresh->attempts, 'release() does not decrement attempts, but this claim episode did cost exactly one attempt, same as any other claim.');
    }

    public function test_a_released_event_records_no_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm, ConnectionStatus::Pending);
        $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        $this->assertNull($this->healthRow($firm, $connection->id), 'release() is caught by its own dedicated branch in dispatchOne(), which never calls recordHealthSignal().');
    }

    // ------------------------------------------------------------
    // Health-signal category-mapping breadth
    // ------------------------------------------------------------

    public function test_a_provider_that_does_not_support_push_is_dead_lettered_as_configuration_error(): void
    {
        $this->assertContains('configuration_error', WebhookRetryPolicyService::TERMINAL_CATEGORIES);

        // Register a fake provider under ProviderKey::Test that
        // implements the root IntegrationProviderContract (so
        // ProviderRegistry::get()'s own return-type contract is
        // satisfied) but deliberately does NOT implement
        // SupportsPushSyncContract, forcing TestResourcePushHandler's
        // own configuration_error branch.
        $fakeProvider = new class implements IntegrationProviderContract
        {
            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Non-Push Provider';
            }

            public function description(): string
            {
                return 'Test-only fixture provider with no push-sync capability.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }
        };
        config(['integrations.providers' => [ProviderKey::Test->value => get_class($fakeProvider)]]);

        // Deliberately reverted below in a finally-equivalent tearDown()
        // call is unnecessary — RefreshDatabase/each test's own setUp()
        // resets config() state via the framework's container rebuild.
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $event = $this->pushRetryEvent($firm, $connection);

        $this->runJob($firm);

        $fresh = $this->eventStatus($firm, $event->id);
        $this->assertSame('dead_lettered', $fresh->status);
        $this->assertStringContainsString('test_resource_push_provider_does_not_support_push', $fresh->last_error);

        $health = $this->healthRow($firm, $connection->id);
        $this->assertNotNull($health);
        $this->assertSame('provider_error', $health->last_failure_category);
    }
}
