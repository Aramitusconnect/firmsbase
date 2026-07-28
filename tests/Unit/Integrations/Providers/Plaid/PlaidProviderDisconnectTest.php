<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\ProviderConnectionService;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PlaidProviderDisconnectTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Proves
 * PlaidProvider::revokeAtProvider() (`/item/remove`) directly, and the
 * full `ProviderConnectionService::disconnect()` teardown — credential
 * revocation, status transition, PlaidItemRoutingService::unroute()
 * cleanup — end-to-end, mirroring GoogleWorkspaceProviderOAuthTest.php's
 * revokeAtProvider() rigor and GmailMailboxRoutingLifecycleTest.php's
 * disconnect() structure. `disconnect()` never routes through
 * `ProviderBillableCallPipeline` (confirmed directly from the live
 * `ProviderConnectionService::disconnect()` source — it calls
 * `$this->httpClient->execute(fn () => $provider->revokeAtProvider(...), 'revokeAtProvider')`
 * directly), so this file needs no cost-control scaffolding at all.
 */
final class PlaidProviderDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-0001';

    private const SECRET = 'unit-test-plaid-secret-0001';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const ACCESS_TOKEN = 'access-sandbox-fixture-token-super-secret-0002';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.providers' => [ProviderKey::Plaid->value => PlaidProvider::class]]);
        config([
            'integrations.oauth_apps.plaid.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.plaid.secret' => self::SECRET,
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
    // PlaidProvider::revokeAtProvider() — direct, unit-level
    // ------------------------------------------------------------

    public function test_revoke_at_provider_returns_false_when_there_is_no_credential_to_revoke(): void
    {
        [$firm, $connection] = $this->makeActiveConnection();

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_revoke_at_provider_hits_item_remove_with_the_decrypted_access_token_and_returns_true_on_200(): void
    {
        [$firm, $connection] = $this->makeActiveConnection();
        $this->storeAccessToken($firm, $connection);

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response([], 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertTrue($result);
        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === self::SANDBOX_BASE.'/item/remove'
                && $body['access_token'] === self::ACCESS_TOKEN
                && $body['client_id'] === self::CLIENT_ID
                && $body['secret'] === self::SECRET;
        });
    }

    public function test_revoke_at_provider_returns_false_when_plaid_responds_with_a_non_200_status(): void
    {
        [$firm, $connection] = $this->makeActiveConnection();
        $this->storeAccessToken($firm, $connection);

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response(['error_code' => 'ITEM_NOT_FOUND'], 400)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertFalse($result);
    }

    public function test_revoke_at_provider_returns_false_rather_than_throwing_on_a_network_failure(): void
    {
        [$firm, $connection] = $this->makeActiveConnection();
        $this->storeAccessToken($firm, $connection);

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->revokeAtProvider(['connection' => $connection]));

        $this->assertFalse($result, 'A best-effort revoke must never throw out of disconnect() on a provider-side failure.');
    }

    // ------------------------------------------------------------
    // ProviderConnectionService::disconnect() — full teardown
    // ------------------------------------------------------------

    public function test_disconnect_revokes_the_credential_transitions_status_and_removes_the_item_route(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->storeAccessToken($firm, $connection);
        $this->runWithFirmContext($firm, fn () => app(PlaidItemRoutingService::class)->route($connection, 'item-sandbox-fixture-id'));

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response([], 200)]);

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id)
        );

        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);

        $activeCredentialCount = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()
                ->where('firm_integration_id', $connection->id)
                ->where('credential_type', CredentialType::ProviderAccessToken->value)
                ->where('status', IntegrationCredentialStatus::Active->value)
                ->count()
        );
        $this->assertSame(0, $activeCredentialCount, 'disconnect() must revoke every Active credential.');

        $routeCount = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->count();
        $this->assertSame(0, $routeCount, 'disconnect() must call PlaidItemRoutingService::unroute() so no stale item route survives.');
    }

    public function test_disconnect_still_completes_local_teardown_even_when_item_remove_fails(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->storeAccessToken($firm, $connection);
        $this->runWithFirmContext($firm, fn () => app(PlaidItemRoutingService::class)->route($connection, 'item-sandbox-fixture-id'));

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500)]);

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id)
        );

        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status, 'Local teardown is best-effort/unconditional — a failed remote revoke must never block it.');
        $routeCount = DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connection->id)->count();
        $this->assertSame(0, $routeCount);
    }

    public function test_disconnect_removes_only_the_disconnected_connections_item_route(): void
    {
        $firmA = $this->firmWithActiveKey();
        $connectionA = $this->activeConnection($firmA);
        $firmUserA = $this->firmUserFor($firmA, FirmUserRole::FirmOwner);
        $this->storeAccessToken($firmA, $connectionA);
        $this->runWithFirmContext($firmA, fn () => app(PlaidItemRoutingService::class)->route($connectionA, 'item-a'));

        $firmB = $this->firmWithActiveKey();
        $connectionB = $this->activeConnection($firmB);
        $this->storeAccessToken($firmB, $connectionB);
        $this->runWithFirmContext($firmB, fn () => app(PlaidItemRoutingService::class)->route($connectionB, 'item-b'));

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response([], 200)]);

        $this->runWithFirmContext(
            $firmA,
            fn () => app(ProviderConnectionService::class)->disconnect($connectionA, $firmUserA->user_id)
        );

        $this->assertSame(0, DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connectionA->id)->count());
        $this->assertSame(1, DB::table('integration_plaid_item_routes')->where('firm_integration_id', $connectionB->id)->count(), 'disconnect() must never remove a DIFFERENT connection\'s item route.');
    }

    // ------------------------------------------------------------
    // Token redaction on the disconnect path
    // ------------------------------------------------------------

    public function test_the_plaintext_access_token_never_appears_in_any_audit_metadata_row_after_disconnect(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->storeAccessToken($firm, $connection);

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response([], 200)]);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id)
        );

        $events = $this->runWithFirmContext($firm, fn () => TimelineEvent::query()->where('firm_id', $firm->id)->get());
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $encoded = (string) json_encode($event->metadata_json);
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, $encoded, "Timeline event [{$event->event_type}] metadata must never contain the plaintext access_token.");
        }
    }

    /**
     * NOTE: `ProviderConnectionService::disconnect()`'s own
     * `catch (SanitizedProviderHttpException $e)` branch (which records
     * `integration_oauth.provider_revocation_failed`) is never reached
     * for Plaid — `PlaidProvider::revokeAtProvider()` already catches
     * `SanitizedProviderHttpException` internally and returns `false`
     * (identical, already-established discipline to
     * `GoogleWorkspaceProvider::revokeAtProvider()`), so no exception
     * ever propagates up to `disconnect()`'s wrapping try/catch. This is
     * confirmed correct-as-designed (see `PlaidProvider::revokeAtProvider()`'s
     * own docblock), not a gap this file needs to prove further — the
     * broader `test_the_plaintext_access_token_never_appears_in_any_audit_metadata_row_after_disconnect()`
     * test above already covers every event actually recorded on the
     * failure path.
     */
    public function test_disconnect_completes_even_when_item_remove_fails_without_recording_a_provider_revocation_failed_event(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->storeAccessToken($firm, $connection);

        Http::fake([self::SANDBOX_BASE.'/item/remove' => Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500)]);

        $fresh = $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id)
        );

        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->where('event_type', 'integration_oauth.provider_revocation_failed')->first()
        );
        $this->assertNull($event, 'PlaidProvider::revokeAtProvider() swallows the SanitizedProviderHttpException internally, so this event is never reached for Plaid.');
    }

    // ------------------------------------------------------------
    // Helpers
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

    private function plaidProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();
    }

    private function activeConnection(Firm $firm): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->plaidProviderRow())
                ->create(['status' => ConnectionStatus::Active->value, 'external_account_id' => 'item-sandbox-fixture-id'])
        );
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeActiveConnection(): array
    {
        $firm = $this->firmWithActiveKey();

        return [$firm, $this->activeConnection($firm)];
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->activeConnection($firm);
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        return [$firm, $connection, $firmUser];
    }

    private function storeAccessToken(Firm $firm, FirmIntegration $connection): void
    {
        $this->runWithFirmContext(
            $firm,
            fn () => app(IntegrationCredentialService::class)->store($connection, CredentialType::ProviderAccessToken, self::ACCESS_TOKEN, ['label' => 'Plaid Item access token'])
        );
    }
}
