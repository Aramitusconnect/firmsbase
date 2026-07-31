<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderOperationAttemptState;
use App\Integrations\Enums\ProviderOperationClaimDecision;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PushSyncJob;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\TenantContextService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\PurgesDurableProviderOperationAttempts;
use Tests\Support\NonBillablePushStubProvider;
use Tests\TestCase;
use Throwable;

/**
 * PushSyncJobDurableGateTest — Checkpoint 8.2 (§A-push), for the path
 * that matters most: the DIRECT provider call every real Microsoft 365 /
 * Google Workspace push makes.
 *
 * That path never went through `ProviderBillableCallPipeline` (only
 * Plaid implements `RequiresBillableCallPipelineContract`, and Plaid
 * never implements `SupportsPushSyncContract`), so before this checkpoint
 * it had no at-most-once protection whatsoever beyond a connection-row
 * lock held across the entire provider call — which itself created the
 * Checkpoint 8.1 deadlock shape and meant a push that succeeded at the
 * provider and then failed locally was simply pushed again.
 *
 * PushSyncJobTest / PushSyncJobRateLimitedRetryTest cover the
 * pre-existing mapping/conflict/retry-classification behavior, which
 * this checkpoint leaves unchanged; this file is deliberately about the
 * NEW durable at-most-once gate and the transaction/lock discipline
 * around it.
 */
class PushSyncJobDurableGateTest extends TestCase
{
    use PurgesDurableProviderOperationAttempts;
    use RefreshDatabase;

    private const DURABLE_CONNECTION = 'pgsql_audit';

    private NonBillablePushStubProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeDurableProviderOperationAttempts();

        $this->provider = new NonBillablePushStubProvider;
        $this->app->instance(NonBillablePushStubProvider::class, $this->provider);

        config(['integrations.providers' => [
            ProviderKey::Microsoft365->value => NonBillablePushStubProvider::class,
        ]]);
    }

    private function firm(): Firm
    {
        // Committed on the independent connection because the
        // pipeline-free gate still records its rows there; mirrors
        // RenewGraphSubscriptionJobDurableGateTest's identical fixture
        // discipline.
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();

        $this->beforeApplicationDestroyed(function () use ($firm) {
            $durable = DB::connection(self::DURABLE_CONNECTION);

            $durable->transaction(function () use ($durable, $firm) {
                $durable->statement('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firm->id, true]);
                $durable->table('timeline_events')->where('firm_id', $firm->id)->delete();
            });

            Firm::on(self::DURABLE_CONNECTION)->where('id', $firm->id)->delete();
        });

        return $firm;
    }

    private function connection(Firm $firm): FirmIntegration
    {
        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        return $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => null]));
    }

    /**
     * A Firm + FirmIntegration genuinely committed on the independent
     * `pgsql_audit` connection, so a real cross-session lock probe
     * (`FOR KEY SHARE NOWAIT` from that same connection) can see the row
     * at all — mirrors PullSyncJobConcurrencyBoundaryTest::committedConnection()'s
     * identical discipline. RefreshDatabase's own uncommitted default-
     * connection fixtures (this file's plain connection() helper above)
     * are invisible to a genuinely separate session, which is exactly
     * why only the two lock-probe tests below need this heavier fixture.
     *
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function committedConnection(): array
    {
        $firm = Firm::factory()->connection(self::DURABLE_CONNECTION)->create();

        $this->setProbeFirmContext((int) $firm->id);

        TenantEncryptionKey::factory()->connection(self::DURABLE_CONNECTION)->forFirm($firm)->create();

        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();

        $connection = FirmIntegration::factory()
            ->connection(self::DURABLE_CONNECTION)
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create([
                'status' => ConnectionStatus::Active->value,
                'external_account_id' => null,
                'connected_by_firm_user_id' => null,
            ]);

        $this->beforeApplicationDestroyed(function () use ($firm, $connection) {
            $probe = DB::connection(self::DURABLE_CONNECTION);
            $this->setProbeFirmContext((int) $firm->id);

            $probe->table('integration_external_mappings')->where('firm_integration_id', $connection->id)->delete();
            $probe->table('integration_sync_items')->whereIn('sync_run_id', $probe->table('integration_sync_runs')->where('firm_integration_id', $connection->id)->pluck('id'))->delete();
            $probe->table('integration_sync_runs')->where('firm_integration_id', $connection->id)->delete();
            $probe->table('timeline_events')->where('firm_id', $firm->id)->delete();
            $probe->table('firm_integrations')->where('id', $connection->id)->delete();
            $probe->table('tenant_encryption_keys')->where('firm_id', $firm->id)->delete();
            $probe->table('firms')->where('id', $firm->id)->delete();

            $this->clearProbeFirmContext();
        });

        return [$firm, $connection];
    }

    private function setProbeFirmContext(int $firmId): void
    {
        DB::connection(self::DURABLE_CONNECTION)->select('select set_config(?, ?, ?)', ['app.current_firm_id', (string) $firmId, false]);
    }

    private function clearProbeFirmContext(): void
    {
        DB::connection(self::DURABLE_CONNECTION)->select('select set_config(?, ?, ?)', ['app.current_firm_id', '', false]);
    }

    private function dispatchPush(FirmIntegration $connection, Firm $firm, int $localId, string $localVersionToken): ?Throwable
    {
        try {
            (new PushSyncJob($connection->id, $firm->id, 'contact', 'App\\Models\\Contact', $localId, $localVersionToken))
                ->handle(
                    app(SyncRunService::class),
                    app(SyncItemService::class),
                    app(IntegrationExternalMappingService::class),
                    app(IntegrationConflictService::class),
                    app(ProviderRegistry::class),
                    app(OutboundProviderHttpClient::class),
                );

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    private function attemptRow(Firm $firm): ?object
    {
        return DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('firm_id', $firm->id)
            ->orderByDesc('id')
            ->first();
    }

    private function mapping(Firm $firm, FirmIntegration $connection, int $localId): ?IntegrationExternalMapping
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', $localId)
            ->first());
    }

    private function latestRun(Firm $firm, FirmIntegration $connection): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
    }

    private function latestItem(Firm $firm, int $localId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')
            ->where('firm_id', $firm->id)
            ->where('local_id', $localId)
            ->orderByDesc('id')
            ->first());
    }

    /**
     * Makes the LOCAL apply fail after the provider has already
     * succeeded, by throwing from an Eloquent `creating` listener on the
     * mapping model — a real failure at a real point inside
     * applyPushResult()'s own transaction. Event-facade listeners are
     * per-test, so nothing leaks.
     */
    private function failTheLocalWrite(): void
    {
        Event::listen('eloquent.creating: '.IntegrationExternalMapping::class, function (): void {
            throw new RuntimeException('local mapping write exploded');
        });
    }

    private function stopFailingTheLocalWrite(): void
    {
        Event::forget('eloquent.creating: '.IntegrationExternalMapping::class);
    }

    // ------------------------------------------------------------------
    // End-to-end gate settlement
    // ------------------------------------------------------------------

    public function test_a_direct_push_is_gated_and_settles_end_to_end(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->assertNull($this->dispatchPush($connection, $firm, 101, 'local-v1'));
        $this->assertSame(1, $this->provider->pushCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertNotNull($attempt, 'The non-pipeline push path must still record a durable gate row.');
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $attempt->attempt_state);
        $this->assertSame(1, (int) $attempt->send_count);
        $this->assertSame('push_sync', $attempt->operation_type);

        $mapping = $this->mapping($firm, $connection, 101);
        $this->assertNotNull($mapping);
        $this->assertSame('push-stub-external-id', $mapping->external_id);

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('succeeded', $run->status);
    }

    public function test_a_repeated_dispatch_of_the_same_local_record_at_the_same_version_never_calls_the_provider_twice(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->dispatchPush($connection, $firm, 102, 'local-v1');
        $this->assertSame(1, $this->provider->pushCalls);

        // A re-delivered/re-processed dispatch for the exact same
        // (connection, resource_type, local_type, local_id,
        // local_version_token) — the job computes the identical
        // idempotency/logical-operation key both times.
        $this->assertNull($this->dispatchPush($connection, $firm, 102, 'local-v1'));

        $this->assertSame(
            1,
            $this->provider->pushCalls,
            'One logical push must produce at most one provider call, even when dispatched twice.'
        );

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('succeeded', $run->status, 'The duplicate dispatch must still settle its own run.');
    }

    public function test_a_push_that_succeeded_then_failed_locally_is_resumed_from_durable_evidence(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->failTheLocalWrite();
        $failure = $this->dispatchPush($connection, $firm, 103, 'local-v1');

        $this->assertNotNull($failure, 'The local failure must surface to the caller.');
        $this->assertSame(1, $this->provider->pushCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingFailed->value, $attempt->attempt_state);
        $this->assertSame(1, (int) $attempt->send_count, 'The send is on the record despite the local failure.');
        $this->assertNotNull($attempt->redacted_result_metadata, 'Recovery evidence must have been kept.');

        // The evidence holds ONLY the two non-secret fields this system
        // already stores in plaintext for this mapping.
        $evidence = json_decode((string) $attempt->redacted_result_metadata, true);
        $this->assertSame(['external_id', 'version_token'], array_keys($evidence));
        $this->assertSame('push-stub-external-id', $evidence['external_id']);

        // The retry, with a healthy local layer: resumed, not re-pushed.
        $this->stopFailingTheLocalWrite();
        $this->assertNull($this->dispatchPush($connection, $firm, 103, 'local-v1'));

        $this->assertSame(
            1,
            $this->provider->pushCalls,
            'The provider already did the work — a resume must never send again.'
        );

        $mapping = $this->mapping($firm, $connection, 103);
        $this->assertNotNull($mapping, 'The local mapping must end up carrying what the provider actually returned.');
        $this->assertSame('push-stub-external-id', $mapping->external_id);
        $this->assertSame(
            ProviderOperationAttemptState::LocalProcessingComplete->value,
            $this->attemptRow($firm)->attempt_state
        );

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('succeeded', $run->status, 'The resumed retry must finalize its own run as succeeded.');
    }

    public function test_an_ambiguous_provider_failure_demands_reconciliation_instead_of_pushing_again(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->provider->onPush = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'push');
        };

        $this->assertNull($this->dispatchPush($connection, $firm, 104, 'local-v1'), 'The first attempt must record the failure locally, never throw.');
        $this->assertSame(1, $this->provider->pushCalls);

        $attempt = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::ReconciliationRequired->value, $attempt->attempt_state);
        $this->assertStringContainsString('uncertain_provider_outcome:', (string) $attempt->reconciliation_reason);

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('partial_failure', $run->status, 'timeout is not a TERMINAL_CATEGORIES category, so the first attempt still lands PartialFailure locally.');

        // Every further attempt at the same logical operation is refused
        // loudly. A timeout may mean the push WAS applied, so pushing
        // again would risk a duplicate.
        $retryFailure = $this->dispatchPush($connection, $firm, 104, 'local-v1');

        $this->assertInstanceOf(ProviderOperationRequiresReconciliationException::class, $retryFailure);
        $this->assertSame(1, $this->provider->pushCalls);

        $retryRun = $this->latestRun($firm, $connection);
        $this->assertSame('failed', $retryRun->status, 'The retry that hits reconciliation_required must finalize its OWN run rather than leave it running.');
    }

    public function test_a_definite_provider_rejection_stays_retryable(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $attempts = 0;
        $this->provider->onPush = static function () use (&$attempts): array {
            $attempts++;

            if ($attempts === 1) {
                throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'push');
            }

            return ['external_id' => 'ext-after-retry', 'version_token' => 'v-after-retry'];
        };

        $this->assertNull($this->dispatchPush($connection, $firm, 105, 'local-v1'));
        $this->assertSame(ProviderOperationAttemptState::ProviderRejected->value, $this->attemptRow($firm)->attempt_state);

        $run = $this->latestRun($firm, $connection);
        $this->assertSame('partial_failure', $run->status);

        // A retry poller dispatch recovers: a 429 is positive knowledge
        // that nothing was pushed.
        $this->assertNull($this->dispatchPush($connection, $firm, 105, 'local-v1'));
        $this->assertSame(2, $this->provider->pushCalls);

        $final = $this->attemptRow($firm);
        $this->assertSame(ProviderOperationAttemptState::LocalProcessingComplete->value, $final->attempt_state);
        $this->assertSame(1, (int) $final->send_count, 'The new generation sent once.');
        $this->assertSame(2, (int) $final->total_send_count, 'Both sends stay on the record.');

        $mapping = $this->mapping($firm, $connection, 105);
        $this->assertSame('ext-after-retry', $mapping->external_id);
    }

    // ------------------------------------------------------------------
    // Stale apply cannot overwrite newer state
    // ------------------------------------------------------------------

    public function test_the_version_guarded_refresh_used_by_apply_refuses_to_overwrite_a_mapping_a_newer_push_already_advanced(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 106,
                'external_id' => 'ext-106',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v1',
            ]));

        // A NEWER push (a later local edit of the same record) completes
        // first and advances the mapping past what an OLDER, still-
        // in-flight push observed at its own claim time.
        $this->runWithFirmContext($firm, fn () => $mapping->update(['local_version_token' => 'local-v2-newer']));

        // The older push's own APPLY phase — PushSyncJob::applyPushResult()
        // — calls exactly this guarded method with exactly this
        // "previous" value (what its OWN claim observed before the
        // provider call). Testing the guard directly, through its real
        // public API, proves the mechanism PushSyncJob's apply phase
        // relies on without needing to fabricate genuine cross-process
        // interleaving.
        $refreshed = $this->runWithFirmContext($firm, fn () => app(IntegrationExternalMappingService::class)
            ->refreshVersionTokensIfCurrent($mapping->fresh(), 'local-v1', 'ext-v-stale', 'local-v1'));

        $this->assertNull($refreshed, 'The guard must refuse when the mapping has already moved past the expected previous version.');

        $fresh = $this->runWithFirmContext($firm, fn () => $mapping->fresh());
        $this->assertSame(
            'local-v2-newer',
            $fresh->local_version_token,
            'A stale apply must never regress local_version_token past what a newer push already recorded.'
        );
        $this->assertSame('ext-v1', $fresh->external_version_token, 'A stale apply must never overwrite external_version_token either.');

        // Positive control: the identical call succeeds when the
        // expected previous version is still genuinely current.
        $mapping2 = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 1106,
                'external_id' => 'ext-1106',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v1',
            ]));

        $refreshed2 = $this->runWithFirmContext($firm, fn () => app(IntegrationExternalMappingService::class)
            ->refreshVersionTokensIfCurrent($mapping2->fresh(), 'local-v1', 'ext-v2', 'local-v1'));

        $this->assertNotNull($refreshed2, 'Baseline: the guard must succeed when the expected previous version is still current — otherwise the refusal above proves nothing.');
        $this->assertSame('ext-v2', $refreshed2->external_version_token);
    }

    public function test_the_version_guarded_refresh_also_refuses_a_mapping_tombstoned_since_claim(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $mapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::factory()
            ->forFirmIntegration($connection)
            ->create([
                'resource_type' => 'contact',
                'local_type' => 'App\\Models\\Contact',
                'local_id' => 1107,
                'external_id' => 'ext-1107',
                'external_version_token' => 'ext-v1',
                'local_version_token' => 'local-v1',
            ]));

        // Between an earlier claim (which observed this mapping live)
        // and apply, an unrelated process tombstones it — e.g. the
        // local record was deleted.
        $this->runWithFirmContext($firm, fn () => app(IntegrationExternalMappingService::class)->tombstone($mapping, 'deleted_locally'));

        $refreshed = $this->runWithFirmContext($firm, fn () => app(IntegrationExternalMappingService::class)
            ->refreshVersionTokensIfCurrent($mapping->fresh(), 'local-v1', 'ext-v2', 'local-v1'));

        $this->assertNull($refreshed, 'The guard must refuse to refresh a mapping that has been tombstoned since claim — never resurrect it.');

        $fresh = $this->runWithFirmContext($firm, fn () => $mapping->fresh());
        $this->assertNotNull($fresh->tombstoned_at, 'The tombstone must remain in place.');
        $this->assertSame('ext-v1', $fresh->external_version_token, 'A refused refresh must never change the tombstoned row at all.');
    }

    // ------------------------------------------------------------------
    // Transaction / lock discipline
    // ------------------------------------------------------------------

    public function test_no_transaction_or_lock_is_held_across_the_push_call(): void
    {
        [$firm, $connection] = $this->committedConnection();
        $levelBefore = DB::transactionLevel();
        $observedLevel = null;
        $observedLockable = null;

        $this->provider->onPush = function () use (&$observedLevel, &$observedLockable, $connection): array {
            $observedLevel = DB::transactionLevel();
            $observedLockable = $this->connectionRowAcceptsDurableWrites($connection->id);

            return ['external_id' => 'ext-lockcheck', 'version_token' => 'v-lockcheck'];
        };

        $this->dispatchPush($connection, $firm, 107, 'local-v1');

        $this->assertSame(
            $levelBefore,
            $observedLevel,
            'The push call must not run inside a transaction this job opened.'
        );
        $this->assertTrue(
            $observedLockable,
            'PushSyncJob must not hold FOR UPDATE on firm_integrations across the provider call — '
                .'that is the exact lock Checkpoint 8.1 deadlocked against.'
        );
    }

    /**
     * The same genuinely-separate-session lock probe
     * PullSyncJobConcurrencyBoundaryTest uses, with its own positive
     * control folded in below.
     */
    private function connectionRowAcceptsDurableWrites(int $connectionId): bool
    {
        $probe = DB::connection(self::DURABLE_CONNECTION);

        try {
            return $probe->transaction(function () use ($probe, $connectionId) {
                $rows = $probe->select(
                    'select id from firm_integrations where id = ? for key share nowait',
                    [$connectionId]
                );

                if ($rows === []) {
                    throw new RuntimeException(
                        'The probe session cannot see firm_integrations row '.$connectionId
                            .' — the fixture must be committed for this test to mean anything.'
                    );
                }

                return true;
            });
        } catch (QueryException) {
            return false;
        }
    }

    public function test_the_lock_probe_really_detects_a_held_for_update_lock(): void
    {
        [$firm, $connection] = $this->committedConnection();

        $this->assertTrue(
            $this->connectionRowAcceptsDurableWrites($connection->id),
            'Baseline: with nobody holding the row, the probe must succeed.'
        );

        DB::beginTransaction();

        try {
            DB::select('select id from firm_integrations where id = ? for update', [$connection->id]);

            $this->assertFalse(
                $this->connectionRowAcceptsDurableWrites($connection->id),
                'With FOR UPDATE genuinely held, the probe MUST fail — otherwise it proves nothing.'
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_tenant_context_is_restored_even_when_the_push_fails(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $this->provider->onPush = static function (): array {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, 429, 'push');
        };

        app(TenantContextService::class)->clearDatabaseTenantContext();
        $this->assertNoDatabaseTenantContext();

        $this->dispatchPush($connection, $firm, 108, 'local-v1');

        $this->assertNoDatabaseTenantContext(
            'The session-scoped provider phase must restore context even on failure.'
        );
    }

    // ------------------------------------------------------------------
    // Two concurrent claims for the same logical operation
    // ------------------------------------------------------------------

    public function test_two_concurrent_claims_for_the_same_logical_operation_produce_exactly_one_send(): void
    {
        $firm = $this->firm();
        $connection = $this->connection($firm);

        $attempts = app(ProviderOperationAttemptService::class);
        $idempotencyKey = hash('sha256', "{$connection->id}:contact:App\\Models\\Contact:109:local-v1");
        $logicalOperationKey = 'push_sync:'.$idempotencyKey;

        // Two genuinely independent claim() calls against the SAME
        // logical operation key, each writing to the durable connection
        // via its own compare-and-set INSERT/UPDATE — exactly what two
        // real concurrent PushSyncJob workers would each individually
        // execute, racing the same unique index.
        $first = $attempts->claim($logicalOperationKey, ProviderKey::Microsoft365->value, $firm->id, $connection->id, 'push_sync');
        $second = $attempts->claim($logicalOperationKey, ProviderKey::Microsoft365->value, $firm->id, $connection->id, 'push_sync');

        $this->assertTrue($first->maySendProviderRequest(), 'Exactly one of the two racing claims must win the send.');
        $this->assertFalse($second->maySendProviderRequest(), 'The other must be told not to send in parallel.');
        $this->assertSame(ProviderOperationClaimDecision::InFlightElsewhere, $second->decision);

        $rowCount = DB::connection(self::DURABLE_CONNECTION)
            ->table('provider_operation_attempts')
            ->where('logical_operation_key', $logicalOperationKey)
            ->count();
        $this->assertSame(1, $rowCount, 'Exactly one durable row must exist for one logical operation, never two.');
    }
}
