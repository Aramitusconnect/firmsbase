<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\EntitlementSource;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Models\Client;
use App\Models\ClientPortalMatterGrant;
use App\Models\ClientPortalUser;
use App\Models\FinancialEvidenceMatterRequest;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\TenantEncryptionKey;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PlaidExchangeControllerAuthorizationTest — Checkpoint 7 (authorization
 * review, item 19). FOUND AND FIXED during this checkpoint: the
 * controller resolved a client-supplied `firm_integration_id` by firm
 * membership ONLY, never by matter/request ownership — a real IDOR
 * letting a client with legitimate access to any matter in a firm
 * complete a DIFFERENT matter's connection with their own public_token.
 * No test exercised this controller's actual authorization logic before
 * this checkpoint (confirmed via repo-wide search — only unauthenticated-
 * guest-redirect checks existed, in ClientPortalAuthenticationTest),
 * which is exactly how the gap went unnoticed. This file is the missing
 * regression coverage, proving both the fixed positive path and the
 * closed IDOR, hitting the real route through the real `client` guard
 * and middleware stack (mirrors ClientPortalAuthenticationTest's own
 * `actingAs($portalUser, 'client')->post('/portal/plaid/exchange', ...)`
 * pattern).
 */
final class PlaidExchangeControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-cp7-exchange';

    private const SECRET = 'unit-test-plaid-secret-cp7-exchange';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

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

    public function test_a_client_can_complete_the_connection_created_for_their_own_matters_request(): void
    {
        [$firm, $matter, $portalUser, $connection, $request] = $this->makeGrantedClientWithPendingRequestAndConnection();
        $this->fakePublicTokenExchange('item-cp7-fixture-a', 'ins_fixture_a');

        $response = $this->actingAs($portalUser, 'client')->post('/portal/plaid/exchange', [
            'public_token' => 'public-sandbox-fixture-token',
            'firm_integration_id' => $connection->id,
            'matter_id' => $matter->id,
        ]);

        $response->assertOk();

        $reloadedRequest = $this->runWithFirmContext($firm, fn () => FinancialEvidenceMatterRequest::query()->find($request->id));
        $this->assertSame('reviewed', $reloadedRequest->status);
    }

    /**
     * THE regression proof for the found-and-fixed IDOR: the client
     * genuinely has portal access to matter A (their own), but submits
     * matter B's connection id (same firm, different matter/client's
     * request) alongside their own public_token. Before the fix this
     * succeeded (firm-membership-only check) and would have completed
     * matter B's connection with the attacker's own bank credential.
     */
    public function test_a_client_cannot_complete_a_connection_created_for_a_different_matters_request_even_in_the_same_firm(): void
    {
        [$firm, $matterA, $portalUserA] = $this->makeGrantedClientWithPendingRequestAndConnection();
        [, $matterB, , $connectionB] = $this->makeGrantedClientWithPendingRequestAndConnection($firm);

        $response = $this->actingAs($portalUserA, 'client')->post('/portal/plaid/exchange', [
            'public_token' => 'attacker-controlled-public-token',
            'firm_integration_id' => $connectionB->id,
            'matter_id' => $matterA->id,
        ]);

        $response->assertStatus(403);

        $reloadedConnectionB = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->find($connectionB->id));
        $this->assertNull($reloadedConnectionB->external_account_id, "Matter B's connection must remain untouched by matter A's client.");
    }

    public function test_a_stale_firm_integration_id_no_longer_matching_the_current_request_is_rejected(): void
    {
        [$firm, $matter, $portalUser, $originalConnection, $request] = $this->makeGrantedClientWithPendingRequestAndConnection();

        // Simulate the request moving on to a second connection attempt
        // (e.g. the client restarted the Link flow) — the server-side
        // binding now points at a NEW connection, but the client submits
        // the stale, previously-issued id.
        $secondConnection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider(IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first())
            ->pending()
            ->create(['external_account_id' => null]));
        $this->runWithFirmContext($firm, fn () => $request->update(['firm_integration_id' => $secondConnection->id]));

        $response = $this->actingAs($portalUser, 'client')->post('/portal/plaid/exchange', [
            'public_token' => 'public-sandbox-fixture-token',
            'firm_integration_id' => $originalConnection->id,
            'matter_id' => $matter->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * @return array{0: Firm, 1: Matter, 2: ClientPortalUser, 3: FirmIntegration, 4: FinancialEvidenceMatterRequest}
     */
    private function makeGrantedClientWithPendingRequestAndConnection(?Firm $firm = null): array
    {
        $firm ??= Firm::factory()->create();

        return $this->runWithFirmContext($firm, function () use ($firm) {
            TenantEncryptionKey::query()->where('firm_id', $firm->id)->exists()
                || TenantEncryptionKey::factory()->forFirm($firm)->create();

            app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
            app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

            $client = Client::factory()->forFirm($firm)->create();
            $matter = Matter::factory()->forFirm($firm)->forClient($client)->create();

            $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first();
            $connection = FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider)->pending()->create(['external_account_id' => null]);

            $requestedBy = FirmUser::factory()->create(['firm_id' => $firm->id]);

            $matterRequest = FinancialEvidenceMatterRequest::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
                'requested_by_firm_user_id' => $requestedBy->id,
                'purpose' => 'Verify income for support calculation.',
                'requested_products_json' => ['bank_account', 'transaction'],
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            $portalUser = ClientPortalUser::query()->create([
                'client_id' => $client->id,
                'email' => 'client-'.Str::random(8).'@example.test',
                'password' => 'irrelevant-hashed-value',
                'is_active' => true,
            ]);

            ClientPortalMatterGrant::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'granted_at' => now(),
            ]);

            return [$firm, $matter, $portalUser, $connection, $matterRequest];
        });
    }

    private function fakePublicTokenExchange(string $itemId, string $institutionId): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response([
                'access_token' => 'access-sandbox-fixture-token-cp7-exchange',
                'item_id' => $itemId,
            ], 200),
            self::SANDBOX_BASE.'/item/get' => Http::response([
                'item' => ['item_id' => $itemId, 'institution_id' => $institutionId],
            ], 200),
        ]);
    }
}
