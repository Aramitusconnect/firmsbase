<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConflict;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PushSyncJob;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PushSyncJobTest — Checkpoint 8 (agent-8c-sync-job-design.md §1/§8-§10;
 * agent-8h-architecture-security-review.md §4.2). firstOrCreate-shaped
 * mapping via recordMapping(); the new refreshVersionTokens() called on
 * a successful re-push of an already-mapped resource; stale local
 * version rejected; conflict created on version mismatch, never a
 * silent overwrite.
 */
class PushSyncJobTest extends TestCase
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
     * Registers a deterministic fake push provider under
     * ProviderKey::Test. Returns the underlying provider instance so a
     * test can inspect $provider->calls to prove (or disprove) push()
     * was ever invoked.
     */
    private function registerFakePushProvider(?string $externalId = null, ?string $versionToken = null, bool $shouldFail = false): object
    {
        $provider = new class($externalId, $versionToken, $shouldFail) implements IntegrationProviderContract, SupportsPushSyncContract {
            public array $calls = [];

            public function __construct(
                private readonly ?string $externalId,
                private readonly ?string $versionToken,
                private readonly bool $shouldFail,
            ) {
            }

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Push Provider';
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

            public function pushableResourceTypes(): array
            {
                return ['contact'];
            }

            public function push(array $context, string $resourceType, array $payload): array
            {
                $this->calls[] = $payload;

                if ($this->shouldFail) {
                    throw new SimulatedProviderFailureException('provider_rejected', 502, 'Simulated fixture failure.');
                }

                return [
                    'external_id' => $this->externalId ?? 'fake-generated-external-id',
                    'version_token' => $this->versionToken ?? 'fake-generated-version-token',
                    'status' => 'synced',
                ];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);

        return $provider;
    }

    private function dispatchPush(FirmIntegration $connection, int $firmId, string $localType, int $localId, string $localVersionToken): void
    {
        $job = new PushSyncJob($connection->id, $firmId, 'contact', $localType, $localId, $localVersionToken);
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }

    private function latestRun(Firm $firm, FirmIntegration $connection): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
    }

    // ------------------------------------------------------------
    // firstOrCreate-shaped mapping via recordMapping()
    // ------------------------------------------------------------

    public function test_a_first_time_push_creates_exactly_one_mapping_via_record_mapping(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePushProvider('ext-new-1', 'v1');

        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 501, 'local-v1');

        $mappings = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 501)
            ->get());

        $this->assertCount(1, $mappings);
        $this->assertSame('ext-new-1', $mappings->first()->external_id);
        $this->assertSame('v1', $mappings->first()->external_version_token);
        $this->assertSame('local-v1', $mappings->first()->local_version_token);
    }

    public function test_a_first_time_push_never_calls_create_directly_it_goes_through_the_sole_writer_service(): void
    {
        // Structural companion to the above: two consecutive pushes for
        // the SAME (connection, resource_type, local_type, local_id)
        // with an unchanged local_version_token must never produce a
        // second row — proving the write path is firstOrCreate-shaped
        // (never a bare create()).
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->registerFakePushProvider('ext-idem-1', 'v1');
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 502, 'local-v1');

        $this->registerFakePushProvider('ext-idem-1', 'v1');
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 502, 'local-v1');

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 502)
            ->count());

        $this->assertSame(1, $mappingCount);
    }

    public function test_the_run_is_marked_succeeded_on_a_first_time_push(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePushProvider('ext-run-ok', 'v1');

        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 503, 'local-v1');

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('succeeded', $run->status);
    }

    // ------------------------------------------------------------
    // refreshVersionTokens() called on a successful re-push of an
    // already-mapped resource
    // ------------------------------------------------------------

    public function test_a_successful_re_push_of_an_already_mapped_resource_refreshes_its_version_tokens(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 601,
                'external_id' => 'ext-existing-601',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v1',
            ]));

        // A legitimate, newer local edit -> local_version_token changes
        // (this must be ACCEPTED, not treated as stale, since the
        // mapping's own stored local_version_token still matches what
        // was last recorded — 'local-v1' — until refreshVersionTokens()
        // updates it below).
        $this->registerFakePushProvider('ext-existing-601', 'ext-v2');
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 601, 'local-v1');

        $fresh = $this->runWithFirmContext($firm, fn () => $mapping->fresh());

        $this->assertSame('ext-v2', $fresh->external_version_token, 'refreshVersionTokens() must have updated the EXISTING row\'s external_version_token to the provider\'s fresh value.');
        $this->assertSame($mapping->id, $fresh->id, 'The SAME mapping row must be refreshed — never a second, duplicate row.');

        $mappingCount = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 601)
            ->count());
        $this->assertSame(1, $mappingCount, 'refreshVersionTokens() must never create a second mapping row.');
    }

    public function test_a_re_push_passes_the_existing_external_id_to_the_provider_as_an_update_not_a_blind_create(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 602,
                'external_id' => 'ext-known-602',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v1',
            ]));

        $provider = $this->registerFakePushProvider('ext-known-602', 'ext-v2');
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 602, 'local-v1');

        $this->assertCount(1, $provider->calls);
        $this->assertSame('ext-known-602', $provider->calls[0]['existing_external_id'], 'The re-push payload must carry the ALREADY-KNOWN external_id, so a real provider could apply it as an update rather than a blind duplicate create.');
    }

    // ------------------------------------------------------------
    // Stale local version rejected
    // ------------------------------------------------------------

    public function test_a_stale_local_version_is_rejected_and_the_provider_is_never_called(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 701,
                'external_id' => 'ext-701',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v2-current',
            ]));

        $provider = $this->registerFakePushProvider('should-not-be-used', 'should-not-be-used');

        // This job's own $localVersionToken ('local-v1-stale') disagrees
        // with the mapping's already-stored 'local-v2-current' — proving
        // something else moved the local record more recently than this
        // job's own view of it.
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 701, 'local-v1-stale');

        $this->assertCount(0, $provider->calls, 'A stale local version must be rejected BEFORE the provider is ever called — never push anyway.');
    }

    public function test_a_stale_local_version_leaves_the_mapping_completely_untouched(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 702,
                'external_id' => 'ext-702',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v2-current',
            ]));

        $this->registerFakePushProvider('should-not-be-used', 'should-not-be-used');
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 702, 'local-v1-stale');

        $fresh = $this->runWithFirmContext($firm, fn () => $mapping->fresh());
        $this->assertSame('ext-v1', $fresh->external_version_token, 'A rejected stale push must never overwrite the mapping\'s external_version_token.');
        $this->assertSame('local-v2-current', $fresh->local_version_token, 'A rejected stale push must never overwrite the mapping\'s local_version_token.');
    }

    public function test_a_stale_local_version_marks_the_item_skipped_and_the_run_partial_failure(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 703,
                'external_id' => 'ext-703',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v2-current',
            ]));

        $this->registerFakePushProvider();
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 703, 'local-v1-stale');

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('partial_failure', $run->status);
        $this->assertSame('stale_local_version_push_rejected', $run->error_summary);

        $item = $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::query()
            ->where('sync_run_id', $run->id)
            ->first());
        $this->assertSame('skipped', $item->status->value);
    }

    // ------------------------------------------------------------
    // Conflict created on version mismatch, never a silent overwrite
    // ------------------------------------------------------------

    public function test_a_version_mismatch_creates_an_explicit_conflict_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 801,
                'external_id' => 'ext-801',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-current',
            ]));

        $this->registerFakePushProvider();
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 801, 'local-stale');

        $conflicts = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_type', 'App\\Models\\Contact')
            ->where('local_id', 801)
            ->get());

        $this->assertCount(1, $conflicts);
        $this->assertSame('stale_local_version_push_rejected', $conflicts->first()->conflict_type);
        $this->assertSame('local-stale', $conflicts->first()->local_version_token);
        $this->assertSame('ext-v1', $conflicts->first()->external_version_token);
    }

    public function test_a_repeated_stale_push_of_the_same_disagreement_does_not_duplicate_the_conflict_row(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);

        $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 802,
                'external_id' => 'ext-802',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-current',
            ]));

        $this->registerFakePushProvider();
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 802, 'local-stale');
        $this->registerFakePushProvider();
        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 802, 'local-stale');

        $conflictCount = $this->runWithFirmContext($firm, fn () => IntegrationConflict::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 802)
            ->count());

        $this->assertSame(1, $conflictCount, 'recordDetection()\'s own ON CONFLICT ... DO NOTHING must keep a redetected still-open conflict a safe no-op, never a duplicate.');
    }

    // ------------------------------------------------------------
    // Sanitized failure handling — no raw provider text ever persisted
    // ------------------------------------------------------------

    public function test_a_provider_rejection_is_recorded_with_only_the_sanitized_category_never_raw_text(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->connection($firm);
        $this->registerFakePushProvider(shouldFail: true);

        $this->dispatchPush($connection, $firm->id, 'App\\Models\\Contact', 901, 'local-v1');

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('provider_rejected', $run->error_summary);
        $this->assertStringNotContainsString('Simulated fixture failure', $run->error_summary);
    }

    // ------------------------------------------------------------
    // CHECKPOINT 12 addition (frozen-design-post-security-review.md §8):
    // genuine App\Integrations\Providers\TestProvider\TestProvider (not
    // this file's own registerFakePushProvider() double above),
    // registered exactly the way production config/integrations.php
    // would, exercised through this job's real F2 $providerContext
    // constructor parameter.
    //
    // RESOLVED DEVIATION (previously disclosed here as a workaround,
    // now fixed): this block used to explain that providerContext
    // ['__simulate_failure'] was structurally unreachable —
    // TestProvider::push()'s FAILURE_SENTINEL/RATE_LIMIT_SENTINEL checks
    // read $payload['__simulate_failure'], never $context, and
    // PushSyncJob::handle() only ever passed $providerContext into
    // push()'s $context argument, never merging it into $payload. That
    // gap is now closed in App\Jobs\PushSyncJob::handle(): it routes
    // providerContext['__simulate_failure'] into $payload before
    // calling push() (the ONE key push() actually reads off $payload),
    // so the sentinel below now genuinely reaches and throws inside
    // genuine TestProvider, driven end-to-end through a real,
    // unmodified PushSyncJob dispatch — no fake provider, no manual
    // orchestration.
    // ------------------------------------------------------------

    public function test_a_provider_context_failure_sentinel_dispatched_through_a_real_push_sync_job_produces_a_genuine_sanitized_failure(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();

        try {
            $firm = Firm::factory()->create();
            $connection = $this->connection($firm);

            $this->dispatchPushWithProviderContext($connection, $firm->id, 'contact', 'App\\Models\\Contact', 960, 'local-v1', ['__simulate_failure' => TestProvider::FAILURE_SENTINEL]);

            $run = $this->latestRun($firm, $connection);
            $this->assertSame('failed', $run->status, 'A real PushSyncJob dispatch carrying providerContext[\'__simulate_failure\'] => FAILURE_SENTINEL must now genuinely reach and throw inside TestProvider::push(), routed through $payload by this checkpoint\'s fix.');
            $this->assertStringContainsString('provider_rejected', $run->error_summary);
            $this->assertStringNotContainsString('Simulated provider failure', $run->error_summary, 'The sanitized error_summary persisted by the job must never contain TestProvider\'s own raw, internal exception message.');

            $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
                ->where('firm_integration_id', $connection->id)
                ->where('local_id', 960)
                ->first());
            $this->assertNull($mapping, 'A genuinely failed push must never create a mapping row.');
        } finally {
            TestProvider::resetSimulationState();
        }
    }

    // ------------------------------------------------------------
    // RESOLVED DEVIATION continued: F4's idempotency-key dedup, now
    // proven via the job's OWN always-computed idempotency_key (see
    // PushSyncJob::handle()'s own deterministic-hash docblock) rather
    // than a manually-injected providerContext override. This supersedes
    // this file's ORIGINAL Checkpoint 12 addition here, which (per the
    // resolved deviation above) could only prove dedup via a
    // test-injected providerContext['idempotency_key'] — a channel real
    // production dispatch never populates.
    // TestProvider::push() now reads $payload['idempotency_key'] FIRST
    // (falling back to $context only for that narrower
    // test-harness-only override case — see TestProvider::push()'s own
    // docblock), so two real dispatches of the exact same (connection,
    // resource_type, local_type, local_id, local_version_token), with NO
    // providerContext involved at all, must now dedupe purely from the
    // job's own real computed key.
    // ------------------------------------------------------------

    public function test_two_dispatches_of_the_same_local_record_at_the_same_version_dedupe_via_the_jobs_own_computed_idempotency_key(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();

        try {
            $firm = Firm::factory()->create();
            $connection = $this->connection($firm);

            $this->dispatchPushWithProviderContext($connection, $firm->id, 'contact', 'App\\Models\\Contact', 950, 'local-v1', null);

            $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
                ->where('firm_integration_id', $connection->id)
                ->where('local_id', 950)
                ->first());
            $this->assertNotNull($mapping);
            $firstExternalVersionToken = $mapping->external_version_token;

            // A second, independent dispatch for the EXACT SAME
            // (connection, resource_type, local_type, local_id,
            // local_version_token) — e.g. a re-delivered/re-processed
            // job for an unchanged local record. PushSyncJob::handle()
            // computes the SAME deterministic idempotency_key both times
            // purely from these five values — no providerContext at
            // all. refreshVersionTokens() overwrites
            // external_version_token with WHATEVER genuine TestProvider
            // returns on this call: if dedup did NOT fire, TestProvider's
            // fresh Str::random(12) would (with overwhelming probability)
            // differ from the first call's token; if dedup DID fire, it
            // is byte-for-byte identical.
            $this->dispatchPushWithProviderContext($connection, $firm->id, 'contact', 'App\\Models\\Contact', 950, 'local-v1', null);

            $fresh = $this->runWithFirmContext($firm, fn () => $mapping->fresh());
            $this->assertSame(
                $firstExternalVersionToken,
                $fresh->external_version_token,
                'A second real PushSyncJob dispatch for the identical local record/version must compute the IDENTICAL idempotency_key as the first, so genuine TestProvider must return its cached response (same external_version_token) rather than a freshly generated one — proving F4\'s dedup fired via the job\'s own real computed key, with zero providerContext involved.'
            );
        } finally {
            TestProvider::resetSimulationState();
        }
    }

    private function dispatchPushWithProviderContext(FirmIntegration $connection, int $firmId, string $resourceType, string $localType, int $localId, string $localVersionToken, ?array $providerContext): void
    {
        // PushSyncJob's constructor now declares providerContext as
        // ?string (JobConstructorsCarryOnlyScalarSecretSafeTypesTest —
        // no ShouldQueue constructor may carry an array-typed
        // parameter), so this helper's own ?array param is JSON-encoded
        // here before reaching the job.
        $job = new PushSyncJob($connection->id, $firmId, $resourceType, $localType, $localId, $localVersionToken, providerContext: $providerContext !== null ? json_encode($providerContext) : null);
        $job->handle(
            app(SyncRunService::class),
            app(SyncItemService::class),
            app(IntegrationExternalMappingService::class),
            app(IntegrationConflictService::class),
            app(ProviderRegistry::class),
            app(OutboundProviderHttpClient::class),
        );
    }
}
