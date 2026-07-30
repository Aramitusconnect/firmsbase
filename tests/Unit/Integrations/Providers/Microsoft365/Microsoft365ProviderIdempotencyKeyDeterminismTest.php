<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Microsoft365;

use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Microsoft365ProviderIdempotencyKeyDeterminismTest — remediation of the
 * independent security review's wall-clock `usageIdempotencyKey`
 * finding.
 *
 * Two sites in Microsoft365Provider built their usage idempotency key
 * from `now()->format('YmdHi')`:
 *
 *   1. refreshToken()        — `oauth_refresh:{connection}:{YmdHi}`
 *   2. renewSubscription()   — `webhook_renew:{connection}:{subId}:{YmdHi}`
 *
 * Neither provider is routed through the billable-call reservation
 * pipeline today (only Plaid implements
 * RequiresBillableCallPipelineContract), so this was never a
 * double-BILLING risk — but per the review's own instruction, a
 * retried/redelivered call must not defeat provider-side idempotency,
 * local usage deduplication, audit correlation, or operation tracing
 * even when no money is involved. A wall-clock key defeats all four at
 * once: `App\Integrations\Jobs\RefreshIntegrationToken` and
 * `App\Integrations\Jobs\RenewGraphSubscriptionJob` both retry with
 * backoff, so a retry reliably lands in a DIFFERENT wall-clock minute
 * than the attempt it retries — writing a SEPARATE
 * `integration_usage_records` row (that table's
 * `unique(firm_integration_id, idempotency_key)` index never fires) and
 * sending Microsoft a DIFFERENT `Idempotency-Key` header each time.
 *
 * Every assertion below reads the REAL `Idempotency-Key` header
 * `App\Integrations\Support\ProviderRequestExecutor::send()` actually
 * put on the wire — which that method sets from the very same
 * `$usageIdempotencyKey` it hands to
 * `IntegrationUsageRecorderService::recordOnce()`. So one assertion
 * proves BOTH dedup layers at once, rather than reaching into a private
 * key-construction helper that could drift from what is really sent.
 *
 * Each fixed key is proved twice, per the remediation's own bar:
 *   (a) the same logical call, retried across a simulated minute
 *       boundary, produces an IDENTICAL key; and
 *   (b) two genuinely DIFFERENT logical calls produce different keys —
 *       so the fix is not the degenerate "hardcode a constant", which
 *       would permanently wedge every future call behind one row.
 */
final class Microsoft365ProviderIdempotencyKeyDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-ms-client-id-0001';

    private const CLIENT_SECRET = 'unit-test-ms-client-secret-0001';

    private const IDENTITY_BASE = 'https://login.microsoftonline.test';

    private const GRAPH_BASE = 'https://graph.microsoft.test';

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

        // Attempt 1 — the original attempt.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->refresh($firm, $connection, 'the-same-refresh-token');

        // Attempt 2 — the SAME logical refresh, retried by
        // RefreshIntegrationToken after its backoff, now in a different
        // wall-clock minute. Nothing durable changed in between: the
        // access credential is only rotated by
        // ProviderConnectionService::refreshConnectionToken() AFTER
        // refreshToken() returns successfully, which (for a retry) it
        // never did.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->refresh($firm, $connection, 'the-same-refresh-token');

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertSame(
            $keys[0],
            $keys[1],
            'A retried refresh that crosses a wall-clock minute boundary must reuse the same idempotency key — otherwise it writes a second usage row and sends Microsoft a second Idempotency-Key for one logical refresh.',
        );

        // Guard against a silent regression back to the old shape: the
        // key must carry no trace of either minute it was built in.
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

        // Both calls are pinned to the SAME instant, so the clock cannot
        // be what distinguishes them — only the durable state change can.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->refresh($firm, $connection, 'refresh-token-generation-1');

        // A genuinely COMPLETED refresh: refreshConnectionToken() calls
        // IntegrationCredentialService::rotate(), which marks the old
        // credential Rotated and stores a NEW Active row. The next
        // refresh is a new logical operation and must key differently,
        // or it would collide forever against
        // integration_usage_records' unique(firm_integration_id,
        // idempotency_key) index and silently stop recording usage.
        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)
            ->rotate($connection, $credential, 'rotated-access-token-plaintext', now()->addHour()));

        $this->refresh($firm, $connection, 'refresh-token-generation-2');

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame(
            $keys[0],
            $keys[1],
            'Once a refresh actually completes and rotates the access credential, the next refresh is a different logical operation and must derive a different key.',
        );
    }

    public function test_refresh_token_derives_a_different_idempotency_key_for_a_different_connection(): void
    {
        $this->configureEnvironment();
        [$firmA, $connectionA] = $this->makeConnection();
        $this->makeAccessCredential($firmA, $connectionA);
        [$firmB, $connectionB] = $this->makeConnection();
        $this->makeAccessCredential($firmB, $connectionB);
        $this->fakeTokenEndpoint();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->refresh($firmA, $connectionA, 'a-refresh-token');
        $this->refresh($firmB, $connectionB, 'a-refresh-token');

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'Two different connections refreshing at the same instant are two different logical operations.');
    }

    // ------------------------------------------------------------
    // Site 2 — renewSubscription()
    // ------------------------------------------------------------

    public function test_renew_subscription_derives_an_identical_idempotency_key_when_one_renewal_is_retried_across_a_minute_boundary(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $subscription = $this->makeSubscription($firm, $connection);
        $this->fakeGraphSubscriptionPatch();

        // Attempt 1, then the SAME logical renewal retried by
        // RenewGraphSubscriptionJob (backoff() = [30, 60, 120, 240],
        // $tries = 5) a minute later. The job rewrites
        // provider_subscription_id/expires_at ONLY on success, so on a
        // retry the subscription row is byte-for-byte what attempt 1 saw.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));
        $this->renew($firm, $connection, $subscription);

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:01:30'));
        $this->renew($firm, $connection, $subscription);

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertSame(
            $keys[0],
            $keys[1],
            'All attempts at one logical subscription renewal must share an idempotency key.',
        );
        $this->assertStringNotContainsString('202607011200', $keys[0]);
        $this->assertStringNotContainsString('202607011201', $keys[0]);
    }

    public function test_renew_subscription_derives_a_different_idempotency_key_once_the_renewal_has_actually_succeeded(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $subscription = $this->makeSubscription($firm, $connection);
        $this->fakeGraphSubscriptionPatch();

        // Same frozen instant for both calls — only the durable state
        // change may distinguish them.
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->renew($firm, $connection, $subscription);

        // A genuinely COMPLETED renewal: RenewGraphSubscriptionJob
        // rewrites the row's provider-side id and expiry. `expires_at`
        // is precisely the durable "this renewal still needs doing"
        // marker, so the NEXT renewal cycle must key differently.
        $this->runWithFirmContext($firm, function () use ($subscription): void {
            $subscription->forceFill([
                'provider_subscription_id' => 'graph-subscription-generation-2',
                'expires_at' => now()->addHours(70),
            ])->save();
        });

        $this->renew($firm, $connection, $subscription);

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame(
            $keys[0],
            $keys[1],
            'The next renewal cycle of the same subscription is a different logical operation and must derive a different key.',
        );
    }

    public function test_renew_subscription_derives_a_different_idempotency_key_for_a_different_subscription_on_the_same_connection(): void
    {
        $this->configureEnvironment();
        [$firm, $connection] = $this->makeConnection();
        $this->makeAccessCredential($firm, $connection);
        $messages = $this->makeSubscription($firm, $connection, 'graph-subscription-messages', "me/mailFolders('Inbox')/messages");
        $events = $this->makeSubscription($firm, $connection, 'graph-subscription-events', 'me/events');
        $this->fakeGraphSubscriptionPatch();

        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:30'));

        $this->renew($firm, $connection, $messages);
        $this->renew($firm, $connection, $events);

        $keys = $this->sentIdempotencyKeys();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'Two different subscriptions renewed at the same instant are two different logical operations.');
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function provider(): Microsoft365Provider
    {
        return app(Microsoft365Provider::class);
    }

    /**
     * The REAL `Idempotency-Key` header values ProviderRequestExecutor
     * put on the wire, in send order — see this class's docblock for why
     * the header (rather than a reflected private helper) is what these
     * tests assert on.
     *
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

    private function refresh(Firm $firm, FirmIntegration $connection, string $refreshToken): void
    {
        $this->runWithFirmContext($firm, fn () => $this->provider()->refreshToken($refreshToken, [
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Message->value],
        ]));
    }

    private function renew(Firm $firm, FirmIntegration $connection, IntegrationProviderWebhookSubscription $subscription): void
    {
        $this->runWithFirmContext($firm, fn () => $this->provider()->renewSubscription([
            'connection' => $connection,
            'subscription' => $subscription,
        ]));
    }

    private function fakeTokenEndpoint(): void
    {
        Http::fake([
            self::IDENTITY_BASE.'/organizations/oauth2/v2.0/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'Mail.Read Mail.Send offline_access openid profile',
            ], 200),
        ]);
    }

    private function fakeGraphSubscriptionPatch(): void
    {
        Http::fake([
            self::GRAPH_BASE.'/v1.0/subscriptions/*' => Http::response([
                'id' => 'graph-subscription-renewed',
                'expirationDateTime' => '2026-07-04 10:00:00',
                'resource' => "me/mailFolders('Inbox')/messages",
                'changeType' => 'created,updated,deleted',
            ], 200),
        ]);
    }

    /**
     * Never real Microsoft credentials/endpoints — everything here is a
     * synthetic, config()-overridden test fixture, matching
     * Microsoft365ProviderOAuthTest's own convention.
     */
    private function configureEnvironment(): void
    {
        config([
            'integrations.oauth_apps.microsoft365.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.microsoft365.client_secret' => self::CLIENT_SECRET,
            'integrations.provider_environments.'.ProviderKey::Microsoft365->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => [
                    'identity' => self::IDENTITY_BASE,
                    'graph' => self::GRAPH_BASE,
                ],
                'live_base_urls' => [
                    'identity' => self::IDENTITY_BASE,
                    'graph' => self::GRAPH_BASE,
                ],
            ],
        ]);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();

        // An Active tenant encryption key must exist before any
        // credential can be stored or rotated through the real
        // per-firm envelope chain.
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $providerRow = IntegrationProvider::query()->where('code', ProviderKey::Microsoft365->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Microsoft365->value]);

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

    private function makeSubscription(
        Firm $firm,
        FirmIntegration $connection,
        string $providerSubscriptionId = 'graph-subscription-generation-1',
        string $providerResource = "me/mailFolders('Inbox')/messages",
    ): IntegrationProviderWebhookSubscription {
        return $this->runWithFirmContext($firm, fn () => IntegrationProviderWebhookSubscription::factory()
            ->forFirmIntegration($connection)
            ->create([
                'provider_key' => ProviderKey::Microsoft365->value,
                'provider_resource' => $providerResource,
                'provider_subscription_id' => $providerSubscriptionId,
                'expires_at' => Carbon::parse('2026-07-02 10:00:00'),
            ]));
    }
}
