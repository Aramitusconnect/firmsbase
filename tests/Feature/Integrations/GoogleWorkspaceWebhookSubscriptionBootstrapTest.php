<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * GoogleWorkspaceWebhookSubscriptionBootstrapTest — Checkpoint 3
 * (FirmsVault Live Integrations, Google Workspace provider) test-writing
 * pass, mirroring `ProviderConnectionServiceCapabilityThreadingTest.php`'s
 * style.
 *
 * Proves the new, generic, provider-agnostic
 * `ProviderConnectionService::bootstrapWebhookSubscriptions()`
 * orchestration (checkpoint3-combined-design.md §4.7;
 * checkpoint3-security-review.md Finding 1, required) actually gets
 * called on OAuth completion for ANY `SupportsWebhooksContract` provider,
 * and correctly persists `IntegrationProviderWebhookSubscription` rows —
 * including the one test this checkpoint's security review specifically
 * flagged as an important regression-closing proof, not scope creep:
 * this exact same generic addition retroactively fixes a REAL,
 * pre-existing Microsoft 365 defect (no webhook subscription was ever
 * created on connect before this checkpoint — the only pre-existing
 * production call site of `->subscribe(` was
 * `RenewGraphSubscriptionJob.php:176`'s renewal-schedule fallback, never
 * reached on a first connect).
 *
 * Every network-shaped call goes through Http::fake([...]) — mandatory
 * given tests/TestCase.php's suite-wide Http::preventStrayRequests()
 * guard. No real Google/Microsoft credentials or endpoints are ever
 * used.
 */
final class GoogleWorkspaceWebhookSubscriptionBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const GW_CLIENT_ID = 'unit-test-gw-client-id-0002';

    private const GW_CLIENT_SECRET = 'unit-test-gw-client-secret-0002';

    private const GW_TOKEN_BASE = 'https://oauth2.googleapis.test';

    private const GW_GMAIL_BASE = 'https://gmail.googleapis.test';

    private const GW_CALENDAR_BASE = 'https://calendar.googleapis.test';

    private const GW_DRIVE_BASE = 'https://drive.googleapis.test';

    private const GW_HMAC_KEY = 'unit-test-gmail-mailbox-routing-hmac-key-fixture-0002';

    private const MS_CLIENT_ID = 'unit-test-ms-client-id-0002';

    private const MS_CLIENT_SECRET = 'unit-test-ms-client-secret-0002';

    private const MS_IDENTITY_BASE = 'https://login.microsoftonline.test';

    private const MS_GRAPH_BASE = 'https://graph.microsoft.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://app.firmsbase.test']);
        URL::forceRootUrl('https://app.firmsbase.test');
        URL::forceScheme('https');
    }

    // ------------------------------------------------------------
    // Google Workspace — the primary, day-one-correct case
    // ------------------------------------------------------------

    public function test_google_workspace_oauth_completion_bootstraps_a_gmail_webhook_subscription_row(): void
    {
        $this->configureGoogleWorkspace();

        [$firm, $connection, $firmUser] = $this->googleFirmConnectionAndActor([ResourceType::Message->value]);

        $this->fakeGoogleTokenProfileAndWatch('bootstrap-proof@example.test');

        $result = $this->connectGoogle($connection, $firmUser);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->first()
        );

        $this->assertNotNull($subscription);
        $this->assertSame('googleworkspace', $subscription->provider_key);
        $this->assertSame(ResourceType::Message->value, $subscription->resource_type);
        $this->assertSame('gmail:me', $subscription->provider_resource);
        $this->assertSame('active', $subscription->status);
        $this->assertNotNull($subscription->provider_subscription_id);
        $this->assertNotNull($subscription->expires_at);
    }

    /**
     * checkpoint3-security-review.md Finding 1's own headline claim:
     * "Microsoft 365 (Checkpoint 2) has a real, pre-existing production
     * defect: no webhook subscription is ever created for any
     * connection." This proves the SAME generic
     * `bootstrapWebhookSubscriptions()` addition — built for Google —
     * fixes Microsoft 365 too, with zero Microsoft-specific code change.
     */
    public function test_microsoft_365_oauth_completion_now_bootstraps_a_webhook_subscription_closing_the_pre_existing_regression(): void
    {
        $this->configureMicrosoft365();

        $firm = $this->firmWithActiveKey();
        $provider = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->firstOrFail();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($provider)
                ->pending()
                ->create(['external_account_id' => null, 'requested_capabilities_json' => [ResourceType::Contact->value]])
        );
        $firmUser = $this->firmUserFor($firm, FirmUserRole::Attorney);

        $idToken = $this->fakeMicrosoftIdToken([
            'aud' => self::MS_CLIENT_ID,
            'iss' => 'https://login.microsoftonline.com/tenant-regression-fix/v2.0',
            'tid' => 'tenant-regression-fix',
            'oid' => 'user-object-id-regression-fix',
        ]);

        Http::fake([
            self::MS_IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-ms-access-token',
                'refresh_token' => 'fake-ms-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'offline_access openid profile Contacts.Read',
                'id_token' => $idToken,
            ], 200),
            self::MS_GRAPH_BASE.'/v1.0/subscriptions' => Http::response([
                'id' => 'graph-subscription-regression-fix',
                'resource' => 'me/contacts',
                'changeType' => 'created,updated,deleted',
                'expirationDateTime' => now()->addHours(70)->toIso8601String(),
            ], 201),
        ]);

        $result = $this->connectMicrosoft($connection, $firmUser);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $subscription = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->first()
        );

        $this->assertNotNull(
            $subscription,
            'Before this checkpoint, no code path anywhere ever called subscribe() on a first connect for ANY provider, '
            .'including Microsoft 365 — this is the regression-closing proof that the generic bootstrapWebhookSubscriptions() '
            .'orchestration also fixes Microsoft 365, not just Google Workspace.'
        );
        $this->assertSame('microsoft365', $subscription->provider_key);
        $this->assertSame(ResourceType::Contact->value, $subscription->resource_type);
        $this->assertSame('me/contacts', $subscription->provider_resource);
        $this->assertSame('active', $subscription->status);

        Http::assertSent(fn (HttpClientRequest $request): bool => (string) $request->url() === self::MS_GRAPH_BASE.'/v1.0/subscriptions');
    }

    // ------------------------------------------------------------
    // Intersection logic — only pullable AND requested resource types
    // ------------------------------------------------------------

    public function test_bootstrap_only_subscribes_to_resource_types_that_are_both_pullable_and_requested(): void
    {
        $this->configureGoogleWorkspace();

        // Requests ONLY CalendarEvent — Message and Document are both
        // pullable by GoogleWorkspaceProvider but were never requested,
        // so subscribe() must never be called for either.
        [$firm, $connection, $firmUser] = $this->googleFirmConnectionAndActor([ResourceType::CalendarEvent->value]);

        $idToken = $this->fakeGoogleIdToken('calendar-only@example.test');

        Http::fake([
            self::GW_TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
                'id_token' => $idToken,
            ], 200),
            self::GW_CALENDAR_BASE.'/calendar/v3/calendars/primary/events/watch' => Http::response([
                'id' => 'calendar-channel-id-001',
                'expiration' => (string) now()->addDays(7)->getTimestampMs(),
            ], 200),
        ]);

        $result = $this->connectGoogle($connection, $firmUser);

        $this->assertTrue($result->successful);

        $subscriptions = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->get()
        );

        $this->assertCount(1, $subscriptions, 'Exactly one subscription (Calendar) must be created — never Message or Document, which were not requested.');
        $this->assertSame(ResourceType::CalendarEvent->value, $subscriptions->first()->resource_type);
        $this->assertSame('calendar:primary', $subscriptions->first()->provider_resource);

        // Gmail's watch()/profile endpoints were never faked at all —
        // if subscribe() incorrectly reached Gmail for the unrequested
        // Message capability, Http::preventStrayRequests() would have
        // already failed this test loudly. This is the explicit,
        // additional confirmation.
        Http::assertNotSent(fn (HttpClientRequest $request): bool => str_contains((string) $request->url(), 'gmail'));

        // No Gmail mailbox route either — route() is only ever reached
        // from the Message branch of subscribe().
        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count());
    }

    // ------------------------------------------------------------
    // Idempotency on reauthorization
    // ------------------------------------------------------------

    public function test_bootstrap_is_idempotent_and_does_not_duplicate_an_already_active_subscription_on_reauthorization(): void
    {
        $this->configureGoogleWorkspace();

        [$firm, $connection, $firmUser] = $this->googleFirmConnectionAndActor([ResourceType::Message->value]);

        $this->fakeGoogleTokenProfileAndWatch('idempotent-reauth@example.test');
        $first = $this->connectGoogle($connection, $firmUser);
        $this->assertTrue($first->successful);

        $countAfterFirst = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->count()
        );
        $this->assertSame(1, $countAfterFirst);

        // Reauthorize the SAME connection (same mailbox/account) a
        // second time — re-fakes every endpoint so a real duplicate call
        // would silently "succeed" if not caught explicitly below.
        $this->fakeGoogleTokenProfileAndWatch('idempotent-reauth@example.test');
        $second = $this->connectGoogle($connection, $firmUser);
        $this->assertTrue($second->successful);

        $countAfterSecond = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->count()
        );
        $this->assertSame(1, $countAfterSecond, 'A reauthorization must never create a second subscription row for the same resource type.');

        Http::assertNotSent(fn (HttpClientRequest $request): bool => str_contains((string) $request->url(), '/gmail/v1/users/me/watch'));
    }

    // ------------------------------------------------------------
    // No-op when nothing was requested
    // ------------------------------------------------------------

    /**
     * `GoogleWorkspaceProvider::requiredScopes()` itself throws on a
     * genuinely EMPTY `requested_capabilities` context (never a silent
     * broad-scope fallback — checkpoint3-combined-design.md §4.2), so a
     * connection can never complete OAuth with literally zero requested
     * capabilities. The real no-op case this proves instead: a
     * connection whose requested capability (`Contact`) is valid enough
     * to pass `requiredScopes()` (it simply contributes no extra scope —
     * Google has no Contact/People-API bundle at all, per §10 of the
     * combined design) but is NOT one of
     * `GoogleWorkspaceProvider::pullableResourceTypes()`'s three values
     * — proving `bootstrapWebhookSubscriptions()`'s own
     * `array_intersect()` correctly yields nothing to subscribe to, ever
     * reaching neither Gmail, Calendar, nor Drive.
     */
    public function test_bootstrap_is_a_no_op_when_the_connections_requested_capability_is_not_pullable_by_this_provider(): void
    {
        $this->configureGoogleWorkspace();

        [$firm, $connection, $firmUser] = $this->googleFirmConnectionAndActor([ResourceType::Contact->value]);

        // Only the token endpoint is faked — if bootstrapWebhookSubscriptions()
        // incorrectly attempted a Gmail/Calendar/Drive subscribe() call
        // for an unrequested/unsupported capability,
        // Http::preventStrayRequests() would fail this test loudly.
        $idToken = $this->fakeGoogleIdToken('no-pullable-capability@example.test');

        Http::fake([
            self::GW_TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'id_token' => $idToken,
            ], 200),
        ]);

        $result = $this->connectGoogle($connection, $firmUser);

        $this->assertTrue($result->successful);

        $subscriptionCount = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_provider_webhook_subscriptions')->where('firm_integration_id', $connection->id)->count()
        );
        $this->assertSame(0, $subscriptionCount);
        $this->assertSame(0, DB::table('integration_gmail_mailbox_routes')->where('firm_integration_id', $connection->id)->count());
    }

    // ------------------------------------------------------------
    // Helpers — Google Workspace
    // ------------------------------------------------------------

    private function configureGoogleWorkspace(): void
    {
        config(['integrations.providers' => [ProviderKey::GoogleWorkspace->value => GoogleWorkspaceProvider::class]]);

        config([
            'integrations.oauth_apps.googleworkspace.client_id' => self::GW_CLIENT_ID,
            'integrations.oauth_apps.googleworkspace.client_secret' => self::GW_CLIENT_SECRET,
            'integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => self::GW_HMAC_KEY,
            'integrations.provider_environments.'.ProviderKey::GoogleWorkspace->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'token' => self::GW_TOKEN_BASE,
                    'gmail' => self::GW_GMAIL_BASE,
                    'calendar' => self::GW_CALENDAR_BASE,
                    'drive' => self::GW_DRIVE_BASE,
                ],
                'live_base_urls' => [
                    'token' => self::GW_TOKEN_BASE,
                    'gmail' => self::GW_GMAIL_BASE,
                    'calendar' => self::GW_CALENDAR_BASE,
                    'drive' => self::GW_DRIVE_BASE,
                ],
            ],
        ]);
    }

    private function googleWorkspaceProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::GoogleWorkspace->value)->firstOrFail();
    }

    /**
     * @param  string[]|null  $requestedCapabilities
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function googleFirmConnectionAndActor(?array $requestedCapabilities): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->googleWorkspaceProviderRow())
                ->pending()
                ->create(['external_account_id' => null, 'requested_capabilities_json' => $requestedCapabilities])
        );
        $firmUser = $this->firmUserFor($firm, FirmUserRole::Attorney);

        return [$firm, $connection, $firmUser];
    }

    private function connectGoogle(FirmIntegration $connection, FirmUser $firmUser)
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $service = app(ProviderConnectionService::class);
        $initiation = $service->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($initiation->authorizationUrl, PHP_URL_QUERY), $query);

        return $service->completeOAuthCallback($query['state'], 'unit-test-auth-code-'.$connection->id, $firmUser->user_id);
    }

    private function fakeGoogleTokenProfileAndWatch(string $emailAddress): void
    {
        $idToken = $this->fakeGoogleIdToken($emailAddress);

        Http::fake([
            self::GW_TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
                'id_token' => $idToken,
            ], 200),
            self::GW_GMAIL_BASE.'/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => $emailAddress,
                'historyId' => '1000',
            ], 200),
            self::GW_GMAIL_BASE.'/gmail/v1/users/me/watch' => Http::response([
                'historyId' => '1000',
                'expiration' => (string) now()->addDays(7)->getTimestampMs(),
            ], 200),
        ]);
    }

    private function fakeGoogleIdToken(string $emailAddress): string
    {
        return $this->fakeIdToken([
            'aud' => self::GW_CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'sub' => 'google-sub-'.hash('crc32', $emailAddress),
            'hd' => 'example.test',
            'exp' => time() + 3600,
        ]);
    }

    // ------------------------------------------------------------
    // Helpers — Microsoft 365 (regression-closing proof only)
    // ------------------------------------------------------------

    private function configureMicrosoft365(): void
    {
        config(['integrations.providers' => [ProviderKey::Microsoft365->value => Microsoft365Provider::class]]);

        config([
            'integrations.oauth_apps.microsoft365.client_id' => self::MS_CLIENT_ID,
            'integrations.oauth_apps.microsoft365.client_secret' => self::MS_CLIENT_SECRET,
            'integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'identity' => self::MS_IDENTITY_BASE,
                    'graph' => self::MS_GRAPH_BASE,
                ],
                'live_base_urls' => [
                    'identity' => self::MS_IDENTITY_BASE,
                    'graph' => self::MS_GRAPH_BASE,
                ],
            ],
        ]);
    }

    private function connectMicrosoft(FirmIntegration $connection, FirmUser $firmUser)
    {
        $redirectUri = route('integrations.oauth.callback', [], true);
        $service = app(ProviderConnectionService::class);
        $initiation = $service->initiateOAuthConnection($connection, $firmUser->user_id, $redirectUri);

        $query = [];
        parse_str((string) parse_url($initiation->authorizationUrl, PHP_URL_QUERY), $query);

        return $service->completeOAuthCallback($query['state'], 'unit-test-ms-auth-code-'.$connection->id, $firmUser->user_id);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function fakeMicrosoftIdToken(array $claims): string
    {
        return $this->fakeIdToken($claims);
    }

    // ------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------

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
     * Builds a base64url-encoded, UNSIGNED fake JWT (header.payload.signature)
     * carrying the given claims as its JSON payload — identical,
     * already-established pattern to
     * Microsoft365ProviderOAuthTest::fakeIdToken()/
     * GmailMailboxRoutingLifecycleTest::fakeIdToken(). Neither provider's
     * exchangeCodeForToken()/decodeAndValidateIdToken() verifies a JWS
     * signature (a deliberate, already-security-reviewed scope
     * limitation for this specific back-channel trust boundary — see
     * each provider's own docblock).
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeIdToken(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'none', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode('unsigned-test-fixture-signature'), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$signature}";
    }
}
