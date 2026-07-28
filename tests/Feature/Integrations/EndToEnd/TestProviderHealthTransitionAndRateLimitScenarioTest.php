<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\EndToEnd;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ConnectionHealthSummary;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncRunType;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\RefreshIntegrationToken;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationSyncItem;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Jobs\SyncRetryPollJob;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * TestProviderHealthTransitionAndRateLimitScenarioTest — Checkpoint 12
 * (frozen-design-post-security-review.md §6 Scenario 3). A sequence of
 * real job runs against ONE connection with genuine TestProvider,
 * asserting HealthStateService's real state-machine transitions:
 * success (recordSuccess) -> rate-limited failure (recordRateLimited) ->
 * credential-error refresh (recordCredentialError) -> a real
 * reauthorization back to Active -> recovery success (recordSuccess
 * again). Never via checkHealth() (per N1/F6 — checkHealth() has zero
 * proactive-polling caller anywhere in this codebase).
 *
 * RESOLVED DEVIATION for the rate-limited step (previously disclosed
 * here as a workaround, now fixed): this originally explained that
 * push()'s RATE_LIMIT_SENTINEL check reads `$payload['__simulate_failure']`,
 * never `$context`, and SyncRetryPollJob's own $payload
 * (local_type/local_id/existing_external_id) was never merged with
 * $providerContext — so no reachable, currently-wired job dispatch could
 * drive a genuine TestProvider `rate_limited` throw into
 * HealthStateService::recordRateLimited(). That gap is now closed in
 * App\Jobs\SyncRetryPollJob::resolveOneRetry(), which routes
 * providerContext['__simulate_failure'] into $payload before calling
 * push() (the ONE key push()'s sentinel checks actually read off
 * $payload). Step 2 below now dispatches a real, queue-processed
 * SyncRetryPollJob carrying providerContext['__simulate_failure'] =>
 * RATE_LIMIT_SENTINEL against a genuinely claimable retry item, driving
 * resolveOneRetry()'s own catch block -> recordHealthSignal() ->
 * HealthStateService::recordRateLimited() end to end through real,
 * unmodified production code — no manual orchestration of those
 * components. A short sanity check of the underlying sentinel mechanism
 * itself (genuine TestProvider + OutboundProviderHttpClient, proving
 * RATE_LIMIT_SENTINEL really does translate through RetryAfterParser
 * into retryAfterSeconds() === 30) is kept immediately before it, since
 * that is real, unfabricated coverage this file already established and
 * this fix does not change RetryAfterParser's own translation.
 */
final class TestProviderHealthTransitionAndRateLimitScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_the_full_success_rate_limited_credential_error_recovery_health_state_sequence(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser, 'health-scenario-account');

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status);

        // ------------------------------------------------------------
        // Step 1 — success, via a real, queue-processed SyncRetryPollJob
        // dispatch against a real FailedRetryable push-shaped item and
        // genuine TestProvider. Asserts the real recordSuccess()-driven
        // health state.
        // ------------------------------------------------------------
        $this->seedClaimableRetryItem($firm, $connection, 900001);
        SyncRetryPollJob::dispatch($firm->id);

        $healthAfterSuccess = $this->healthSummary($firm, $connection);
        $this->assertSame(HealthSummaryState::Healthy, $healthAfterSuccess->summaryState);
        $this->assertNotNull($healthAfterSuccess->lastSuccessAt);
        $this->assertSame(0, $healthAfterSuccess->consecutiveFailures);

        // ------------------------------------------------------------
        // Step 2 — rate-limited failure (see class docblock: the
        // job-driven dispatch below was previously disclosed as
        // unreachable and is now fixed).
        //
        // First, a short sanity check of the underlying sentinel
        // mechanism itself — genuine, unmodified TestProvider +
        // OutboundProviderHttpClient, confirming RATE_LIMIT_SENTINEL
        // really does translate through RetryAfterParser into a real
        // retryAfterSeconds() value — before relying on that same
        // mechanism firing inside the real job dispatch below.
        // ------------------------------------------------------------
        $sanityProvider = new TestProvider;
        $sanityHttpClient = new OutboundProviderHttpClient;

        $caughtRateLimited = null;
        try {
            $sanityHttpClient->execute(
                fn () => $sanityProvider->push([], 'contact', ['__simulate_failure' => TestProvider::RATE_LIMIT_SENTINEL]),
                'push',
            );
            $this->fail('Expected a SanitizedProviderHttpException (rate_limited).');
        } catch (SanitizedProviderHttpException $e) {
            $caughtRateLimited = $e;
        }

        $this->assertSame(SanitizedProviderHttpException::CATEGORY_RATE_LIMITED, $caughtRateLimited->category());
        $this->assertNotNull($caughtRateLimited->retryAfterSeconds(), 'A genuine RATE_LIMIT_SENTINEL throw must carry a real, RetryAfterParser-translated retryAfterSeconds() value — proving RetryAfterParser genuinely ran.');
        $this->assertSame(30, $caughtRateLimited->retryAfterSeconds(), 'TestProvider\'s own documented rate-limit simulation carries retryAfterRaw: \'30\'.');

        // ------------------------------------------------------------
        // The real, now-genuinely-reachable proof: a real, queue-
        // processed SyncRetryPollJob dispatch against a genuinely
        // claimable retry item, carrying providerContext
        // ['__simulate_failure'] => RATE_LIMIT_SENTINEL. This drives
        // resolveOneRetry()'s own catch block -> recordHealthSignal()
        // -> HealthStateService::recordRateLimited() end to end through
        // real, unmodified production code.
        //
        // Note: SyncRetryPollJob::recordHealthSignal() (frozen design
        // D2 — this checkpoint deliberately does not touch
        // resolveOneRetry()'s own retry-outcome/backoff logic) always
        // calls recordRateLimited() with a fixed now()->addMinutes(1)
        // reset window, NOT the sentinel's own parsed
        // retryAfterSeconds() value asserted above — so the assertions
        // below reflect the job's real, current behavior rather than
        // re-deriving the reset time from retryAfterSeconds().
        // ------------------------------------------------------------
        $this->seedClaimableRetryItem($firm, $connection, 900003);
        SyncRetryPollJob::dispatch($firm->id, providerContext: json_encode(['__simulate_failure' => TestProvider::RATE_LIMIT_SENTINEL]));

        $healthAfterRateLimit = $this->healthSummary($firm, $connection);
        $this->assertSame(HealthSummaryState::Degraded, $healthAfterRateLimit->summaryState);
        $this->assertSame(1, $healthAfterRateLimit->consecutiveFailures);

        $rateLimitedResetAt = $this->runWithFirmContext($firm, fn () => DB::table('integration_connection_health')->where('firm_integration_id', $connection->id)->value('rate_limited_reset_at'));
        $this->assertNotNull($rateLimitedResetAt, 'A real SyncRetryPollJob-driven rate_limited failure must persist a real rate_limited_reset_at via HealthStateService::recordRateLimited(), reached end-to-end through resolveOneRetry()\'s own catch block — no manual orchestration.');

        $retryItemStatus = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_items')->where('firm_id', $firm->id)->where('local_id', 900003)->value('status'));
        $this->assertSame('failed_retryable', $retryItemStatus, 'resolveOneRetry() must resolve a genuine rate_limited push failure back to failed_retryable (resolveRetryOutcome), never permanently failed.');

        // ------------------------------------------------------------
        // Step 3 — credential-error, via a real, queue-processed
        // RefreshIntegrationToken dispatch: the refresh credential's
        // REAL plaintext is rotated to FAILURE_SENTINEL (never a raw DB
        // write), and the access credential is backdated so the job
        // genuinely attempts a refresh rather than short-circuiting on
        // double-checked locking — same, already-established technique
        // as ProviderConnectionServiceOAuthTest's
        // test_refresh_failure_transitions_the_connection_to_reauthorization_required.
        // ------------------------------------------------------------
        $credentialService = new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService), new TimelineEventRecorder);
        $refreshCredential = $this->runWithFirmContext($firm, fn () => IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthRefreshToken->value)
            ->first());
        $this->runWithFirmContext($firm, fn () => $credentialService->rotate($connection->fresh(), $refreshCredential, TestProvider::FAILURE_SENTINEL));
        $this->runWithFirmContext($firm, fn () => DB::table('integration_credentials')
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', CredentialType::OauthAccessToken->value)
            ->where('status', 'active')
            ->update(['expires_at' => now()->subMinute()]));

        RefreshIntegrationToken::dispatch($connection->id, $firm->id);

        $healthAfterCredentialError = $this->healthSummary($firm, $connection);
        $this->assertSame(HealthSummaryState::ActionRequired, $healthAfterCredentialError->summaryState);

        $reauthRequired = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::ReauthorizationRequired, $reauthRequired->status, 'A genuine invalid_grant refresh failure must transition the connection — this is the trigger that made recordCredentialError() fire.');

        // ------------------------------------------------------------
        // Step 4 — recovery: a real reauthorization back to Active
        // (ProviderConnectionService has no HealthStateService
        // dependency at all — confirmed by inspection — so recovery's
        // own health-state proof must come from a SUBSEQUENT real job
        // success, exactly like Step 1), then a second real,
        // queue-processed SyncRetryPollJob success.
        // ------------------------------------------------------------
        $this->reauthorize($firm, $reauthRequired, $firmUser, 'health-scenario-account');

        $activeAgain = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $activeAgain->status);

        $this->seedClaimableRetryItem($firm, $connection, 900002);
        SyncRetryPollJob::dispatch($firm->id);

        $healthAfterRecovery = $this->healthSummary($firm, $connection);
        $this->assertSame(HealthSummaryState::Healthy, $healthAfterRecovery->summaryState, 'A real recovery success must bring the state machine back to Healthy.');
        $this->assertSame(0, $healthAfterRecovery->consecutiveFailures, 'recordSuccess() must reset consecutive_failures back to zero.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function healthSummary(Firm $firm, FirmIntegration $connection): ConnectionHealthSummary
    {
        return $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->summaryFor($connection->fresh()));
    }

    /**
     * Seeds a real, claimable (status='failed_retryable', due
     * next_attempt_at, local_type/local_id known) IntegrationSyncItem
     * belonging to a real IntegrationSyncRun for $connection — exactly
     * the shape SyncRetryPollJob::handle()'s own claimForRetry() query
     * requires.
     */
    private function seedClaimableRetryItem(Firm $firm, FirmIntegration $connection, int $localId): void
    {
        $run = $this->runWithFirmContext($firm, fn () => IntegrationSyncRun::factory()
            ->forFirmIntegration($connection)
            ->create([
                'sync_direction' => SyncDirection::Outbound->value,
                'run_type' => SyncRunType::Initial->value,
                'trigger_source' => SyncTriggerSource::SchedulerPoller->value,
                'status' => SyncRunStatus::Failed->value,
            ]));

        $this->runWithFirmContext($firm, fn () => IntegrationSyncItem::factory()
            ->forSyncRun($run)
            ->failedRetryable()
            ->create([
                'local_type' => 'App\\Models\\Contact',
                'local_id' => $localId,
                'external_id' => null,
                // failedRetryable()'s own factory default sets
                // next_attempt_at 5 minutes in the FUTURE (simulating an
                // ordinary not-yet-due retry) — overridden here to a past
                // timestamp so claimForRetry()'s own
                // `next_attempt_at <= statement_timestamp()` predicate
                // genuinely matches this real dispatch.
                'next_attempt_at' => now()->subMinute(),
            ]));
    }

    private function service(): ProviderConnectionService
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
            app(IntegrationEntitlementPolicyService::class),
            // Checkpoint 3 addition (FirmsVault Live Integrations,
            // Google Workspace): ProviderConnectionService's constructor
            // gained this 9th, required dependency -- every manual
            // construction site in this file must supply it.
            app(GmailMailboxRoutingService::class),
        );
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->pending()->create(['external_account_id' => null]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    private function completeSuccessfulConnect(Firm $firm, FirmIntegration $connection, FirmUser $firmUser, string $externalAccountId): void
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        $code = (new TestProvider)->simulateAuthorizationGrant($query['code_challenge'], $externalAccountId);

        $this->service()->completeOAuthCallback($query['state'], $code, $firmUser->user_id);
    }

    private function reauthorize(Firm $firm, FirmIntegration $connection, FirmUser $firmUser, string $externalAccountId): void
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        $code = (new TestProvider)->simulateAuthorizationGrant($query['code_challenge'], $externalAccountId);

        $this->service()->completeOAuthCallback($query['state'], $code, $firmUser->user_id);
    }
}
