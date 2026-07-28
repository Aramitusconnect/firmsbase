<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Services;

use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Services\WebhookConnectionResolverService;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WebhookConnectionResolverServicePlaidTest — FirmsVault Live
 * Integrations, Checkpoint 4 (Plaid financial evidence add-on)
 * test-writer pass. Proves the new `ProviderKey::Plaid` fallback branch
 * `WebhookConnectionResolverService::resolveConnectionIdentity()` gained
 * (checkpoint4-combined-design.md §1.1.1, binding "Option B";
 * checkpoint4-design-plaid-provider-core.md §11.2) — the exact structural
 * sibling of the existing, already-tested Gmail-mailbox fallback branch.
 *
 * Directly against the real service and a real PostgreSQL database — no
 * HTTP layer involved, mirroring PlaidItemRoutingServiceTest.php's own
 * "direct-against-the-real-service" structure.
 */
final class WebhookConnectionResolverServicePlaidTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_KEY = 'unit-test-plaid-item-routing-hmac-key-0002';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.oauth_apps.plaid.item_routing_hmac_key' => self::HMAC_KEY]);
        // Configured too (even though this file never routes a Gmail
        // mailbox) so that a GoogleWorkspace-scoped lookup in
        // test_the_plaid_fallback_never_fires_for_a_different_provider_key()
        // exercises GmailMailboxRoutingService's OWN fail-closed
        // "unknown mailbox -> null" path, rather than tripping its
        // unrelated missing-key guard and masking what this test is
        // actually trying to prove.
        config(['integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key' => 'unit-test-gmail-mailbox-routing-hmac-key-filler']);
    }

    private function resolver(): WebhookConnectionResolverService
    {
        return app(WebhookConnectionResolverService::class);
    }

    private function itemRouting(): PlaidItemRoutingService
    {
        return app(PlaidItemRoutingService::class);
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($provider)
            ->create());

        return [$firm, $connection];
    }

    // ------------------------------------------------------------
    // The Plaid item_id fallback fires when the primary hash lookup misses
    // ------------------------------------------------------------

    public function test_resolves_via_the_plaid_item_routing_fallback_when_the_primary_hash_lookup_misses(): void
    {
        [$firm, $connection] = $this->makeConnection();
        $this->itemRouting()->route($connection, 'item-sandbox-fixture-id');

        // No integration_webhook_routing_index row exists for this raw
        // value at all — the primary CSPRNG-token-keyed lookup must miss
        // before this fallback is even reached.
        $this->assertSame(
            0,
            DB::table('integration_webhook_routing_index')->where('webhook_routing_token_hash', hash('sha256', 'item-sandbox-fixture-id'))->count()
        );

        $result = $this->resolver()->resolveConnectionIdentity(ProviderKey::Plaid->value, 'item-sandbox-fixture-id');

        $this->assertInstanceOf(ResolvedWebhookConnection::class, $result);
        $this->assertSame($firm->id, $result->firmId);
        $this->assertSame($connection->id, $result->firmIntegrationId);
        $this->assertSame($connection->integration_provider_id, $result->integrationProviderId);
        $this->assertSame(ProviderKey::Plaid->value, $result->providerKey);
    }

    public function test_returns_null_never_throws_for_an_unrouted_item_id(): void
    {
        $result = $this->resolver()->resolveConnectionIdentity(ProviderKey::Plaid->value, 'nobody-has-ever-routed-this-item-id');

        $this->assertNull($result);
    }

    public function test_the_primary_hash_index_takes_priority_over_the_plaid_fallback_when_both_would_match(): void
    {
        [$firmPrimary, $connectionPrimary] = $this->makeConnection();
        [$firmFallback, $connectionFallback] = $this->makeConnection();

        $collidingValue = 'a-value-that-exists-in-both-lookup-paths';

        // Primary path: a real CSPRNG-token-keyed row for connectionPrimary.
        $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();
        DB::table('integration_webhook_routing_index')->insert([
            'integration_provider_id' => $plaidProvider->id,
            'firm_id' => $firmPrimary->id,
            'firm_integration_id' => $connectionPrimary->id,
            'webhook_routing_token_hash' => hash('sha256', $collidingValue),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fallback path: the SAME raw string also happens to be routed
        // (via the dedicated item-routing table) to a DIFFERENT connection.
        $this->itemRouting()->route($connectionFallback, $collidingValue);

        $result = $this->resolver()->resolveConnectionIdentity(ProviderKey::Plaid->value, $collidingValue);

        $this->assertSame($connectionPrimary->id, $result->firmIntegrationId, 'The primary hash-index lookup must take priority over the Plaid item-routing fallback.');
    }

    // ------------------------------------------------------------
    // Scoped ONLY to ProviderKey::Plaid — never rescues a wrong-shaped
    // identifier for a different provider.
    // ------------------------------------------------------------

    public function test_the_plaid_fallback_never_fires_for_a_different_provider_key(): void
    {
        [, $connection] = $this->makeConnection();
        $this->itemRouting()->route($connection, 'item-sandbox-fixture-id');

        $resultForMicrosoft = $this->resolver()->resolveConnectionIdentity(ProviderKey::Microsoft365->value, 'item-sandbox-fixture-id');
        $resultForGoogle = $this->resolver()->resolveConnectionIdentity(ProviderKey::GoogleWorkspace->value, 'item-sandbox-fixture-id');

        $this->assertNull($resultForMicrosoft, 'A Plaid item_id route must never resolve for a Microsoft365 provider-key lookup.');
        $this->assertNull($resultForGoogle, 'A Plaid item_id route must never resolve for a GoogleWorkspace provider-key lookup.');
    }

    public function test_an_unrecognized_provider_key_value_returns_null_without_reaching_any_fallback(): void
    {
        [, $connection] = $this->makeConnection();
        $this->itemRouting()->route($connection, 'item-sandbox-fixture-id');

        $result = $this->resolver()->resolveConnectionIdentity('not-a-real-provider-key', 'item-sandbox-fixture-id');

        $this->assertNull($result);
    }

    // ------------------------------------------------------------
    // Cross-firm isolation via the fallback path
    // ------------------------------------------------------------

    public function test_the_plaid_fallback_never_resolves_a_route_belonging_to_a_different_firm(): void
    {
        [$firmA, $connectionA] = $this->makeConnection();
        [$firmB, $connectionB] = $this->makeConnection();

        $this->itemRouting()->route($connectionA, 'item-firm-a');
        $this->itemRouting()->route($connectionB, 'item-firm-b');

        $resultA = $this->resolver()->resolveConnectionIdentity(ProviderKey::Plaid->value, 'item-firm-a');
        $resultB = $this->resolver()->resolveConnectionIdentity(ProviderKey::Plaid->value, 'item-firm-b');

        $this->assertSame($firmA->id, $resultA->firmId);
        $this->assertNotSame($firmB->id, $resultA->firmId);
        $this->assertSame($firmB->id, $resultB->firmId);
    }
}
