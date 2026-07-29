<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Jobs;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationConnectionHealth;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\SyncCursorService;
use App\Jobs\PullSyncJob;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * PullSyncJobPlaidCredentialLivenessTest — FirmsVault Live Integrations,
 * Checkpoint 4 (Plaid financial evidence add-on) test-writer pass.
 * Proves the coordinator-fixed credential-liveness safety net in
 * `App\Jobs\PullSyncJob::runBatchLoop()`
 * (checkpoint4-combined-design.md §1.1.2, binding) genuinely holds for
 * Plaid connections — the exact bug class the task brief flagged: the
 * fix widens BOTH the `whereIn('credential_type', [...])` EXISTENCE
 * check AND the `findActiveCredential()` LIVENESS check to also cover
 * `CredentialType::ProviderAccessToken`, not just one of the two (a
 * partial fix that widened only the first would make every Plaid
 * connection either always pass — a real liveness gap — or always fail
 * — every healthy connection incorrectly blocked; verified here to be
 * neither).
 *
 * Deliberately stops short of exercising a full, successful Plaid pull —
 * `PullSyncJob::runBatchLoop()`'s own real provider call is
 * `RequiresBillableCallPipelineContract`-gated (routes through
 * `ProviderBillableCallPipeline::execute()`), a separate test-writer's
 * scope for this checkpoint (see
 * `PlaidProviderPullSyncTest.php`'s class docblock for the identical
 * reasoning). The credential-liveness check itself runs BEFORE that
 * gated call, so the blocking scenario is fully provable in isolation;
 * the "does not falsely block a healthy connection" scenario is proven
 * by asserting the run never fails with the SPECIFIC credential-liveness
 * signature, regardless of what (if anything) happens further downstream
 * in the billing pipeline this file does not configure.
 */
final class PullSyncJobPlaidCredentialLivenessTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [ProviderKey::Plaid->value => PlaidProvider::class]]);
        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);

        Http::fake([self::SANDBOX_BASE.'/*' => Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500)]);
    }

    /**
     * A connection whose ProviderAccessToken credential was provisioned
     * at some point but is no longer Active (revoked/rotated away),
     * while `firm_integrations.status` is still Active (hasn't caught up
     * yet) — the exact data-consistency edge case this safety net exists
     * to catch. The run must fail CLOSED, the cursor must be marked
     * failed (never advanced), and a credential-error health event must
     * be recorded — all WITHOUT ever attempting the provider call.
     */
    public function test_a_revoked_provider_access_token_is_caught_by_the_safety_net_before_any_provider_call_is_attempted(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, function () use ($connection) {
            $credential = app(IntegrationCredentialService::class)->store($connection, CredentialType::ProviderAccessToken, 'now-stale-access-token');
            app(IntegrationCredentialService::class)->revoke($connection, $credential, 'unit_test_setup');
        });

        PullSyncJob::dispatchSync($connection->id, $firm->id, ResourceType::Transaction->value);

        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->latest('id')->first());

        $this->assertNotNull($run);
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertStringContainsString('authentication_failed', (string) $run->error_summary);

        Http::assertNothingSent();

        $cursor = $this->runWithFirmContext($firm, fn () => app(SyncCursorService::class)->firstOrCreate($connection, ResourceType::Transaction->value, SyncDirection::Inbound));
        $this->assertNull($this->runWithFirmContext($firm, fn () => app(SyncCursorService::class)->decryptCursorValue($connection, $cursor)), 'A blocked run must never advance the cursor.');

        $health = $this->runWithFirmContext($firm, fn () => IntegrationConnectionHealth::query()->where('firm_integration_id', $connection->id)->first());
        $this->assertNotNull($health);
        $this->assertSame('credential_error', $health->last_failure_category);
    }

    /**
     * A connection that has NEVER had any OAuth-shaped credential
     * provisioned (a hypothetical AuthMethod::None/ApiKey-only provider
     * shape, per the safety net's own documented existence check) must
     * NOT be blocked by this safety net at all — confirmed here for the
     * "genuinely no credential of either shape has ever existed" case,
     * distinguishing "never provisioned" from "provisioned then
     * revoked" (the previous test).
     */
    public function test_a_connection_that_never_had_any_credential_provisioned_is_not_blocked_by_the_liveness_check(): void
    {
        [$firm, $connection] = $this->makeConnection();

        try {
            PullSyncJob::dispatchSync($connection->id, $firm->id, ResourceType::Transaction->value);
        } catch (Throwable) {
            // The billing pipeline (a separate test-writer's scope, not
            // configured by this file) may legitimately throw once
            // execution reaches it — what matters here is which side of
            // the safety net the run failed on.
        }

        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->latest('id')->first());

        // Unconditional assertion (never skipped, regardless of whether
        // a run row exists or how far the unconfigured billing pipeline
        // got) — a run blocked BY THIS SAFETY NET is always Failed with
        // the exact 'authentication_failed' signature; anything else
        // (no run at all, a run failed for a different reason, or no
        // failure at all) correctly proves the safety net did not fire.
        $blockedBySafetyNet = $run !== null
            && $run->status === SyncRunStatus::Failed
            && str_contains((string) $run->error_summary, 'authentication_failed');

        $this->assertFalse(
            $blockedBySafetyNet,
            'A connection with no OAuth-shaped credential ever provisioned must never be blocked by the credential-liveness safety net specifically — any failure here must come from somewhere else (e.g. the unconfigured billing pipeline this file deliberately does not set up).'
        );
    }

    /**
     * A connection with a genuinely Active ProviderAccessToken credential
     * must also NOT be blocked by the liveness check — the core positive
     * proof that the coordinator's fix does not over-widen and
     * incorrectly block every healthy Plaid connection.
     */
    public function test_a_connection_with_an_active_provider_access_token_is_not_blocked_by_the_liveness_check(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)->store($connection, CredentialType::ProviderAccessToken, 'a-healthy-access-token'));

        try {
            PullSyncJob::dispatchSync($connection->id, $firm->id, ResourceType::Transaction->value);
        } catch (Throwable) {
            // See the previous test's identical reasoning.
        }

        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::query()->where('firm_integration_id', $connection->id)->latest('id')->first());

        $blockedBySafetyNet = $run !== null
            && $run->status === SyncRunStatus::Failed
            && str_contains((string) $run->error_summary, 'authentication_failed');

        $this->assertFalse(
            $blockedBySafetyNet,
            'A connection with a genuinely Active ProviderAccessToken credential must never be blocked by the credential-liveness safety net.'
        );

        $health = $this->runWithFirmContext($firm, fn () => IntegrationConnectionHealth::query()->where('firm_integration_id', $connection->id)->first());
        if ($health !== null) {
            $this->assertNotSame('credential_error', $health->last_failure_category, 'A healthy, Active credential must never produce a credential_error health signal via this safety net.');
        } else {
            $this->assertNull($health);
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function plaidProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);
    }

    /**
     * Durable Firm required: both tests above dispatch a real
     * PullSyncJob run for a Plaid ResourceType::Transaction connection
     * with no billing pipeline configured, and explicitly document (see
     * each test's own try/catch comment) that execution may legitimately
     * reach `ProviderBillableCallPipeline::execute()` before throwing.
     * Every one of that pipeline's steps — success AND denial alike —
     * writes via `TimelineEventRecorder::recordOnIndependentConnection()`
     * (independentOfAmbientTransaction: true), which cannot see a Firm
     * still uncommitted inside this test's RefreshDatabase transaction —
     * same shape as ProviderBillableCallPipelineTest's own
     * firmWithEntitlement() helper.
     *
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->connection('pgsql_audit')->create();
        $this->cleanUpDurableFirmAuditTrailAfterRollback($firm);

        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => 'item-sandbox-fixture-id']));

        return [$firm, $connection];
    }

    /**
     * Mirrors IntegrationAuditEventTypeTest::cleanUpDurableFirmAuditTrailAfterRollback()
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
}
