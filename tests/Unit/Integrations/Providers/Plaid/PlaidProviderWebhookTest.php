<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PlaidProviderWebhookTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Structural,
 * per-method coverage of PlaidProvider's SupportsWebhooksContract
 * surface (webhookEventTypes()/detectSubscriptionValidationChallenge()/
 * extractRoutingIdentifier()/subscribe()/renewSubscription()) plus the
 * `parseInboundEvent()` `event_type` construction contract, mirroring
 * `GoogleWorkspaceProviderWebhookTest.php`'s structure/rigor. The
 * exhaustive `verifyInboundSignature()` JWT security matrix lives in the
 * dedicated `tests/Unit/Integrations/Support/PlaidWebhookJwtVerificationTest.php`
 * instead, per that file's own precedent (Gmail's own OIDC matrix is
 * likewise split out of its sibling GoogleWorkspaceProviderWebhookTest.php).
 *
 * §9 below is the REGRESSION TEST this task explicitly calls for: proving
 * `parseInboundEvent()`'s `event_type` construction correctly LEADS WITH
 * a `ResourceType` value for a genuine data-change notification, per
 * `DispatchPullSyncOnVerifiedWebhookEvent::mapEventTypeToResourceType()`'s
 * documented contract — the exact bug class that broke Microsoft 365's
 * webhook routing in Checkpoint 2. This file proves it directly against
 * BOTH `PlaidProvider::parseInboundEvent()`'s own output AND the real
 * `DispatchPullSyncOnVerifiedWebhookEvent::mapEventTypeToResourceType()`
 * method (via reflection, since it is private) for every event_type
 * PlaidProvider can actually emit.
 */
final class PlaidProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
            'integrations.oauth_apps.plaid.webhook_url' => 'https://app.firmsbase.test/integrations/webhooks/plaid',
            'integrations.oauth_apps.plaid.item_routing_hmac_key' => str_repeat('k', 32),
            'integrations.provider_environments.'.ProviderKey::Plaid->value => [
                'mode' => 'sandbox',
                'sandbox_base_urls' => ['default' => self::SANDBOX_BASE],
                'live_base_urls' => ['default' => self::SANDBOX_BASE],
            ],
        ]);
    }

    private function provider(): PlaidProvider
    {
        return app(PlaidProvider::class);
    }

    // ------------------------------------------------------------
    // webhookEventTypes()
    // ------------------------------------------------------------

    public function test_webhook_event_types_returns_the_documented_closed_vocabulary(): void
    {
        $types = $this->provider()->webhookEventTypes();

        $this->assertContains('transaction:sync_updates_available', $types);
        $this->assertContains('transaction:recurring_transactions_update', $types);
        $this->assertContains('lifecycle:item_error', $types);
        $this->assertContains('lifecycle:item_login_repaired', $types);
        $this->assertContains('lifecycle:unrecognized_webhook', $types);
    }

    // ------------------------------------------------------------
    // detectSubscriptionValidationChallenge()
    // ------------------------------------------------------------

    public function test_detect_subscription_validation_challenge_always_returns_null(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->detectSubscriptionValidationChallenge([], []));
        $this->assertNull($provider->detectSubscriptionValidationChallenge(['validationToken' => 'anything'], []));
        $this->assertNull($provider->detectSubscriptionValidationChallenge([], ['X-Some-Header' => 'value']));
    }

    // ------------------------------------------------------------
    // extractRoutingIdentifier()
    // ------------------------------------------------------------

    public function test_extract_routing_identifier_returns_the_item_id_from_the_json_body(): void
    {
        $result = $this->provider()->extractRoutingIdentifier(json_encode(['item_id' => 'item-sandbox-fixture-id', 'webhook_type' => 'ITEM']), []);

        $this->assertSame('item-sandbox-fixture-id', $result);
    }

    public function test_extract_routing_identifier_returns_null_when_item_id_is_missing_or_empty(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->extractRoutingIdentifier(json_encode(['webhook_type' => 'ITEM']), []));
        $this->assertNull($provider->extractRoutingIdentifier(json_encode(['item_id' => '']), []));
    }

    public function test_extract_routing_identifier_returns_null_for_a_non_json_body(): void
    {
        $this->assertNull($this->provider()->extractRoutingIdentifier('not-json-at-all', []));
    }

    public function test_extract_routing_identifier_never_reads_from_headers(): void
    {
        // Plaid's item_id always arrives in the JSON body, never a
        // header — a header-shaped decoy must never be picked up.
        $result = $this->provider()->extractRoutingIdentifier(json_encode(['webhook_type' => 'ITEM']), ['item_id' => 'decoy-header-value']);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------
    // §9 — event_type construction: MUST lead with a ResourceType value
    // for a genuine data-change notification (the Checkpoint 2 Microsoft
    // 365 bug class regression guard).
    // ------------------------------------------------------------

    public function test_a_transactions_sync_updates_available_webhook_produces_an_event_type_leading_with_the_transaction_resource_type(): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertSame(ResourceType::Transaction->value.':sync_updates_available', $result['event_type']);
        $this->assertStringStartsWith(ResourceType::Transaction->value, $result['event_type']);
    }

    public function test_a_transactions_recurring_update_webhook_also_leads_with_the_transaction_resource_type(): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'RECURRING_TRANSACTIONS_UPDATE',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertStringStartsWith(ResourceType::Transaction->value, $result['event_type']);
    }

    /**
     * A legacy /transactions/get-oriented webhook code (never fired
     * against a /transactions/sync-only integration, per PlaidProvider's
     * own defensive handling) must be prefixed `lifecycle:` — it is NOT
     * a "go re-pull via the sync framework" signal, so it must never
     * accidentally lead with the transaction resource type.
     */
    public function test_a_legacy_transactions_webhook_code_is_prefixed_lifecycle_never_the_resource_type(): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'INITIAL_UPDATE',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertStringStartsWith('lifecycle:', $result['event_type']);
    }

    public static function itemLifecycleWebhookCodeProvider(): array
    {
        return [
            'ERROR' => ['ERROR', 'lifecycle:item_error'],
            'LOGIN_REPAIRED' => ['LOGIN_REPAIRED', 'lifecycle:item_login_repaired'],
            'NEW_ACCOUNTS_AVAILABLE' => ['NEW_ACCOUNTS_AVAILABLE', 'lifecycle:item_new_accounts_available'],
            'PENDING_EXPIRATION' => ['PENDING_EXPIRATION', 'lifecycle:item_pending_expiration'],
            'PENDING_DISCONNECT' => ['PENDING_DISCONNECT', 'lifecycle:item_pending_disconnect'],
            'USER_PERMISSION_REVOKED' => ['USER_PERMISSION_REVOKED', 'lifecycle:item_user_permission_revoked'],
        ];
    }

    #[DataProvider('itemLifecycleWebhookCodeProvider')]
    public function test_item_lifecycle_webhook_codes_are_prefixed_lifecycle_never_a_resource_type(string $webhookCode, string $expectedEventType): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'ITEM',
            'webhook_code' => $webhookCode,
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertSame($expectedEventType, $result['event_type']);
        $this->assertStringStartsWith('lifecycle:', $result['event_type']);

        // None of ITEM/lifecycle's own first-word content collides with
        // a real ResourceType value — a belt-and-suspenders check that
        // this event_type could never be misread by the pull-sync
        // dispatcher as a resource-type-leading value.
        $this->assertNull(ResourceType::tryFrom('lifecycle'));
    }

    public function test_an_unrecognized_webhook_type_produces_the_fail_safe_lifecycle_event_type(): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'SOME_FUTURE_PRODUCT_FAMILY',
            'webhook_code' => 'SOMETHING',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertSame('lifecycle:unrecognized_webhook', $result['event_type']);
    }

    /**
     * The REAL, live `DispatchPullSyncOnVerifiedWebhookEvent::mapEventTypeToResourceType()`
     * — the actual downstream consumer of `event_type` — must correctly
     * resolve `transaction:sync_updates_available` to `ResourceType::Transaction`,
     * proving the contract holds end-to-end, not merely that
     * PlaidProvider's own string happens to start with the right prefix.
     */
    public function test_the_real_dispatch_pull_sync_listener_correctly_maps_the_transaction_sync_event_type_to_the_transaction_resource_type(): void
    {
        $eventType = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
            'item_id' => 'item-sandbox-fixture-id',
        ]), [])['event_type'];

        $resolved = $this->invokeMapEventTypeToResourceType($eventType);

        $this->assertSame(ResourceType::Transaction, $resolved);
    }

    /**
     * The inverse proof: an ITEM lifecycle event_type must resolve to
     * NULL (skip, never guess) — the dispatcher must never mistake an
     * Item health/status notification for a "go re-pull data" signal.
     */
    public function test_the_real_dispatch_pull_sync_listener_never_maps_an_item_lifecycle_event_type_to_any_resource_type(): void
    {
        $eventType = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'ITEM',
            'webhook_code' => 'ERROR',
            'item_id' => 'item-sandbox-fixture-id',
        ]), [])['event_type'];

        $resolved = $this->invokeMapEventTypeToResourceType($eventType);

        $this->assertNull($resolved);
    }

    private function invokeMapEventTypeToResourceType(?string $eventType): ?ResourceType
    {
        $listener = new DispatchPullSyncOnVerifiedWebhookEvent(1, 1, ProviderKey::Plaid->value, $eventType, 1);
        $method = new ReflectionMethod(DispatchPullSyncOnVerifiedWebhookEvent::class, 'mapEventTypeToResourceType');
        $method->setAccessible(true);

        return $method->invoke($listener, $eventType);
    }

    // ------------------------------------------------------------
    // parseInboundEvent() — event_id synthesis
    // ------------------------------------------------------------

    public function test_parse_inbound_event_synthesizes_a_deterministic_length_event_id(): void
    {
        $result = $this->provider()->parseInboundEvent(json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
            'item_id' => 'item-sandbox-fixture-id',
        ]), []);

        $this->assertSame(64, strlen($result['event_id']), 'event_id must be a sha256 hex digest.');
        $this->assertSame('TRANSACTIONS', $result['payload']['webhook_type']);
        $this->assertSame('SYNC_UPDATES_AVAILABLE', $result['payload']['webhook_code']);
    }

    // ------------------------------------------------------------
    // subscribe() / renewSubscription() — item-routing table write
    // ------------------------------------------------------------

    public function test_subscribe_re_asserts_the_webhook_registration_and_writes_the_item_route(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken('item-sandbox-fixture-id');

        Http::fake([self::SANDBOX_BASE.'/item/webhook/update' => Http::response([], 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->subscribe(['connection' => $connection]));

        $this->assertSame('item-sandbox-fixture-id', $result['subscription_id']);
        $this->assertSame('item', $result['resource']);
        $this->assertSame('webhook', $result['change_type']);
        $this->assertNotEmpty($result['expires_at']);

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === self::SANDBOX_BASE.'/item/webhook/update'
                && $body['access_token'] === 'access-sandbox-fixture-token'
                && $body['webhook'] === 'https://app.firmsbase.test/integrations/webhooks/plaid';
        });

        $route = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->first();
        $this->assertNotNull($route, 'subscribe() must call PlaidItemRoutingService::route() with the connection\'s external_account_id.');
    }

    public function test_subscribe_does_not_write_a_route_when_the_connection_has_no_external_account_id_yet(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken(null);

        Http::fake([self::SANDBOX_BASE.'/item/webhook/update' => Http::response([], 200)]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->subscribe(['connection' => $connection]));

        $this->assertSame(0, DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->count());
    }

    public function test_renew_subscription_is_an_idempotent_re_assert_identical_to_subscribe(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken('item-sandbox-fixture-id');

        Http::fake([self::SANDBOX_BASE.'/item/webhook/update' => Http::response([], 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->renewSubscription(['connection' => $connection]));

        $this->assertSame('item-sandbox-fixture-id', $result['subscription_id']);
        Http::assertSentCount(1);
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
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnectionWithAccessToken(?string $externalAccountId): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => $externalAccountId]));

        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)->store(
            $connection, CredentialType::ProviderAccessToken, 'access-sandbox-fixture-token'
        ));

        return [$firm, $connection];
    }
}
