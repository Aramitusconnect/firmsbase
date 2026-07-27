<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DispatchPullSyncOnVerifiedWebhookEventTest — FirmsVault Live
 * Integrations, Checkpoint 2 (test-writer pass). Feature-level coverage
 * of the listener/job itself (checkpoint2-combined-design.md §2 P-21):
 * a verified inbound webhook event must genuinely dispatch a
 * PullSyncJob for a registered SupportsPullSyncContract provider — the
 * "verified webhook event never triggers sync" gap this class exists
 * to close.
 *
 * Uses TestProvider (ProviderKey::Test) rather than Microsoft365Provider
 * — this class is provider-agnostic by construction (resolves via
 * ProviderRegistry + instanceof SupportsPullSyncContract, never branches
 * on provider identity), and TestProvider's own pullableResourceTypes()
 * (['contact', 'task']) is a genuine ResourceType vocabulary sufficient
 * to exercise mapEventTypeToResourceType() exactly as
 * Microsoft365Provider's own event_type shape would
 * (Microsoft365ProviderWebhookTest covers the Microsoft-specific
 * parseInboundEvent() -> event_type derivation separately).
 *
 * Queue::fake() (this codebase's established convention — see
 * RecordWebhookVerificationFailureJobDispatchTest) proves PullSyncJob
 * was genuinely DISPATCHED (went through the queue mechanism), not
 * merely executed inline.
 */
final class DispatchPullSyncOnVerifiedWebhookEventTest extends TestCase
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

    private function connectionFor(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    private function dispatchListener(
        FirmIntegration $connection,
        int $firmId,
        ?string $eventType,
        int $webhookEventId = 999,
    ): void {
        $job = new DispatchPullSyncOnVerifiedWebhookEvent(
            $connection->id,
            $firmId,
            ProviderKey::Test->value,
            $eventType,
            $webhookEventId,
        );

        $job->handle(app(ProviderRegistry::class));
    }

    // ------------------------------------------------------------
    // Genuine dispatch for a mappable event_type
    // ------------------------------------------------------------

    public function test_a_mappable_event_type_genuinely_dispatches_pull_sync_job_with_the_correct_resource_type_and_triggering_event_id(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        $this->dispatchListener($connection, $firm->id, 'contact:created', webhookEventId: 4242);

        Queue::assertPushed(PullSyncJob::class, function (PullSyncJob $job) use ($connection, $firm): bool {
            return $job->firmIntegrationId === $connection->id
                && $job->firmId === $firm->id
                && $job->resourceType === 'contact'
                && $job->triggeringWebhookEventId === 4242;
        });
        Queue::assertPushed(PullSyncJob::class, 1);
    }

    public function test_an_exact_resource_type_event_type_with_no_delimiter_also_dispatches(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        $this->dispatchListener($connection, $firm->id, 'task', webhookEventId: 1);

        Queue::assertPushed(PullSyncJob::class, fn (PullSyncJob $job): bool => $job->resourceType === 'task');
    }

    public function test_a_resource_type_the_provider_does_not_declare_pullable_is_not_dispatched(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        // 'document' is a genuine ResourceType value, but TestProvider's
        // own pullableResourceTypes() is ['contact', 'task'] only.
        $this->dispatchListener($connection, $firm->id, 'document:created', webhookEventId: 1);

        Queue::assertNotPushed(PullSyncJob::class);
    }

    // ------------------------------------------------------------
    // Disconnected / non-Active connection -> silent no-op
    // ------------------------------------------------------------

    public function test_a_disconnected_connection_is_a_silent_no_op(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm, ConnectionStatus::Disconnected);

        $this->dispatchListener($connection, $firm->id, 'contact:created');

        Queue::assertNotPushed(PullSyncJob::class);
    }

    public function test_a_pending_connection_is_a_silent_no_op(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm, ConnectionStatus::Pending);

        $this->dispatchListener($connection, $firm->id, 'contact:created');

        Queue::assertNotPushed(PullSyncJob::class);
    }

    public function test_a_nonexistent_connection_is_a_silent_no_op_not_an_exception(): void
    {
        Queue::fake();

        $firm = Firm::factory()->create();

        $job = new DispatchPullSyncOnVerifiedWebhookEvent(999999999, $firm->id, ProviderKey::Test->value, 'contact:created', 1);
        $job->handle(app(ProviderRegistry::class));

        Queue::assertNotPushed(PullSyncJob::class);
    }

    // ------------------------------------------------------------
    // Unmapped event_type -> silent no-op with a log entry, no exception
    // ------------------------------------------------------------

    public function test_an_unmapped_event_type_logs_and_does_not_dispatch_or_throw(): void
    {
        Queue::fake();
        Log::spy();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        // "lifecycle:reauthorizationRequired"-shaped: its first segment
        // ("lifecycle") is not a ResourceType value.
        $this->dispatchListener($connection, $firm->id, 'lifecycle:reauthorizationRequired');

        Queue::assertNotPushed(PullSyncJob::class);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_a_null_event_type_logs_and_does_not_dispatch_or_throw(): void
    {
        Queue::fake();
        Log::spy();

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        $this->dispatchListener($connection, $firm->id, null);

        Queue::assertNotPushed(PullSyncJob::class);
        Log::shouldHaveReceived('info')->once();
    }

    // ------------------------------------------------------------
    // Provider not registered / does not support pull -> silent no-op
    // ------------------------------------------------------------

    public function test_an_unregistered_provider_key_is_a_silent_no_op(): void
    {
        Queue::fake();

        config(['integrations.providers' => []]);

        $firm = Firm::factory()->create();
        $connection = $this->connectionFor($firm);

        $this->dispatchListener($connection, $firm->id, 'contact:created');

        Queue::assertNotPushed(PullSyncJob::class);
    }
}
