<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\RefreshIntegrationToken;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RefreshIntegrationTokenJobTest — Checkpoint 8
 * (agent-8d-token-refresh-concurrency-design.md;
 * agent-8h-architecture-security-review.md §1 item 4/§4.2;
 * diff-review.md item 5; fix-diff-review.md §3.1). Queue promotion
 * (ShouldQueue, $tries=5, backoff()); Gate 1 no-op (disconnected before
 * dispatch); Gate 2 no-op (disconnected/reauthorization-required
 * discovered after lock acquired — race simulation); the fixed
 * transitionedThisCall disambiguation — MUST explicitly prove a no-op
 * does NOT call recordCredentialError()/recordSuccess(), while a
 * genuine invalid_grant DOES; failed() -> markRefreshExhausted() ->
 * ConnectionStatus::Error (never ReauthorizationRequired) after $tries
 * exhausted for a transient category.
 */
class RefreshIntegrationTokenJobTest extends TestCase
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

    private function connectionService(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService),
                new PkceService,
                new ProviderRedirectUrlValidator,
            ),
            new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder),
            new IntegrationAccessPolicyService(new TimelineEventRecorder),
            new ProviderRegistry,
            new OutboundProviderHttpClient,
            new ProviderRedirectUrlValidator,
            new TimelineEventRecorder,
            // Checkpoint 10 addition (frozen design §4): ProviderConnectionService's
            // constructor gained this 8th, required dependency — every
            // manual construction site in this file must supply it.
            app(IntegrationEntitlementPolicyService::class),
        );
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
    }

    /**
     * Connection Active, with a real Active access credential (expired,
     * so the double-checked-locking guard doesn't short-circuit before
     * calling the provider) and refresh credential, mirroring
     * ProviderConnectionServiceOAuthTest's own established fixture
     * pattern — but built via IntegrationCredentialService::store()
     * directly, without the full OAuth callback flow (unnecessary for
     * this job's own concerns).
     */
    private function activeConnectionWithCredentials(Firm $firm): FirmIntegration
    {
        // A real, Active TenantEncryptionKey for THIS firm — required
        // before setRefreshTokenPlaintext()'s later rotate() call (and
        // any other real credential encryption) can succeed.
        // IntegrationCredentialFactory::definition()'s own
        // encryptFixtureSecret() provisions a key for a THROWAWAY firm
        // it creates internally, not for the real firm passed via
        // forFirmIntegration() — matching
        // ProviderConnectionServiceOAuthTest::firmWithActiveKey()'s
        // identical, already-established fix for this exact gap.
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => null]),
        );

        $this->runWithFirmContext($firm, function () use ($connection) {
            IntegrationCredential::factory()
                ->forFirmIntegration($connection)
                ->ofType(CredentialType::OauthAccessToken)
                ->create(['expires_at' => now()->subMinute()]);
            IntegrationCredential::factory()
                ->forFirmIntegration($connection)
                ->ofType(CredentialType::OauthRefreshToken)
                ->create();
        });

        return $this->runWithFirmContext($firm, fn () => $connection->fresh());
    }

    private function setRefreshTokenPlaintext(Firm $firm, FirmIntegration $connection, string $plaintext): void
    {
        $refreshCredential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthRefreshToken->value)
            ->first());

        $this->runWithFirmContext($firm, fn () => $this->credentialService()->rotate($connection->fresh(), $refreshCredential, $plaintext));
    }

    private function healthRow(Firm $firm, int $connectionId): ?object
    {
        return $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connectionId)->first());
    }

    // ------------------------------------------------------------
    // Queue promotion
    // ------------------------------------------------------------

    public function test_the_job_implements_should_queue(): void
    {
        $job = new RefreshIntegrationToken(1, 1);

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function test_the_job_has_5_tries(): void
    {
        $job = new RefreshIntegrationToken(1, 1);

        $this->assertSame(5, $job->tries);
    }

    public function test_backoff_returns_the_fixed_thirty_sixty_one_twenty_two_forty_schedule(): void
    {
        $job = new RefreshIntegrationToken(1, 1);

        $this->assertSame([30, 60, 120, 240], $job->backoff());
    }

    // ------------------------------------------------------------
    // Gate 1 no-op — disconnected BEFORE dispatch
    // ------------------------------------------------------------

    public function test_gate_1_no_ops_silently_for_a_disconnected_connection(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Disconnected->value, 'external_account_id' => null]),
        );

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $job->handle($this->connectionService(), app(HealthStateService::class));

        $this->assertNull($this->healthRow($firm, $connection->id), 'Gate 1\'s silent no-op must never call any HealthStateService method.');
    }

    public function test_gate_1_no_op_does_not_throw(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Pending->value, 'external_account_id' => null]),
        );

        $job = new RefreshIntegrationToken($connection->id, $firm->id);

        // Must complete without throwing — a Gate 1 no-op is never an
        // exception, never counted against $tries.
        $job->handle($this->connectionService(), app(HealthStateService::class));

        $this->assertTrue(true);
    }

    public function test_gate_1_no_op_leaves_connection_status_completely_unchanged(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::ScopeInsufficient->value, 'external_account_id' => null]),
        );

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $job->handle($this->connectionService(), app(HealthStateService::class));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ScopeInsufficient, $fresh->status);
    }

    // ------------------------------------------------------------
    // Gate 2 no-op (service-level proof, isolating Gate 2 specifically
    // from Gate 1) — a stale in-memory $connection object still
    // reporting Active is passed to refreshConnectionToken() directly,
    // while the DB row has ALREADY moved on. Mirrors fix-diff-review.md
    // §3.1's own "stale in-memory $connection object" methodology.
    // ------------------------------------------------------------

    public function test_gate_2_no_ops_when_the_locked_row_is_no_longer_active_despite_a_stale_in_memory_object(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);

        // A stale snapshot, taken BEFORE the DB row moves on.
        $staleConnection = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $staleConnection->status);

        // Simulate a concurrent transition landing between Gate 1's read
        // and the lock's acquisition — the row itself is updated to
        // Disconnected in the DB, but $staleConnection (already loaded
        // into PHP memory) still reports Active.
        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::Disconnected->value]));

        $result = $this->connectionService()->refreshConnectionToken($staleConnection);

        $this->assertFalse($result->successful);
        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
        $this->assertFalse($result->transitionedThisCall, 'Gate 2\'s no-op must leave transitionedThisCall at its false default — this call performed NO transition of its own.');
    }

    public function test_gate_2_no_op_never_attempts_to_decrypt_or_rotate_any_credential(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);
        $staleConnection = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        $accessCiphertextBefore = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->value('encrypted_payload_ciphertext'));

        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::ReauthorizationRequired->value]));

        $this->connectionService()->refreshConnectionToken($staleConnection);

        $accessCiphertextAfter = $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->value('encrypted_payload_ciphertext'));

        $this->assertSame($accessCiphertextBefore, $accessCiphertextAfter, 'Gate 2\'s no-op must never reach the credential-decrypt/rotate step.');
    }

    // ------------------------------------------------------------
    // Gate 1/Gate 2 no-op — job-level black-box race simulation
    // (mirrors fix-diff-review.md §3.1 Scenario A exactly: the
    // connection is flipped to ReauthorizationRequired in the DB,
    // simulating a concurrent transition, then the REAL job's handle()
    // is run end-to-end). Regardless of which specific gate catches it
    // internally, the job's OBSERVABLE contract is identical: no
    // exception, and — the mandatory disambiguation proof — NO health
    // signal call.
    // ------------------------------------------------------------

    public function test_a_connection_that_already_transitioned_away_from_active_before_the_job_runs_records_no_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);

        // Simulate a concurrent invalid_grant transition having already
        // landed by the time this job execution begins.
        $this->runWithFirmContext($firm, fn () => DB::table('firm_integrations')->where('id', $connection->id)->update(['status' => ConnectionStatus::ReauthorizationRequired->value]));

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $job->handle($this->connectionService(), app(HealthStateService::class));

        $this->assertNull(
            $this->healthRow($firm, $connection->id),
            'A no-op (via Gate 1 or Gate 2, either is fine — this is a black-box observable-behavior proof) must NEVER call recordCredentialError()/recordSuccess() — inflating consecutive_failures or resetting next_retry_at for a non-event would be a real bug.'
        );
    }

    // ------------------------------------------------------------
    // The genuine, fresh invalid_grant path — recordCredentialError()
    // DOES fire, and transitionedThisCall is true
    // ------------------------------------------------------------

    public function test_a_genuine_invalid_grant_transitions_to_reauthorization_required_and_records_a_credential_error_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);
        $this->setRefreshTokenPlaintext($firm, $connection, TestProvider::FAILURE_SENTINEL);

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $job->handle($this->connectionService(), app(HealthStateService::class));

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ReauthorizationRequired, $fresh->status);

        $health = $this->healthRow($firm, $connection->id);
        $this->assertNotNull($health, 'A GENUINE invalid_grant transition (transitionedThisCall=true) MUST call recordCredentialError() — the opposite half of the disambiguation proof.');
        $this->assertSame('credential_error', $health->last_failure_category);
        $this->assertSame('action_required', $health->summary_state);
    }

    public function test_a_genuine_invalid_grant_records_a_provider_connection_service_directly_confirms_transitioned_this_call(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);
        $this->setRefreshTokenPlaintext($firm, $connection, TestProvider::FAILURE_SENTINEL);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $result = $this->connectionService()->refreshConnectionToken($fresh);

        $this->assertFalse($result->successful);
        $this->assertSame(ConnectionStatus::ReauthorizationRequired, $result->status);
        $this->assertTrue($result->transitionedThisCall, 'A genuine, fresh invalid_grant transition performed by THIS call must set transitionedThisCall = true.');
    }

    // ------------------------------------------------------------
    // failed() -> markRefreshExhausted() -> ConnectionStatus::Error
    // (never ReauthorizationRequired) after $tries exhausted for a
    // transient category
    // ------------------------------------------------------------

    public function test_failed_hook_transitions_an_active_connection_to_error_for_a_transient_category(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, null, 'refreshToken');

        $job->failed($exception);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(
            ConnectionStatus::Error,
            $fresh->status,
            'A transient (non-invalid_grant) category exhausting all retries must transition to Error — NEVER ReauthorizationRequired, which specifically implies the credential itself is known-invalid.'
        );
    }

    public function test_failed_hook_never_transitions_to_reauthorization_required(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_TIMEOUT, null, 'refreshToken');

        $job->failed($exception);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNotSame(ConnectionStatus::ReauthorizationRequired, $fresh->status);
    }

    public function test_failed_hook_records_a_provider_error_health_signal(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, null, 'refreshToken');

        $job->failed($exception);

        $health = $this->healthRow($firm, $connection->id);
        $this->assertNotNull($health);
        $this->assertSame('provider_error', $health->last_failure_category);
    }

    public function test_failed_hook_no_ops_when_the_connection_is_no_longer_active(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(['status' => ConnectionStatus::Disconnected->value, 'external_account_id' => null]),
        );

        $job = new RefreshIntegrationToken($connection->id, $firm->id);
        $exception = new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR, null, 'refreshToken');

        $job->failed($exception);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status, 'failed() must no-op for a connection already moved on — never resurrect it into Error.');
    }

    public function test_a_genuine_transient_failure_thrown_from_refresh_connection_token_matches_what_the_failed_hook_expects(): void
    {
        // Companion, end-to-end proof that refreshConnectionToken()
        // genuinely RETHROWS (rather than swallowing) a transient
        // category — the exact exception failed() is designed to
        // receive.
        $firm = Firm::factory()->create();
        $connection = $this->activeConnectionWithCredentials($firm);
        $this->setRefreshTokenPlaintext($firm, $connection, TestProvider::TRANSIENT_FAILURE_SENTINEL);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());

        try {
            $this->connectionService()->refreshConnectionToken($fresh);
            $this->fail('Expected refreshConnectionToken() to rethrow for a transient category.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame('network_error', $e->category());
        }

        // The connection must remain Active — a single transient
        // failure never transitions status; only the job's own
        // exhausted-$tries failed() hook does that.
        $stillActive = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $stillActive->status);
    }
}
