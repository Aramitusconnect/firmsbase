<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PlaidProviderLinkTokenTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Proves
 * PlaidProvider's SupportsLinkTokenContract surface
 * (createLinkToken()/exchangePublicToken()) directly against the
 * shipped `app/Integrations/Providers/Plaid/PlaidProvider.php`, mirroring
 * GoogleWorkspaceProviderOAuthTest.php's rigor and Http::fake()-only
 * discipline (no real Plaid credentials/endpoints are ever reached —
 * tests/TestCase.php's suite-wide Http::preventStrayRequests() guard
 * would fail this suite outright if one were).
 *
 * Covers: new-Item link_token issuance (products translation), update-mode
 * link_token issuance (existing access_token forwarded, no products),
 * the "exactly one of requested_capabilities/update_access_token" XOR
 * contract, exchangePublicToken()'s access_token/item_id/institution_id
 * mapping (including the best-effort, swallowed-on-failure /item/get
 * institution_id follow-up), and isConfigured()/supportedAuthMethods().
 */
final class PlaidProviderLinkTokenTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-0001';

    private const SECRET = 'unit-test-plaid-secret-0001';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const WEBHOOK_URL = 'https://app.firmsbase.test/integrations/webhooks/plaid';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.plaid.client_id' => self::CLIENT_ID,
            'integrations.oauth_apps.plaid.secret' => self::SECRET,
            'integrations.oauth_apps.plaid.webhook_url' => self::WEBHOOK_URL,
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

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function makeConnection(): array
    {
        $firm = Firm::factory()->create();

        $plaidProviderRow = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Plaid->value]);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($plaidProviderRow)
            ->create(['external_account_id' => null]));

        return [$firm, $connection];
    }

    // ------------------------------------------------------------
    // isConfigured() / supportedAuthMethods()
    // ------------------------------------------------------------

    public function test_is_configured_is_false_when_client_id_and_secret_are_both_missing(): void
    {
        config(['integrations.oauth_apps.plaid.client_id' => null, 'integrations.oauth_apps.plaid.secret' => null]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_false_when_only_the_secret_is_present(): void
    {
        config(['integrations.oauth_apps.plaid.client_id' => '', 'integrations.oauth_apps.plaid.secret' => self::SECRET]);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_is_configured_is_true_when_both_client_id_and_secret_are_present(): void
    {
        $this->assertTrue($this->provider()->isConfigured());
    }

    public function test_supported_auth_methods_returns_only_link_token(): void
    {
        $this->assertSame([AuthMethod::LinkToken], $this->provider()->supportedAuthMethods());
    }

    // ------------------------------------------------------------
    // createLinkToken() — the requested_capabilities XOR update_access_token contract
    // ------------------------------------------------------------

    public function test_create_link_token_throws_when_neither_requested_capabilities_nor_update_access_token_is_present(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken(['connection' => $connection]));
    }

    public function test_create_link_token_throws_when_both_requested_capabilities_and_update_access_token_are_present(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Transaction->value],
            'update_access_token' => 'some-token',
        ]));
    }

    public function test_create_link_token_throws_on_an_empty_requested_capabilities_array(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [],
        ]));
    }

    public function test_create_link_token_throws_on_an_empty_update_access_token_string(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'update_access_token' => '',
        ]));
    }

    public function test_create_link_token_throws_when_no_requested_capability_translates_into_a_plaid_product(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => ['not_a_real_capability', 12345, null],
        ]));
    }

    // ------------------------------------------------------------
    // createLinkToken() — new Item (products path)
    // ------------------------------------------------------------

    public function test_create_link_token_issues_a_new_item_link_token_with_the_correctly_translated_products(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
                'request_id' => 'req-fixture',
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Transaction->value, ResourceType::BankAccount->value],
        ]));

        $this->assertSame('link-sandbox-fixture-token', $result['link_token']);
        $this->assertSame('2026-08-01T00:00:00Z', $result['expiration']);

        Http::assertSent(function (HttpClientRequest $request) use ($connection): bool {
            $body = $request->data();

            return $request->url() === self::SANDBOX_BASE.'/link/token/create'
                && $body['client_id'] === self::CLIENT_ID
                && $body['secret'] === self::SECRET
                && $body['user']['client_user_id'] === (string) $connection->uuid
                && $body['webhook'] === self::WEBHOOK_URL
                && in_array('transactions', $body['products'], true)
                && in_array('auth', $body['products'], true)
                && ! array_key_exists('access_token', $body);
        });
    }

    public function test_create_link_token_ignores_unknown_capability_values_but_still_translates_the_known_ones(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
        ]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => ['bogus_capability', ResourceType::Identity->value],
        ]));

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $body['products'] === ['identity'];
        });
    }

    public function test_create_link_token_dedups_products_when_the_same_capability_is_requested_twice(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
        ]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'requested_capabilities' => [ResourceType::Transaction->value, ResourceType::Transaction->value],
        ]));

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->data()['products'] === ['transactions']);
    }

    public function test_create_link_token_throws_a_sanitized_exception_when_the_response_has_no_usable_link_token(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response(['expiration' => '2026-08-01T00:00:00Z'], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
                'connection' => $connection,
                'requested_capabilities' => [ResourceType::Transaction->value],
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a response with no link_token.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, $e->category());
        }
    }

    public function test_create_link_token_throws_a_sanitized_exception_when_the_response_has_no_usable_expiration(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response(['link_token' => 'link-sandbox-fixture-token'], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
                'connection' => $connection,
                'requested_capabilities' => [ResourceType::Transaction->value],
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a response with no expiration.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, $e->category());
        }
    }

    // ------------------------------------------------------------
    // createLinkToken() — update mode (existing access_token path)
    // ------------------------------------------------------------

    public function test_create_link_token_issues_an_update_mode_link_token_carrying_the_existing_access_token_and_no_products(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-update-mode-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->createLinkToken([
            'connection' => $connection,
            'update_access_token' => 'existing-item-access-token-plaintext',
        ]));

        $this->assertSame('link-sandbox-update-mode-token', $result['link_token']);

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $body['access_token'] === 'existing-item-access-token-plaintext'
                && ! array_key_exists('products', $body);
        });
    }

    // ------------------------------------------------------------
    // exchangePublicToken()
    // ------------------------------------------------------------

    public function test_exchange_public_token_throws_on_an_empty_public_token(): void
    {
        [$firm, $connection] = $this->makeConnection();

        $this->expectException(InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('   ', ['connection' => $connection]));
    }

    public function test_exchange_public_token_returns_access_token_item_id_and_best_effort_institution_id(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response([
                'access_token' => 'access-sandbox-fixture-token',
                'item_id' => 'item-sandbox-fixture-id',
            ], 200),
            self::SANDBOX_BASE.'/item/get' => Http::response([
                'item' => ['item_id' => 'item-sandbox-fixture-id', 'institution_id' => 'ins_109508'],
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-fixture-token', [
            'connection' => $connection,
        ]));

        $this->assertSame('access-sandbox-fixture-token', $result['access_token']);
        $this->assertSame('item-sandbox-fixture-id', $result['item_id']);
        $this->assertSame('ins_109508', $result['institution_id']);

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->url() === self::SANDBOX_BASE.'/item/public_token/exchange'
            && $request->data()['public_token'] === 'public-sandbox-fixture-token');
    }

    public function test_exchange_public_token_swallows_an_institution_id_lookup_failure_and_returns_a_null_institution_id(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response([
                'access_token' => 'access-sandbox-fixture-token',
                'item_id' => 'item-sandbox-fixture-id',
            ], 200),
            self::SANDBOX_BASE.'/item/get' => Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-fixture-token', [
            'connection' => $connection,
        ]));

        $this->assertSame('access-sandbox-fixture-token', $result['access_token']);
        $this->assertSame('item-sandbox-fixture-id', $result['item_id']);
        $this->assertNull($result['institution_id'], 'A failed best-effort /item/get lookup must never fail the whole exchange — institution_id simply stays uncaptured.');
    }

    public function test_exchange_public_token_throws_a_sanitized_exception_when_the_response_has_no_usable_access_token_or_item_id(): void
    {
        [$firm, $connection] = $this->makeConnection();

        Http::fake([
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response(['request_id' => 'req-only'], 200),
        ]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->exchangePublicToken('public-sandbox-fixture-token', [
                'connection' => $connection,
            ]));
            $this->fail('Expected a SanitizedProviderHttpException for a response with no access_token/item_id.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, $e->category());
        }
    }
}
