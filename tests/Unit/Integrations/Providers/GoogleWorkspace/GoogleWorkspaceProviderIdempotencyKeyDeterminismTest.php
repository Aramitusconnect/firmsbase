<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\GoogleWorkspace;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Google\Auth\AccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GoogleWorkspaceProviderIdempotencyKeyDeterminismTest — remediation of
 * the independent security review's wall-clock `usageIdempotencyKey`
 * finding.
 *
 * SIX sites in GoogleWorkspaceProvider built their usage idempotency key
 * from `now()->format('YmdHi')`:
 *
 *   1. refreshToken()                  — `oauth_refresh:{conn}:{YmdHi}`
 *   2. revokeAtProvider()              — `oauth_revoke:{conn}:{YmdHi}`
 *   3. fetchGmailStartingHistoryId()   — `pull:{conn}:message:profile:{YmdHi}`
 *   4. fetchDriveStartPageToken()      — `pull:{conn}:document:start_page_token:{YmdHi}`
 *   5. callGmailWatch()                — `webhook_subscribe:{conn}:gmail:{YmdHi}`
 *   6. fetchGmailProfileEmailAddress() — `webhook_subscribe:{conn}:gmail_profile:{YmdHi}`
 *
 * See Microsoft365ProviderIdempotencyKeyDeterminismTest's own docblock
 * for the full statement of why this matters even though
 * GoogleWorkspaceProvider is not routed through the billable-call
 * reservation pipeline today, and for why every assertion here reads the
 * REAL `Idempotency-Key` header
 * `App\Integrations\Support\ProviderRequestExecutor::send()` put on the
 * wire (the same string it hands to
 * `IntegrationUsageRecorderService::recordOnce()`) rather than a
 * reflected private helper.
 *
 * Site 4 additionally carried a SECOND defect the wall-clock key was
 * masking: fetchDriveStartPageToken() has TWO callers driving two
 * genuinely different logical operations (pullDriveFullList()'s
 * terminal-page baseline capture and callDriveWatch()'s pre-watch
 * change-stream position fetch), and the old key shape made them
 * indistinguishable whenever they landed in the same minute. That
 * separation is proved below too.
 */
final class GoogleWorkspaceProviderIdempotencyKeyDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-gw-client-id-0001';

    private const CLIENT_SECRET = 'unit-test-gw-client-secret-0001';

    private const TOKEN_BASE = 'https://oauth2.googleapis.test';

    private const GMAIL_BASE = 'https://gmail.googleapis.test';

    private const CALENDAR_BASE = 'https://calendar.googleapis.test';

    private const DRIVE_BASE = 'https://drive.googleapis.test';

    /**
     * MANDATORY, never omit: Carbon::setTestNow() is process-global
     * state. A test that freezes the clock and does not restore it
     * leaks a frozen "now" into every subsequent test in the same
     * process — this codebase has a known bug class from exactly that
     * omission, and these tests freeze the clock in nearly every
     * scenario. Restoring in tearDown() (rather than at the end of each
     * test body) means the restore still happens when an assertion
     * fails mid-test and the rest of the body never runs.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Site 1 — refreshToken()
    // ------------------------------------------------------------

    public function test_refresh_token_derives_an_identical_idempotency_key_when_one_refresh_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeTokenEndpoint();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->refresh($firm, $connection);

        // The SAME logical refresh, retried by RefreshIntegrationToken
        // after its backoff. Google's refresh grant returns no new
        // refresh token, and the access credential is only rotated once
        // refreshToken() actually succeeds — so nothing durable changed.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->refresh($firm, $connection);

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'A retried refresh crossing a minute boundary must reuse one idempotency key.');
        $this->assertStringNotContainsString('202607011200', $keys[0]);
        $this->assertStringNotContainsString('202607011201', $keys[0]);
        $this->assertStringStartsWith('oauth_refresh:'.$connection->id.':', $keys[0]);
    }

    public function test_refresh_token_derives_a_different_idempotency_key_once_the_access_credential_has_actually_rotated(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $credential = $this->makeAccessCredential($firm, $connection);
        $this->fakeTokenEndpoint();

        // Frozen at ONE instant across both calls, so only the durable
        // state change can distinguish them. This is the scenario that
        // makes Google's anchor deliberately differ from Microsoft's:
        // Google's refresh token does not rotate, so anchoring on it
        // would have produced a PERMANENTLY static key here.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->refresh($firm, $connection);

        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)
            ->rotate($connection, $credential, 'rotated-access-token-plaintext', now()->addHour()));

        $this->refresh($firm, $connection);

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame(
            $keys[0],
            $keys[1],
            'Once a refresh completes and rotates the access credential, the next refresh must key differently — otherwise it collides forever against integration_usage_records unique(firm_integration_id, idempotency_key).',
        );
    }

    // ------------------------------------------------------------
    // Site 2 — revokeAtProvider()
    // ------------------------------------------------------------

    public function test_revoke_at_provider_derives_an_identical_idempotency_key_when_one_disconnect_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)
            ->store($connection, CredentialType::OauthRefreshToken, 'the-refresh-token-plaintext'));

        Http::fake([self::TOKEN_BASE.'/revoke' => Http::response([], 200)]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        // A re-entered disconnect (user double-click, or a retry after a
        // transient revoke failure) still targets the same, still-Active
        // credential row.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'Re-entering one logical disconnect must reuse the same revoke idempotency key.');
        $this->assertStringNotContainsString('202607011200', $keys[0]);
        $this->assertStringStartsWith('oauth_revoke:'.$connection->id.':', $keys[0]);
    }

    public function test_revoke_at_provider_derives_a_different_idempotency_key_for_a_credential_from_a_later_grant(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $credentialService = app(IntegrationCredentialService::class);

        $first = $this->runWithFirmContext($firm, fn () => $credentialService
            ->store($connection, CredentialType::OauthRefreshToken, 'grant-1-refresh-token'));

        Http::fake([self::TOKEN_BASE.'/revoke' => Http::response([], 200)]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        // A completed disconnect revokes the credential; a later
        // re-connect mints a brand new credential row, so the next
        // revoke is a genuinely different logical operation.
        $this->runWithFirmContext($firm, fn () => $credentialService->revoke($connection, $first, 'disconnect'));
        $this->runWithFirmContext($firm, fn () => $credentialService
            ->store($connection, CredentialType::OauthRefreshToken, 'grant-2-refresh-token'));

        $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'Revoking a credential from a later grant is a different logical operation.');
    }

    // ------------------------------------------------------------
    // Site 3 — fetchGmailStartingHistoryId() (pull baseline capture)
    // ------------------------------------------------------------

    public function test_gmail_starting_history_id_derives_an_identical_idempotency_key_when_one_terminal_page_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeGmailPullEndpoints();

        // A PullSyncJob retry re-walks the same terminal page of the
        // same full enumeration — same cursor in, same page token.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->pull($firm, $connection, ResourceType::Message->value, 'full:page-token-terminal');

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->pull($firm, $connection, ResourceType::Message->value, 'full:page-token-terminal');

        $profileKeys = $this->sentIdempotencyKeysMatching('pull:'.$connection->id.':message:profile:');

        $this->assertCount(2, $profileKeys);
        $this->assertSame($profileKeys[0], $profileKeys[1], 'The Gmail baseline profile fetch must key on the enumeration it belongs to, not the clock.');
        $this->assertStringNotContainsString('202607011200', $profileKeys[0]);
    }

    public function test_gmail_starting_history_id_derives_a_different_idempotency_key_for_a_different_terminal_page(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeGmailPullEndpoints();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->pull($firm, $connection, ResourceType::Message->value, 'full:page-token-alpha');
        $this->pull($firm, $connection, ResourceType::Message->value, 'full:page-token-beta');

        $profileKeys = $this->sentIdempotencyKeysMatching('pull:'.$connection->id.':message:profile:');

        $this->assertCount(2, $profileKeys);
        $this->assertNotSame($profileKeys[0], $profileKeys[1], 'Two different enumerations terminating on different pages are two different logical operations.');
    }

    // ------------------------------------------------------------
    // Site 4 — fetchDriveStartPageToken() (both callers)
    // ------------------------------------------------------------

    public function test_drive_start_page_token_derives_an_identical_idempotency_key_when_one_terminal_page_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeDrivePullEndpoints();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->pull($firm, $connection, ResourceType::Document->value, 'full:drive-page-terminal');

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->pull($firm, $connection, ResourceType::Document->value, 'full:drive-page-terminal');

        $keys = $this->sentIdempotencyKeysMatching('pull:'.$connection->id.':document:start_page_token:');

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'The Drive baseline start-page-token fetch must key on the enumeration it belongs to, not the clock.');
        $this->assertStringNotContainsString('202607011200', $keys[0]);
    }

    /**
     * The second defect the wall-clock key was masking: the pull path
     * and the watch path both call fetchDriveStartPageToken(), and under
     * the old `pull:{conn}:document:start_page_token:{YmdHi}` shape two
     * genuinely different logical operations landing in the same minute
     * produced the SAME key — collapsing them onto one usage row and
     * destroying audit correlation between them.
     */
    public function test_drive_start_page_token_keys_the_pull_path_and_the_watch_path_as_distinct_operations(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeDrivePullEndpoints();
        $this->fakeDriveWatchEndpoint();

        // Same instant for both — under the old shape these collided.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->pull($firm, $connection, ResourceType::Document->value, 'full:drive-page-terminal');
        $this->runWithFirmContext($firm, fn () => $this->provider()->subscribe([
            'connection' => $connection,
            'resource_type' => ResourceType::Document->value,
        ]));

        $pullKeys = $this->sentIdempotencyKeysMatching('pull:'.$connection->id.':document:start_page_token:');
        $watchKeys = $this->sentIdempotencyKeysMatching('webhook_subscribe:'.$connection->id.':drive_start_page_token:');

        $this->assertCount(1, $pullKeys);
        $this->assertCount(1, $watchKeys);
        $this->assertNotSame(
            $pullKeys[0],
            $watchKeys[0],
            'The pull-path and watch-path start-page-token fetches are different logical operations and must never share an idempotency key.',
        );
    }

    // ------------------------------------------------------------
    // Sites 5 & 6 — callGmailWatch() + fetchGmailProfileEmailAddress()
    // ------------------------------------------------------------

    public function test_gmail_watch_derives_identical_idempotency_keys_when_one_subscribe_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeGmailWatchEndpoints();

        // No subscription row is written by the provider itself — the
        // orchestrating caller writes it only once subscribe() has
        // genuinely succeeded — so a retried bootstrap sees identical
        // durable state both times.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->subscribeGmail($firm, $connection);

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->subscribeGmail($firm, $connection);

        $watchKeys = $this->sentIdempotencyKeysMatching('webhook_subscribe:'.$connection->id.':gmail:');
        $profileKeys = $this->sentIdempotencyKeysMatching('webhook_subscribe:'.$connection->id.':gmail_profile:');

        $this->assertCount(2, $watchKeys);
        $this->assertSame($watchKeys[0], $watchKeys[1], 'A retried Gmail watch() must reuse one idempotency key.');
        $this->assertCount(2, $profileKeys);
        $this->assertSame($profileKeys[0], $profileKeys[1], 'The profile read feeding a retried Gmail watch() must reuse one idempotency key.');

        $this->assertStringNotContainsString('202607011200', $watchKeys[0]);
        $this->assertStringNotContainsString('202607011201', $watchKeys[0]);

        // The two calls belong to the same watch cycle but are distinct
        // operations — same anchor, different key prefix.
        $this->assertNotSame($watchKeys[0], $profileKeys[0]);
    }

    public function test_gmail_watch_derives_a_different_idempotency_key_once_a_subscription_actually_exists(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $this->fakeGmailWatchEndpoints();

        // Frozen at ONE instant — only the durable state change may
        // distinguish the two calls.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->subscribeGmail($firm, $connection);

        // The orchestrating caller persists the subscription row once
        // subscribe() succeeds. A later RENEWAL of that subscription is
        // a different logical watch cycle and must key differently.
        $subscription = $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()
            ->forFirmIntegration($connection)
            ->create([
                'provider_key' => ProviderKey::GoogleWorkspace->value,
                'resource_type' => ResourceType::Message->value,
                'provider_resource' => 'gmail:me',
                'provider_subscription_id' => 'gmail-watch:'.$connection->id,
                'expires_at' => Carbon::parse('2026-07-08 12:00:00'),
            ]));

        $this->runWithFirmContext($firm, fn () => $this->provider()->renewSubscription([
            'connection' => $connection,
            'subscription' => $subscription,
        ]));

        $watchKeys = $this->sentIdempotencyKeysMatching('webhook_subscribe:'.$connection->id.':gmail:');

        $this->assertCount(2, $watchKeys);
        $this->assertNotSame(
            $watchKeys[0],
            $watchKeys[1],
            'Once a subscription genuinely exists, the next watch cycle is a different logical operation and must derive a different key.',
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function provider(): GoogleWorkspaceProvider
    {
        return app(GoogleWorkspaceProvider::class);
    }

    /**
     * @return string[]
     */
    private function sentIdempotencyKeys(): array
    {
        return Http::recorded()
            ->map(static fn (array $pair): ?string => $pair[0]->header('Idempotency-Key')[0] ?? null)
            ->filter(static fn (?string $key): bool => $key !== null && $key !== '')
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    private function sentIdempotencyKeysMatching(string $prefix): array
    {
        return array_values(array_filter(
            $this->sentIdempotencyKeys(),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    private function refresh(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken('the-same-refresh-token', [
            'connection' => $connection,
        ]));
    }

    private function pull(Firm $firm, FirmIntegration $connection, string $resourceType, ?string $cursor): void
    {
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], $resourceType, $cursor));
    }

    private function subscribeGmail(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext($firm, fn () => $this->provider()->subscribe([
            'connection' => $connection,
            'resource_type' => ResourceType::Message->value,
        ]));
    }

    private function fakeTokenEndpoint(): void
    {
        Http::fake([
            self::TOKEN_BASE.'/token' => Http::response([
                'access_token' => 'new-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'openid email',
            ], 200),
        ]);
    }

    /**
     * A terminal full-list page (no nextPageToken), so pullGmailFullList()
     * proceeds to the profile fetch this test file is about.
     */
    private function fakeGmailPullEndpoints(): void
    {
        // Trailing `*` on every pattern: ProviderRequestExecutor's GET
        // branch appends the query string (e.g. `?pageToken=...`), so an
        // exact-URL fake would not match.
        Http::fake([
            self::GMAIL_BASE.'/gmail/v1/users/me/messages*' => Http::response(['messages' => []], 200),
            self::GMAIL_BASE.'/gmail/v1/users/me/profile*' => Http::response(['historyId' => '99001'], 200),
        ]);
    }

    private function fakeGmailWatchEndpoints(): void
    {
        Http::fake([
            self::GMAIL_BASE.'/gmail/v1/users/me/profile*' => Http::response([
                'emailAddress' => 'mailbox@unit-test.example',
                'historyId' => '99001',
            ], 200),
            self::GMAIL_BASE.'/gmail/v1/users/me/watch*' => Http::response([
                'historyId' => '99001',
                'expiration' => (string) (Carbon::parse('2026-07-08 12:00:00')->getTimestamp() * 1000),
            ], 200),
        ]);
    }

    /**
     * A terminal full-list page (no nextPageToken), so pullDriveFullList()
     * proceeds to the start-page-token fetch.
     */
    private function fakeDrivePullEndpoints(): void
    {
        Http::fake([
            self::DRIVE_BASE.'/drive/v3/files*' => Http::response(['files' => []], 200),
            self::DRIVE_BASE.'/drive/v3/changes/startPageToken*' => Http::response(['startPageToken' => '4242'], 200),
        ]);
    }

    private function fakeDriveWatchEndpoint(): void
    {
        Http::fake([
            self::DRIVE_BASE.'/drive/v3/files*' => Http::response(['files' => []], 200),
            self::DRIVE_BASE.'/drive/v3/changes/startPageToken*' => Http::response(['startPageToken' => '4242'], 200),
            self::DRIVE_BASE.'/drive/v3/changes/watch*' => Http::response([
                'id' => 'drive-channel-id',
                'expiration' => (string) (Carbon::parse('2026-07-08 12:00:00')->getTimestamp() * 1000),
            ], 200),
        ]);
    }

    /**
     * Never real Google credentials/endpoints — everything here is a
     * synthetic, config()-overridden test fixture, matching
     * GoogleWorkspaceProviderOAuthTest's own convention.
     * `Google\Auth\AccessToken` is swapped for a double that throws if
     * ever reached: no scenario in this file exercises Gmail webhook
     * verification, so reaching it would mean a real outbound cert
     * fetch.
     */
    private function configureEnvironment(): void
    {
        config([
            'integrations.oauth_apps.googleworkspace.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.googleworkspace.client_secret' => self::CLIENT_SECRET,
            'integrations.oauth_apps.googleworkspace.pubsub_push_audience' => 'unit-test-audience',
            'integrations.oauth_apps.googleworkspace.pubsub_push_service_account_email' => 'push@unit-test.iam.gserviceaccount.com',
            'integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => str_repeat('k', 32),
            'integrations.oauth_apps.googleworkspace.gmail_pubsub_topic_name' => 'projects/unit-test/topics/gmail-push',
            'integrations.provider_environments.'.ProviderKey::GoogleWorkspace->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
                'live_base_urls' => [
                    'token' => self::TOKEN_BASE,
                    'gmail' => self::GMAIL_BASE,
                    'calendar' => self::CALENDAR_BASE,
                    'drive' => self::DRIVE_BASE,
                ],
            ],
        ]);

        app()->instance(AccessToken::class, new class extends AccessToken
        {
            public function verify($token, array $options = [])
            {
                throw new \RuntimeException('AccessToken::verify() must never be reached by this test file — no scenario here exercises Gmail webhook verification.');
            }
        });
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();

        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::GoogleWorkspace->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::GoogleWorkspace->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($providerRow)
            ->create(['external_account_id' => null]));

        return [$firm, $connection];
    }

    private function makeAccessCredential(Firm $firm, FirmIntegration $connection): IntegrationCredential
    {
        return $this->runWithFirmContext($firm, fn () => IntegrationCredential::factory()
            ->forFirmIntegration($connection)
            ->ofType(CredentialType::OauthAccessToken)
            ->create());
    }
}
