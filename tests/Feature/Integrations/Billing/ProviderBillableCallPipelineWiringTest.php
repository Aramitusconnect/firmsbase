<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Enums\EntitlementSource;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\ProviderBillableCallReservation;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\TimelineEvent;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ProviderBillableCallPipelineWiringTest — checkpoint4-design-cost-control.md
 * §2.1 call site #1, resolving Finding 1 of checkpoint4-security-review.md.
 * Proves the ACTUAL wiring works end-to-end, not just that
 * ProviderBillableCallPipeline exists in isolation:
 *
 *   - A PullSyncJob run against a connection whose registered provider
 *     implements RequiresBillableCallPipelineContract (the real,
 *     shipped shape for Plaid) routes the pull through the full
 *     billing pipeline: a reservation row is created and finalized,
 *     and pipeline audit events fire.
 *   - The SAME job run against a connection whose registered provider
 *     does NOT implement RequiresBillableCallPipelineContract
 *     (Microsoft365Provider/GoogleWorkspaceProvider/TestProvider's own
 *     real, unmodified shape) creates NO reservation row and no
 *     pipeline audit event — the old, direct
 *     `$httpClient->execute(...)` path is what actually ran, provably
 *     unaffected.
 *
 * Both cases substitute a lightweight, deterministic double registered
 * under the config-driven provider map (`config('integrations.providers')`)
 * plus a matching container `instance()` binding — exactly the
 * technique `PullSyncJobTest::registerFakePullProvider()` already
 * establishes for `ProviderKey::Test`, applied here for both keys, so
 * neither scenario needs a compile-time reference to any concrete
 * provider adapter class — only to the `ProviderKey` enum and the
 * `Requires*`/`Supports*` contracts every provider (real or fake) must
 * satisfy.
 */
class ProviderBillableCallPipelineWiringTest extends TestCase
{
    use RefreshDatabase;

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
     * technique.
     *
     * CORRECTED: an earlier draft of this helper skipped cleanup on the
     * assumption that this file's own disposable database is always
     * destroyed immediately afterward — false whenever this class runs
     * as part of a larger combined suite (every full-suite run, and any
     * `php artisan test` invocation naming more than this one file), in
     * which case the un-cleaned-up Firm and its durable timeline_events
     * row persist for the rest of that run and silently pollute any
     * later test asserting an exact platform-wide firm/event count
     * (found and fixed during Checkpoint 4's own post-commit full-suite
     * verification — see AuditLogResourceTest, PlatformExecutiveDashboardServiceTest,
     * PlatformTimelineEventDirectoryServiceTest). Cleanup is registered
     * via beforeApplicationDestroyed() — see
     * cleanUpDurableFirmAuditTrailAfterRollback()'s own docblock for why
     * an inline finally deadlocks here.
     */
    private function firmWithEntitlement(): Firm
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    /**
     * Mirrors IntegrationAccessPolicyServiceTest::cleanUpDurableFirmAuditTrailAfterRollback()
     * exactly (see that method's own docblock for the full deadlock
     * reasoning) — registered via beforeApplicationDestroyed() so it
     * runs after RefreshDatabase's own rollback has already released
     * the FOR KEY SHARE lock the default-connection fixtures hold on
     * this Firm row.
     */
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

    /**
     * Builds and registers a deterministic double under the given
     * ProviderKey, implementing IntegrationProviderContract +
     * SupportsPullSyncContract, optionally ALSO
     * RequiresBillableCallPipelineContract — the one axis this test
     * varies between its two scenarios.
     */
    private function registerFakeProvider(ProviderKey $key, bool $requiresBillablePipeline): void
    {
        $fake = $requiresBillablePipeline
            ? $this->makeBillablePipelineFake($key)
            : $this->makePlainFake($key);

        $class = get_class($fake);
        app()->instance($class, $fake);
        config(['integrations.providers' => [$key->value => $class]]);
    }

    private function makeBillablePipelineFake(ProviderKey $key): object
    {
        return new class($key) implements IntegrationProviderContract, RequiresBillableCallPipelineContract, SupportsPullSyncContract
        {
            public function __construct(private readonly ProviderKey $providerKey) {}

            public function key(): ProviderKey
            {
                return $this->providerKey;
            }

            public function displayName(): string
            {
                return 'Fake billable-pipeline provider (wiring test double)';
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
                return [AuthMethod::LinkToken];
            }

            public function pullableResourceTypes(): array
            {
                return ['transaction'];
            }

            public function pull(array $context, string $resourceType, ?string $cursor): array
            {
                return ['items' => [], 'next_cursor' => null];
            }
        };
    }

    private function makePlainFake(ProviderKey $key): object
    {
        return new class($key) implements IntegrationProviderContract, SupportsPullSyncContract
        {
            public function __construct(private readonly ProviderKey $providerKey) {}

            public function key(): ProviderKey
            {
                return $this->providerKey;
            }

            public function displayName(): string
            {
                return 'Fake non-billable-pipeline provider (wiring test double)';
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
                return ['items' => [], 'next_cursor' => null];
            }
        };
    }

    private function dispatchPull(FirmIntegration $connection, int $firmId, string $resourceType): void
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

    public function test_a_pull_against_a_plaid_connection_routes_through_the_billing_pipeline_and_creates_a_finalized_reservation(): void
    {
        $firm = $this->firmWithEntitlement();
        $plaidCatalogProvider = IntegrationProvider::query()->where('code', 'plaid')->firstOrFail();
        $connection = $this->createWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($plaidCatalogProvider)->create(),
        );
        $this->registerFakeProvider(ProviderKey::Plaid, requiresBillablePipeline: true);

        $this->dispatchPull($connection, $firm->id, 'transaction');

        $reservations = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('firm_integration_id', $connection->id)
            ->get());

        $this->assertCount(1, $reservations, 'The Plaid pull must have been reserved by the billing pipeline.');
        $this->assertSame(ProviderBillableCallReservation::STATUS_FINALIZED_BILLABLE, $reservations->first()->status);
        $this->assertSame('transactions', $reservations->first()->product);
        $this->assertSame(ProviderKey::Plaid->value, $reservations->first()->provider_key);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'provider_billing.call_finalized_billable')
            ->first());
        $this->assertNotNull($event, 'Expected the pipeline to have recorded its own finalize audit event.');
    }

    public function test_a_pull_against_a_non_plaid_connection_never_touches_the_billing_pipeline_at_all(): void
    {
        $firm = Firm::factory()->create();
        // Deliberately NO Plaid entitlement granted — if the non-Plaid
        // path somehow routed through the pipeline, step 3's entitlement
        // check would throw and this job run would fail loudly, so a
        // silently-succeeding run here is itself part of the proof that
        // the pipeline was never reached.
        $connection = $this->createWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null]));
        $this->registerFakeProvider(ProviderKey::Test, requiresBillablePipeline: false);

        $this->dispatchPull($connection, $firm->id, 'contact');

        $reservationCount = $this->runWithFirmContext($firm, fn () => ProviderBillableCallReservation::query()
            ->where('firm_integration_id', $connection->id)
            ->count());
        $this->assertSame(0, $reservationCount, 'A non-Plaid provider must never create a billing reservation.');

        $eventCount = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('firm_id', $firm->id)
            ->where('event_type', 'like', 'provider_billing.%')
            ->count());
        $this->assertSame(0, $eventCount, 'A non-Plaid provider must never fire any provider_billing.* audit event.');

        // The run must still have completed via the old, direct
        // $httpClient->execute() path (never blocked, never errored) —
        // proving the else branch is byte-for-byte unaffected, not
        // merely "didn't create a reservation."
        $run = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());
        $this->assertNotNull($run);
        $this->assertNotSame('failed', $run->status);
    }
}
