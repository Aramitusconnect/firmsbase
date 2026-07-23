<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Core\ProviderRegistry;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PullSyncJobTest — Checkpoint 8 (agent-8c-sync-job-design.md §1-§7;
 * agent-8h-architecture-security-review.md §4.2). Firm context
 * restored; wrong firm denied; disconnected connection denied; revoked
 * credential denied; cursor advances only on success; failure
 * preserves cursor; conflict created explicitly on disagreement;
 * duplicate operation idempotent.
 *
 * TestProvider's own pull() has no wiring in this checkpoint's shipped
 * PullSyncJob for deterministic/conflict simulation knobs (the job
 * always calls $provider->pull([], ...) with an empty, non-injectable
 * context array) — every test below that needs deterministic item
 * content (matching a known external_id, a known version_token
 * disagreement) registers a small, fully deterministic fake provider
 * via app()->instance(), exactly mirroring the technique already
 * established in OutboxDispatchJobTest for the same reason.
 */
class PullSyncJobTest extends TestCase
{
    use RefreshDatabase;

    private function connection(Firm $firm, ConnectionStatus $status = ConnectionStatus::Active): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => $status->value, 'external_account_id' => null]),
        );
    }

    /**
     * Registers a deterministic fake pull provider under ProviderKey::Test,
     * returning $itemsPerPage[<page index>] for each successive call
     * (cursor advances 0,1,2,...). $shouldFail forces every call to
     * throw a SimulatedProviderFailureException instead.
     */
    private function registerFakePullProvider(array $itemsPerPage = [], bool $shouldFail = false): void
    {
        $provider = new class($itemsPerPage, $shouldFail) implements IntegrationProviderContract, SupportsPullSyncContract {
            public function __construct(
                private readonly array $itemsPerPage,
                private readonly bool $shouldFail,
            ) {
            }

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Pull Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::None];
            }

            public function pullableResourceTypes(): array
            {
                return ['contact'];
            }

            public function pull(array $context, string $resourceType, ?string $cursor): array
            {
                if ($this->shouldFail) {
                    throw new SimulatedProviderFailureException('network_error', null, 'Simulated fixture failure.');
                }

                $pageIndex = $cursor === null ? 0 : (int) $cursor;
                $items = $this->itemsPerPage[$pageIndex] ?? [];
                $nextCursor = array_key_exists($pageIndex + 1, $this->itemsPerPage) ? (string) ($pageIndex + 1) : null;

                return ['items' => $items, 'next_cursor' => $nextCursor];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    private function dispatchPull(FirmIntegration $connection, int $firmId, string $resourceType = 'contact'): void
    {
        $job = new PullSyncJob($connection->id, $firmId, $resourceType);
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(SyncCursorService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }

    private function cursorFor(Firm $firm, FirmIntegration $connection, string $resourceType = 'contact'): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_cursors')
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $resourceType)
            ->where('sync_direction', SyncDirection::Inbound->value)
            ->first());
    }

    private function latestRun(Firm $firm, FirmIntegration $connection): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
    }

    // ------------------------------------------------------------
    // Firm context restored
    // ------------------------------------------------------------

    public function test_tenant_context_is_restored_after_a_successful_run(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePullProvider([]);

        $this->assertNoDatabaseTenantContext();
        $this->dispatchPull($connection, $firm->id);
        $this->assertNoDatabaseTenantContext();
    }

    public function test_tenant_context_is_restored_even_when_the_connection_is_not_found(): void
    {
        $firm = Firm::factory()->create();

        try {
            $job = new PullSyncJob(999999999, $firm->id, 'contact');
            $job->handle(
                app(SyncRunService::class), app(SyncItemService::class), app(SyncCursorService::class),
                app(IntegrationExternalMappingService::class), app(IntegrationConflictService::class),
                app(ProviderRegistry::class), app(OutboundProviderHttpClient::class),
            );
            $this->fail('Expected ModelNotFoundException.');
        } catch (ModelNotFoundException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext('Even when handle() throws, runWithFirmContext()\'s finally-cleanup must restore no-context.');
    }

    // ------------------------------------------------------------
    // Wrong firm denied
    // ------------------------------------------------------------

    public function test_a_connection_belonging_to_a_different_firm_is_denied(): void
    {
        $realOwner = Firm::factory()->create();
        $attacker = Firm::factory()->create();
        $connection = $this->connection($realOwner);

        $this->expectException(ModelNotFoundException::class);

        // Dispatch claims $connection belongs to $attacker's firm — the
        // job's own ->where('firm_id', $this->firmId) guard (plus RLS)
        // must find zero rows.
        $this->dispatchPull($connection, $attacker->id);
    }

    public function test_a_wrong_firm_dispatch_creates_no_sync_run(): void
    {
        $realOwner = Firm::factory()->create();
        $attacker = Firm::factory()->create();
        $connection = $this->connection($realOwner);

        try {
            $this->dispatchPull($connection, $attacker->id);
        } catch (ModelNotFoundException $e) {
            // expected
        }

        $runCount = $this->runWithFirmContext($realOwner, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $runCount);
    }

    // ------------------------------------------------------------
    // Disconnected connection denied
    // ------------------------------------------------------------

    public function test_a_disconnected_connection_is_denied_and_creates_no_run(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm, ConnectionStatus::Disconnected);
        $this->registerFakePullProvider([['contact-1' => 'irrelevant']]);

        $this->dispatchPull($connection, $firm->id);

        $runCount = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $runCount, 'A non-Active connection must be denied BEFORE any run is even created.');
    }

    public function test_a_pending_connection_is_denied(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm, ConnectionStatus::Pending);

        $this->dispatchPull($connection, $firm->id);

        $runCount = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $runCount);
    }

    // ------------------------------------------------------------
    // Revoked credential denied
    // ------------------------------------------------------------

    public function test_a_connection_with_only_revoked_credentials_does_not_complete_a_successful_sync(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        // Every credential for this connection is Revoked — none
        // Active — while the connection's own status is left Active.
        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationCredential::factory()->forFirmIntegration($connection)->ofType(CredentialType::OauthAccessToken)->revoked()->create();
            IntegrationCredential::factory()->forFirmIntegration($connection)->ofType(CredentialType::OauthRefreshToken)->revoked()->create();
        });

        $activeCredentialCount = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(0, $activeCredentialCount, 'Sanity check: zero Active credentials must exist for this test to mean anything.');

        $this->registerFakePullProvider([[['external_id' => 'ext-revoked-cred', 'version_token' => 'v1']]]);

        $this->dispatchPull($connection, $firm->id);

        $run = $this->latestRun($firm, $connection);
        $this->assertNotNull($run, 'The run is created (connection status alone gates dispatch) — this test documents the actual credential-check boundary.');
        $this->assertNotSame(
            'succeeded',
            $run->status,
            'A connection with zero Active credentials of either OAuth type must not be able to complete a successful sync — revoked credentials must be denied somewhere in the pull path.'
        );
    }

    // ------------------------------------------------------------
    // Cursor advances only on success
    // ------------------------------------------------------------

    public function test_the_cursor_advances_after_a_successful_single_page_pull(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePullProvider([[['external_id' => 'ext-adv-1', 'version_token' => 'v1']]]);

        $before = $this->cursorFor($firm, $connection);
        $this->assertNull($before, 'No cursor should exist before the first dispatch.');

        $this->dispatchPull($connection, $firm->id);

        $after = $this->cursorFor($firm, $connection);
        $this->assertNotNull($after);
        $this->assertSame(1, (int) $after->cursor_version, 'cursor_version must increment exactly once for one successfully-advanced batch.');
        $this->assertSame('idle', $after->status);
    }

    public function test_the_cursor_advances_across_multiple_successful_pages(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePullProvider([
            [['external_id' => 'ext-p0', 'version_token' => 'v1']],
            [['external_id' => 'ext-p1', 'version_token' => 'v1']],
        ]);

        $this->dispatchPull($connection, $firm->id);

        $after = $this->cursorFor($firm, $connection);
        $this->assertSame(2, (int) $after->cursor_version, 'Two successfully-processed pages must advance the cursor twice.');
        $this->assertNull($after->cursor_value, 'The final page\'s next_cursor is null -> cursor_value ends null (no more pages).');
    }

    // ------------------------------------------------------------
    // Failure preserves cursor
    // ------------------------------------------------------------

    public function test_a_provider_failure_preserves_the_cursors_prior_successful_position(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        // Run 1: a genuine successful advance, establishing a
        // non-trivial prior position.
        $this->registerFakePullProvider([[['external_id' => 'ext-preserved', 'version_token' => 'v1']]]);
        $this->dispatchPull($connection, $firm->id);

        $afterSuccess = $this->cursorFor($firm, $connection);
        $this->assertSame(1, (int) $afterSuccess->cursor_version);

        // Run 2: the provider now fails outright on every call,
        // regardless of the cursor it's handed.
        $this->registerFakePullProvider([], shouldFail: true);
        $this->dispatchPull($connection, $firm->id);

        $afterFailure = $this->cursorFor($firm, $connection);
        $this->assertSame(
            (int) $afterSuccess->cursor_version,
            (int) $afterFailure->cursor_version,
            'A blocking provider failure must NEVER advance cursor_version — the prior successful position must be preserved exactly.'
        );
        $this->assertSame(
            $afterSuccess->cursor_value,
            $afterFailure->cursor_value,
            'cursor_value must likewise be preserved exactly across a failed run.'
        );
        $this->assertSame('failed', $afterFailure->status, 'The cursor\'s HEALTH must reflect the failure even though its VALUE is preserved.');
    }

    public function test_a_provider_failure_marks_the_run_failed_and_never_marks_it_succeeded(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePullProvider([], shouldFail: true);

        $this->dispatchPull($connection, $firm->id);

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    // ------------------------------------------------------------
    // Conflict created explicitly on disagreement
    // ------------------------------------------------------------

    public function test_a_version_token_disagreement_creates_an_explicit_conflict_never_a_silent_overwrite(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 4242,
                'external_id' => 'ext-conflict-1',
                'external_version_token' => 'v1-old',
            ]));

        $this->registerFakePullProvider([[['external_id' => 'ext-conflict-1', 'version_token' => 'v2-new']]]);

        $this->dispatchPull($connection, $firm->id);

        $conflicts = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 4242)
            ->get());

        $this->assertCount(1, $conflicts);
        $this->assertSame('remote_version_changed', $conflicts->first()->conflict_type);
        $this->assertSame('detected', $conflicts->first()->status->value);

        // The mapping's OWN external_version_token must NOT have been
        // silently overwritten to the new, disagreeing value.
        $freshMapping = $this->runWithFirmContext($firm, fn () => $mapping->fresh());
        $this->assertSame('v1-old', $freshMapping->external_version_token, 'A version disagreement must never silently overwrite the mapping\'s stored version token.');
    }

    public function test_no_conflict_is_created_when_the_version_token_agrees(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 7,
                'external_id' => 'ext-agree-1',
                'external_version_token' => 'same-version',
            ]));

        $this->registerFakePullProvider([[['external_id' => 'ext-agree-1', 'version_token' => 'same-version']]]);

        $this->dispatchPull($connection, $firm->id);

        $conflictCount = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $conflictCount);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->where('external_id', 'ext-agree-1')->first());
        $this->assertSame('succeeded', $item->status->value);
    }

    public function test_an_unmapped_external_item_is_recorded_skipped_never_a_silently_invented_local_record(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePullProvider([[['external_id' => 'ext-no-mapping', 'version_token' => 'v1']]]);

        $this->dispatchPull($connection, $firm->id);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()->where('external_id', 'ext-no-mapping')->first());
        $this->assertNotNull($item);
        $this->assertSame('skipped', $item->status->value);
        $this->assertNull($item->local_id);

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()->where('external_id', 'ext-no-mapping')->count());
        $this->assertSame(0, $mappingCount, 'PullSyncJob must never invent a local record/mapping on its own for an unresolved external item.');
    }

    // ------------------------------------------------------------
    // Duplicate operation idempotent
    // ------------------------------------------------------------

    public function test_the_same_external_id_pulled_across_two_separate_dispatches_never_duplicates_the_mapping(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 99,
                'external_id' => 'ext-dup-1',
                'external_version_token' => 'v1',
            ]));

        // First dispatch: the item agrees with the mapping's stored
        // version -> Succeeded, refreshVersionTokens() touches the SAME
        // row.
        $this->registerFakePullProvider([[['external_id' => 'ext-dup-1', 'version_token' => 'v1']]]);
        $this->dispatchPull($connection, $firm->id);

        // Second, independent dispatch of the identical logical
        // operation (SAME connection/resource_type/external_id/version) —
        // simulating a redelivered/duplicated pull trigger.
        $this->registerFakePullProvider([[['external_id' => 'ext-dup-1', 'version_token' => 'v1']]]);
        $this->dispatchPull($connection, $firm->id);

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()->where('external_id', 'ext-dup-1')->count());
        $this->assertSame(1, $mappingCount, 'Two dispatches of the identical logical pull operation must never create a second mapping row.');

        $conflictCount = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $conflictCount, 'A repeated identical-version pull must never be misclassified as a conflict.');
    }

    // ------------------------------------------------------------
    // Invalid cursor gate (requirement 4's fail-closed cursor check)
    // ------------------------------------------------------------

    public function test_an_invalid_cursor_refuses_an_ordinary_incremental_pull(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationSyncCursor::factory()
            ->forFirmIntegration($connection)
            ->invalid()
            ->create(['resource_type' => 'contact', 'sync_direction' => SyncDirection::Inbound->value]));

        $this->registerFakePullProvider([[['external_id' => 'ext-should-not-run', 'version_token' => 'v1']]]);

        $this->dispatchPull($connection, $firm->id);

        $runCount = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->count());
        $this->assertSame(0, $runCount, 'An Invalid cursor must refuse to start an ordinary (non-retry) run at all — fail closed.');
    }
}
