<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\EndToEnd;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Jobs\PushSyncJob;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TestProviderWebhookRoutedSyncAndIdempotencyScenarioTest — Checkpoint
 * 12 (frozen-design-post-security-review.md §3 "Webhook slice"/§6
 * Scenario 2). connect (service-layer) -> enableWebhookRouting() ->
 * hand-sign a payload with the connection's REAL credential-backed
 * secret using TestProvider's documented `v1=<hex>`/
 * `X-Test-Provider-Timestamp` convention -> a real HTTP POST through the
 * real, unmodified inbound webhook route, proving acceptance and
 * idempotent-on-replay -> two real, queue-processed PushSyncJob
 * dispatches carrying the SAME providerContext idempotency_key, proving
 * F4's dedup end-to-end at the job level -> the sanitized-error path,
 * proven against genuine TestProvider's real throw.
 *
 * Per §5 N1: this file never wires TestProvider's own webhook-simulation
 * methods (verifyInboundSignature()/parseInboundEvent()) into the live
 * controller — it hand-signs a payload the same way a real TestProvider-
 * originated webhook would arrive, and POSTs it through the real,
 * unmodified `POST /webhooks/integrations/{provider}` route, exactly
 * mirroring InboundWebhookSignatureVerificationTest's/
 * InboundWebhookIdempotencyTest's own established `signedHeaders()`/
 * `postWebhook()` helper pattern.
 *
 * RESOLVED DEVIATION (previously disclosed here as a workaround, now
 * fixed) for the FAILURE_SENTINEL step: this originally explained that
 * the frozen design's literal wording ("one dispatch using
 * providerContext: ['__simulate_failure' => TestProvider::FAILURE_SENTINEL],
 * assert the sanitized-error path holds with genuine TestProvider") was
 * structurally unreachable — push()'s FAILURE_SENTINEL/RATE_LIMIT_SENTINEL
 * checks read `$payload['__simulate_failure']`, never `$context`, and
 * PushSyncJob's own $payload was never merged with $providerContext. That
 * gap is now closed in App\Jobs\PushSyncJob::handle(), which routes
 * providerContext['__simulate_failure'] into $payload before calling
 * push() (the ONE key push() actually reads off $payload). Step 4 below
 * now dispatches a real, queue-processed PushSyncJob carrying
 * providerContext['__simulate_failure'] => FAILURE_SENTINEL exactly as
 * the frozen design's literal wording called for (see
 * tests/Feature/Integrations/PushSyncJobTest.php's identically-resolved
 * Checkpoint 12 addition for the same fix).
 */
final class TestProviderWebhookRoutedSyncAndIdempotencyScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

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

    public function test_the_full_connect_webhook_route_idempotency_and_sanitized_error_chain(): void
    {
        // ------------------------------------------------------------
        // Step 1: connect (service-layer, genuine TestProvider) + real
        // credential-backed webhook signing secret.
        // ------------------------------------------------------------
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->completeSuccessfulConnect($firm, $connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status);

        $rawToken = $this->service()->enableWebhookRouting($fresh, $firmUser->user_id);
        $secret = 'wh-secret-'.Str::random(32);
        $this->runWithFirmContext($firm, fn () => $this->credentialService()->store($connection->fresh(), CredentialType::WebhookSigningSecret, $secret));

        // ------------------------------------------------------------
        // Step 2: hand-sign a payload using TestProvider's documented
        // v1=<hex>/X-Test-Provider-Timestamp convention (mirrors
        // InboundWebhookSignatureVerificationTest::signedHeaders()
        // exactly) and POST it through the real, unmodified inbound
        // route. Assert acceptance, then assert idempotent-on-replay.
        // ------------------------------------------------------------
        $body = $this->eventBody();
        $headers = $this->signedHeaders($secret, $rawToken, $body);

        $firstResponse = $this->postWebhook('test', $headers, $body);
        $firstResponse->assertStatus(202);

        $eventCountAfterFirst = $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('firm_integration_id', $connection->id)
            ->count());
        $this->assertSame(1, $eventCountAfterFirst);

        $replayResponse = $this->postWebhook('test', $headers, $body);

        $this->assertSame($firstResponse->getStatusCode(), $replayResponse->getStatusCode());
        $this->assertSame($firstResponse->getContent(), $replayResponse->getContent());

        $eventCountAfterReplay = $this->runWithFirmContext($firm, fn () => DB::table('integration_inbound_webhook_events')
            ->where('firm_integration_id', $connection->id)
            ->count());
        $this->assertSame(1, $eventCountAfterReplay, 'A byte-identical replay of the same real, hand-signed request must never create a second event row.');

        // ------------------------------------------------------------
        // Step 3: F4's real proof point at the job level — UPDATED by
        // this checkpoint's fix (see class docblock): this step
        // originally forced two DIFFERENT local records to share one
        // providerContext-supplied idempotency_key. That relied on the
        // very bug this fix closes — TestProvider::push() used to read
        // ONLY $context['idempotency_key'], so a manually-injected
        // providerContext override could paper over two genuinely
        // different local records. Now that TestProvider::push() reads
        // $payload['idempotency_key'] FIRST (see its own docblock), and
        // App\Jobs\PushSyncJob::handle() ALWAYS computes its own
        // deterministic idempotency_key over (connection, resource_type,
        // local_type, local_id, local_version_token) into $payload, the
        // job's own real key correctly wins — and correctly differs
        // for two different local records, so they no longer dedupe.
        // That is CORRECT production behavior, not a regression: two
        // distinct local records must never share one provider-side
        // idempotency key by accident.
        //
        // The genuine F4 proof at the job level is therefore: two real,
        // queue-processed PushSyncJob dispatches for the EXACT SAME
        // local record/version (e.g. a re-delivered/re-processed job),
        // with NO providerContext involved at all — PushSyncJob computes
        // the IDENTICAL idempotency_key both times purely from its own
        // deterministic hash, so genuine TestProvider must return its
        // cached response. refreshVersionTokens() overwrites
        // external_version_token with WHATEVER genuine TestProvider
        // returns on the second dispatch: if dedup did NOT fire,
        // TestProvider's fresh Str::random(12) would (with overwhelming
        // probability) differ from the first dispatch's token; if dedup
        // DID fire, it is byte-for-byte identical.
        // ------------------------------------------------------------
        $this->assertNoDatabaseTenantContext();

        PushSyncJob::dispatch($connection->id, $firm->id, 'contact', 'App\\Models\\Contact', 700100, 'local-v1');

        $this->assertNoDatabaseTenantContext();

        $firstPushMapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 700100)
            ->first());
        $this->assertNotNull($firstPushMapping);
        $firstExternalVersionToken = $firstPushMapping->external_version_token;

        PushSyncJob::dispatch($connection->id, $firm->id, 'contact', 'App\\Models\\Contact', 700100, 'local-v1');

        $secondPushMapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 700100)
            ->first());
        $this->assertNotNull($secondPushMapping);
        $this->assertSame($firstPushMapping->id, $secondPushMapping->id, 'The SAME mapping row must be refreshed on the second dispatch, never a second, duplicate row.');

        $this->assertSame(
            $firstExternalVersionToken,
            $secondPushMapping->external_version_token,
            'Two real, queue-processed PushSyncJob dispatches for the identical local record/version must compute the IDENTICAL idempotency_key (the job\'s own deterministic hash) both times, so genuine TestProvider must return its cached response (the same external_version_token) rather than a freshly generated one — proving F4\'s dedup fired via the job\'s own real computed key, with zero providerContext involved.'
        );

        // ------------------------------------------------------------
        // Step 4: the sanitized-error path — RESOLVED DEVIATION (see
        // class docblock's "DISCLOSED DEVIATION" note, now fixed):
        // App\Jobs\PushSyncJob::handle() now routes
        // providerContext['__simulate_failure'] into $payload before
        // calling push() (the ONE key TestProvider::push()'s sentinel
        // checks actually read off $payload), so this is now proven via
        // a real, queue-processed PushSyncJob dispatch carrying
        // providerContext — the exact mechanism the frozen design's
        // literal wording called for — rather than a manually
        // orchestrated provider/httpClient pairing.
        // ------------------------------------------------------------
        $this->assertNoDatabaseTenantContext();

        PushSyncJob::dispatch($connection->id, $firm->id, 'contact', 'App\\Models\\Contact', 700102, 'local-v1', providerContext: json_encode(['__simulate_failure' => TestProvider::FAILURE_SENTINEL]));

        $this->assertNoDatabaseTenantContext();

        $failedRun = $this->runWithFirmContext($firm, fn () => DB::table('integration_sync_runs')
            ->where('firm_integration_id', $connection->id)
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($failedRun);
        $this->assertSame('failed', $failedRun->status, 'A real PushSyncJob dispatch carrying providerContext[\'__simulate_failure\'] => FAILURE_SENTINEL must now genuinely reach and throw inside genuine TestProvider::push(), routed through $payload by this checkpoint\'s fix.');
        $this->assertStringContainsString('provider_rejected', $failedRun->error_summary);

        // Mirrors PushSyncJob::handle()'s own real catch-block
        // formatting exactly (`"push_failed: {$e->category()}"`) — the
        // sanitized summary a real job actually persists to
        // integration_sync_runs.error_summary must never contain
        // TestProvider's own raw, internal exception message.
        $this->assertStringNotContainsString('Simulated provider failure', $failedRun->error_summary);

        $failedMapping = $this->runWithFirmContext($firm, fn () => IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('local_id', 700102)
            ->first());
        $this->assertNull($failedMapping, 'A genuinely failed push must never create a mapping row.');
    }

    // ------------------------------------------------------------
    // Helpers — mirrors ProviderConnectionServiceOAuthTest's/
    // InboundWebhookSignatureVerificationTest's established shapes,
    // kept self-contained in this file.
    // ------------------------------------------------------------

    private function service(): ProviderConnectionService
    {
        return new ProviderConnectionService(
            new IntegrationOAuthStateService(
                new EmailBodyEncryptionService(new EncryptionKeyService()),
                new PkceService(),
                new ProviderRedirectUrlValidator(),
            ),
            $this->credentialService(),
            new IntegrationAccessPolicyService(new TimelineEventRecorder()),
            new \App\Integrations\Core\ProviderRegistry(),
            new OutboundProviderHttpClient(),
            new ProviderRedirectUrlValidator(),
            new TimelineEventRecorder(),
            app(IntegrationEntitlementPolicyService::class),
        );
    }

    private function credentialService(): IntegrationCredentialService
    {
        return new IntegrationCredentialService(new EmailBodyEncryptionService(new EncryptionKeyService()));
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

    private function completeSuccessfulConnect(Firm $firm, FirmIntegration $connection, FirmUser $firmUser): void
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        $code = (new TestProvider())->simulateAuthorizationGrant($query['code_challenge']);

        $this->service()->completeOAuthCallback($query['state'], $code, $firmUser->user_id);
    }

    private function eventBody(): string
    {
        return json_encode([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'test.resource.created',
            'payload' => ['foo' => 'bar'],
        ]);
    }

    private function signedHeaders(string $secret, string $rawToken, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.'.'.$body, $secret);

        return [
            'X-Test-Provider-Connection-Token' => $rawToken,
            'X-Test-Provider-Signature' => $signature,
            'X-Test-Provider-Timestamp' => (string) $timestamp,
        ];
    }

    private function postWebhook(string $provider, array $headers, string $body): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
