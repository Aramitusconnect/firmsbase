<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationOAuthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EmailBodyEncryptionService;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TimelineEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * ProviderConnectionServiceCapabilityThreadingTest — Checkpoint 2
 * (FirmsVault Live Integrations) test-writing pass. Proves the
 * capability-selection surface (checkpoint2-combined-design.md §1.1,
 * §2 P-6a/P-6b/P-6h):
 *
 *   - startConnection()'s optional $requestedCapabilities persistence
 *     AND its defense-in-depth subset validation against the resolved
 *     provider's ProviderMetadata::resourceTypes.
 *   - initiateOAuthConnection() threading the connection's
 *     requested_capabilities_json into requiredScopes()'s new
 *     $context parameter.
 *   - updateRequestedCapabilities()'s column update, TOCTOU-safe
 *     re-authorization, and audit event.
 *   - The auto-enableWebhookRouting() call on a successful
 *     finishCallback() completion (P-6g) — ONLY for a provider
 *     implementing SupportsWebhooksContract.
 *
 * Uses the real, registered TestProvider (pullableResourceTypes()/
 * pushableResourceTypes() both return [Contact, Task] — a fixed, known
 * vocabulary this file relies on to pick a genuinely-unsupported
 * capability) for every test that does not need to inspect the exact
 * $context requiredScopes() received or control SupportsWebhooksContract
 * implementation — those two needs are served by small, deterministic
 * fake providers, mirroring this suite's established
 * ProviderConnectionServiceRefreshScopeDowngradeTest precedent.
 */
final class ProviderConnectionServiceCapabilityThreadingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> firm_id => TenantEncryptionKey id */
    private array $encryptionKeyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();

        Http::fake();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // startConnection() persistence
    // ------------------------------------------------------------

    public function test_start_connection_persists_the_requested_capabilities_json(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $connection = $this->service()->startConnection(
            $firm->id,
            $provider->id,
            $firmUser->user_id,
            [ResourceType::Contact->value],
        );

        $this->assertSame([ResourceType::Contact->value], $connection->requested_capabilities_json);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connection->id));
        $this->assertSame([ResourceType::Contact->value], $fresh->requested_capabilities_json);
    }

    public function test_start_connection_with_null_requested_capabilities_performs_no_validation_and_persists_null(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $connection = $this->service()->startConnection($firm->id, $provider->id, $firmUser->user_id);

        $this->assertNull($connection->requested_capabilities_json, 'Omitting $requestedCapabilities entirely must preserve today\'s exact behavior: null persisted, no validation performed.');
    }

    // ------------------------------------------------------------
    // startConnection() defense-in-depth: reject a capability not in
    // ProviderMetadata::resourceTypes (a tampered client payload).
    // ------------------------------------------------------------

    public function test_start_connection_rejects_a_capability_not_supported_by_the_resolved_provider(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        // TestProvider::pullableResourceTypes()/pushableResourceTypes()
        // both return exactly [Contact, Task] — Message is genuinely
        // never declared by this provider, simulating a tampered client
        // payload requesting an unsupported capability.
        try {
            $this->service()->startConnection(
                $firm->id,
                $provider->id,
                $firmUser->user_id,
                [ResourceType::Message->value],
            );
            $this->fail('Expected a RuntimeException for a capability the resolved provider does not support.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(ResourceType::Message->value, $e->getMessage());
        }

        // And no row must have been created at all.
        $count = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(0, $count, 'A rejected capability-validation attempt must create no row.');
    }

    public function test_start_connection_allows_a_capability_list_that_is_a_genuine_subset(): void
    {
        [$firm, $provider, $firmUser] = $this->firmProviderAndActor();

        $connection = $this->service()->startConnection(
            $firm->id,
            $provider->id,
            $firmUser->user_id,
            [ResourceType::Contact->value, ResourceType::Task->value],
        );

        $this->assertSame([ResourceType::Contact->value, ResourceType::Task->value], $connection->requested_capabilities_json);
    }

    // ------------------------------------------------------------
    // initiateOAuthConnection() threads requested_capabilities_json
    // into requiredScopes()'s $context.
    // ------------------------------------------------------------

    public function test_initiate_oauth_connection_threads_the_connections_requested_capabilities_into_required_scopes_context(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities([ResourceType::Contact->value]);

        $captured = null;
        $this->registerCapabilityRecordingProvider(function (array $context) use (&$captured): void {
            $captured = $context;
        });

        $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, route('integrations.oauth.callback', [], true));

        $this->assertNotNull($captured, 'requiredScopes() must have been called by initiateOAuthConnection().');
        $this->assertSame(['requested_capabilities' => [ResourceType::Contact->value]], $captured);
    }

    public function test_initiate_oauth_connection_threads_an_empty_array_when_the_connection_has_no_requested_capabilities(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities(null);

        $captured = null;
        $this->registerCapabilityRecordingProvider(function (array $context) use (&$captured): void {
            $captured = $context;
        });

        $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, route('integrations.oauth.callback', [], true));

        $this->assertSame(['requested_capabilities' => []], $captured, 'A null requested_capabilities_json must thread through as an empty array, never omitted entirely.');
    }

    // ------------------------------------------------------------
    // updateRequestedCapabilities()
    // ------------------------------------------------------------

    public function test_update_requested_capabilities_updates_the_column_and_fires_an_audit_event(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities([ResourceType::Contact->value], FirmUserRole::Attorney);

        $updated = $this->service()->updateRequestedCapabilities($connection, [ResourceType::Task->value], $firmUser->user_id);

        $this->assertSame([ResourceType::Task->value], $updated->requested_capabilities_json);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame([ResourceType::Task->value], $fresh->requested_capabilities_json);

        $event = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('event_type', 'integration_oauth.requested_capabilities_updated')
            ->where('subject_type', FirmIntegration::class)
            ->where('subject_id', $connection->id)
            ->latest('id')
            ->first());

        $this->assertNotNull($event, 'Expected an integration_oauth.requested_capabilities_updated audit event.');
        $this->assertSame($connection->id, $event->metadata_json['firm_integration_id'] ?? null);
        $this->assertSame([ResourceType::Task->value], $event->metadata_json['requested_capabilities'] ?? null);
    }

    /**
     * TOCTOU-safe: updateRequestedCapabilities() re-checks authorization
     * (assertCanConfigure()) at call time — a role below the configure
     * ceiling must be rejected even if it was somehow valid earlier.
     */
    public function test_update_requested_capabilities_re_checks_authorization_and_denies_a_role_below_the_configure_ceiling(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities([ResourceType::Contact->value], FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        $this->service()->updateRequestedCapabilities($connection, [ResourceType::Task->value], $firmUser->user_id);
    }

    public function test_update_requested_capabilities_leaves_the_column_unchanged_when_authorization_is_denied(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities([ResourceType::Contact->value], FirmUserRole::Paralegal);

        try {
            $this->service()->updateRequestedCapabilities($connection, [ResourceType::Task->value], $firmUser->user_id);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame([ResourceType::Contact->value], $fresh->requested_capabilities_json);
    }

    public function test_update_requested_capabilities_rejects_an_unsupported_capability(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities([ResourceType::Contact->value], FirmUserRole::Attorney);

        $this->expectException(RuntimeException::class);

        $this->service()->updateRequestedCapabilities($connection, [ResourceType::Message->value], $firmUser->user_id);
    }

    // ------------------------------------------------------------
    // Auto webhook-routing-enable on a successful finishCallback()
    // ------------------------------------------------------------

    public function test_successful_callback_auto_enables_webhook_routing_for_a_webhooks_capable_provider(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities(null);

        $flow = $this->initiateFlow($connection, $firmUser);
        // TestProvider implements SupportsWebhooksContract — see this
        // file's own class docblock.
        $code = (new TestProvider)->simulateAuthorizationGrant($flow['codeChallenge']);
        $result = $this->service()->completeOAuthCallback($flow['rawState'], $code, $firmUser->user_id);

        $this->assertTrue($result->successful);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNotNull($fresh->webhook_routing_token, 'A provider implementing SupportsWebhooksContract must have webhook routing auto-enabled on a successful callback.');
    }

    public function test_successful_callback_does_not_enable_webhook_routing_for_a_provider_that_does_not_implement_the_webhooks_contract(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionWithCapabilities(null);
        $this->registerNonWebhookOAuthProvider();

        $flow = $this->initiateFlow($connection, $firmUser);
        $result = $this->service()->completeOAuthCallback($flow['rawState'], Str::random(20), $firmUser->user_id);

        $this->assertTrue($result->successful);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNull($fresh->webhook_routing_token, 'A provider that does NOT implement SupportsWebhooksContract must never have webhook routing auto-enabled.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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

    private function makeTestProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);
    }

    /**
     * @return array{0: Firm, 1: IntegrationProvider, 2: FirmUser}
     */
    private function firmProviderAndActor(FirmUserRole $role = FirmUserRole::FirmOwner): array
    {
        $firm = $this->firmWithActiveKey();
        $provider = $this->makeTestProviderRow();
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $provider, $firmUser];
    }

    /**
     * A connection created DIRECTLY via the factory (bypassing
     * startConnection()) with a specific requested_capabilities_json
     * already set — isolates each of these tests to the ONE behavior it
     * is actually about (initiateOAuthConnection()'s threading,
     * updateRequestedCapabilities()'s update/authorization/audit) rather
     * than also re-exercising startConnection()'s own validation.
     *
     * @param  string[]|null  $capabilities
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionWithCapabilities(?array $capabilities, FirmUserRole $role = FirmUserRole::Attorney): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->pending()
            ->create([
                'external_account_id' => null,
                'requested_capabilities_json' => $capabilities,
                // FirmIntegrationFactory::definition() defaults this to a
                // random token regardless of the pending() state — must
                // be explicitly nulled here so the auto-enableWebhookRouting()
                // tests below can actually distinguish "already had a
                // token from the fixture" from "the callback itself
                // enabled it".
                'webhook_routing_token' => null,
            ]));
        $firmUser = $this->firmUserFor($firm, $role);

        return [$firm, $connection, $firmUser];
    }

    private function firmWithActiveKey(): Firm
    {
        $firm = Firm::factory()->create();
        $key = $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $this->encryptionKeyIds[$firm->id] = $key->id;

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function firmUserFor(Firm $firm, FirmUserRole $role): FirmUser
    {
        $user = User::factory()->create();

        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create());
    }

    /**
     * @return array{result: OAuthInitiationResult, rawState: string, codeChallenge: string}
     */
    private function initiateFlow(FirmIntegration $connection, FirmUser $firmUser): array
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $result = $this->service()->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($result->authorizationUrl, PHP_URL_QUERY), $query);

        return [
            'result' => $result,
            'rawState' => $query['state'],
            'codeChallenge' => $query['code_challenge'] ?? '',
        ];
    }

    /**
     * A minimal fake OAuth provider whose requiredScopes() records
     * exactly the $context it was called with, via an injected closure —
     * lets initiateOAuthConnection()'s threading be asserted directly
     * without needing to inspect a real scope string.
     */
    private function registerCapabilityRecordingProvider(\Closure $onRequiredScopes): void
    {
        $provider = new class($onRequiredScopes) implements IntegrationProviderContract, SupportsOAuthContract
        {
            public function __construct(private readonly \Closure $onRequiredScopes) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Capability-Recording OAuth Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider — records the $context requiredScopes() was called with.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::OAuth2];
            }

            public function authorizationUrl(array $params): string
            {
                return 'https://fake-oauth-provider.invalid/authorize?'.http_build_query($params);
            }

            public function exchangeCodeForToken(string $code, array $context): array
            {
                throw new RuntimeException('exchangeCodeForToken() is not exercised by this test fixture.');
            }

            public function refreshToken(string $refreshToken, array $context = []): array
            {
                throw new RuntimeException('refreshToken() is not exercised by this test fixture.');
            }

            public function requiredScopes(array $context = []): array
            {
                ($this->onRequiredScopes)($context);

                return ['test.read'];
            }

            public function capabilityScopeMap(): array
            {
                return [];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }

    /**
     * A minimal fake OAuth provider that does NOT implement
     * SupportsWebhooksContract — the negative fixture for the
     * auto-enableWebhookRouting() proof.
     */
    private function registerNonWebhookOAuthProvider(): void
    {
        $requiredScopes = ['test.read'];

        $provider = new class($requiredScopes) implements IntegrationProviderContract, SupportsOAuthContract
        {
            public function __construct(private readonly array $requiredScopes) {}

            public function key(): ProviderKey
            {
                return ProviderKey::Test;
            }

            public function displayName(): string
            {
                return 'Fake Non-Webhook OAuth Provider';
            }

            public function description(): string
            {
                return 'Deterministic test fixture provider — deliberately does NOT implement SupportsWebhooksContract.';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function supportedAuthMethods(): array
            {
                return [AuthMethod::OAuth2];
            }

            public function authorizationUrl(array $params): string
            {
                return 'https://fake-oauth-provider.invalid/authorize?'.http_build_query($params);
            }

            public function exchangeCodeForToken(string $code, array $context): array
            {
                return [
                    'access_token' => Str::random(40),
                    'refresh_token' => Str::random(40),
                    'token_type' => 'bearer',
                    'expires_in' => 3600,
                    'scope' => implode(' ', $this->requiredScopes),
                    'external_account_id' => 'fake-account-'.Str::random(8),
                ];
            }

            public function refreshToken(string $refreshToken, array $context = []): array
            {
                throw new RuntimeException('refreshToken() is not exercised by this test fixture.');
            }

            public function requiredScopes(array $context = []): array
            {
                return $this->requiredScopes;
            }

            public function capabilityScopeMap(): array
            {
                return [];
            }
        };

        $class = get_class($provider);
        app()->instance($class, $provider);
        config(['integrations.providers' => [ProviderKey::Test->value => $class]]);
    }
}
