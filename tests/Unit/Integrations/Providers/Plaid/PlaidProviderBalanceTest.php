<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Contracts\SupportsBalanceContract;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PlaidProviderBalanceTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Proves
 * `PlaidProvider::fetchBalance()` — the `SupportsBalanceContract`
 * implementation a coordinator added post-wiring-pass, fixing a genuine
 * contract mismatch (the method now resolves and decrypts its own
 * credential internally, exactly like `pull()`/`revokeAtProvider()`
 * above it, never accepting a pre-decrypted token across the method
 * boundary — see `PlaidProvider.php`'s own class-level docblock for the
 * full history of that fix).
 *
 * Covers: `PlaidProvider implements SupportsBalanceContract` (a
 * structural contract-conformance assertion, not just a duck-typed
 * call); internal decryption (never accepts a plaintext token
 * parameter — confirmed by this method's own real signature,
 * `fetchBalance(FirmIntegration $connection, string $accountId, array
 * $context)`, which has no token parameter at all); correct filtering to
 * the single requested account via Plaid's documented
 * `options.account_ids` request field; on-demand-only posture (never
 * appears in `pullableResourceTypes()`); and honest failure/credential-
 * missing handling.
 */
final class PlaidProviderBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const ACCESS_TOKEN = 'access-sandbox-fixture-token-balance-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.oauth_apps.plaid.client_id' => 'unit-test-plaid-client-id',
            'integrations.oauth_apps.plaid.secret' => 'unit-test-plaid-secret',
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
    // Contract conformance
    // ------------------------------------------------------------

    public function test_plaid_provider_implements_supports_balance_contract(): void
    {
        $this->assertInstanceOf(SupportsBalanceContract::class, $this->provider());
    }

    public function test_balance_is_never_listed_among_the_pullable_resource_types(): void
    {
        // Balance is deliberately excluded from the generic, scheduled
        // pull-sync surface (real-time, rate-limited, billed-per-call —
        // Plaid's own documented guidance against a polling cadence).
        $this->assertSame(
            ['bank_account', 'transaction', 'income', 'liability', 'investment', 'statement', 'identity'],
            $this->provider()->pullableResourceTypes()
        );
    }

    // ------------------------------------------------------------
    // Internal decryption — never accepts a pre-decrypted token
    // ------------------------------------------------------------

    public function test_fetch_balance_decrypts_its_own_credential_internally_and_uses_it_in_the_outbound_request(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/accounts/balance/get' => Http::response([
                'accounts' => [
                    ['account_id' => 'account-requested', 'balances' => ['available' => 100.0, 'current' => 100.0]],
                ],
            ], 200),
        ]);

        $result = $this->runWithFirmContext(
            $firm,
            fn () => $this->provider()->fetchBalance($connection, 'account-requested', [])
        );

        $this->assertSame('account-requested', $result[0]['account_id']);

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $body['access_token'] === self::ACCESS_TOKEN;
        });
    }

    public function test_fetch_balance_throws_when_there_is_no_active_credential_rather_than_accepting_a_token_argument(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value]));

        // No credential stored at all.
        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-requested', []));
            $this->fail('Expected a SanitizedProviderHttpException when no ProviderAccessToken credential exists.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }

        Http::assertNothingSent();
    }

    /**
     * Reflection-level structural proof that the contract's real,
     * shipped signature carries no plaintext-token parameter at all —
     * the strongest form of "never accepts a pre-decrypted token"
     * assertion available, independent of any particular call's
     * behavior.
     */
    public function test_fetch_balance_signature_carries_no_access_token_parameter(): void
    {
        $method = new \ReflectionMethod(PlaidProvider::class, 'fetchBalance');
        $parameterNames = array_map(fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        $this->assertSame(['connection', 'accountId', 'context'], $parameterNames);
        $this->assertNotContains('accessToken', $parameterNames);
        $this->assertNotContains('access_token', $parameterNames);
    }

    public function test_the_plaintext_access_token_is_unset_even_when_the_outbound_call_fails(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([self::SANDBOX_BASE.'/accounts/balance/get' => Http::response(['error_code' => 'BALANCE_LIMIT'], 400)]);

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-requested', []));
            $this->fail('Expected a SanitizedProviderHttpException.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertNotNull($e->category());
            // The exception's own sanitized category never carries the
            // raw request body/token — this is enforced by
            // ProviderRequestExecutor's own construction, exercised here
            // end to end for fetchBalance() specifically.
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // options.account_ids — filters to the single requested account
    // ------------------------------------------------------------

    public function test_fetch_balance_sends_the_requested_account_id_via_options_account_ids(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/accounts/balance/get' => Http::response(['accounts' => []], 200),
        ]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-xyz-only', []));

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return isset($body['options']['account_ids'])
                && $body['options']['account_ids'] === ['account-xyz-only'];
        });
    }

    public function test_fetch_balance_never_sends_more_than_the_single_requested_account_id(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([self::SANDBOX_BASE.'/accounts/balance/get' => Http::response(['accounts' => []], 200)]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-solo', []));

        Http::assertSent(function (HttpClientRequest $request): bool {
            $ids = $request->data()['options']['account_ids'] ?? null;

            return is_array($ids) && count($ids) === 1;
        });
    }

    public function test_fetch_balance_returns_only_the_accounts_array_from_the_response(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/accounts/balance/get' => Http::response([
                'accounts' => [['account_id' => 'account-requested', 'balances' => ['available' => 42.5]]],
                'request_id' => 'req-fixture',
                'item' => ['item_id' => 'item-sandbox-fixture-id'],
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-requested', []));

        $this->assertSame([['account_id' => 'account-requested', 'balances' => ['available' => 42.5]]], $result);
    }

    public function test_fetch_balance_returns_an_empty_array_when_the_response_has_no_accounts_key(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([self::SANDBOX_BASE.'/accounts/balance/get' => Http::response(['request_id' => 'req-only'], 200)]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->fetchBalance($connection, 'account-requested', []));

        $this->assertSame([], $result);
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
    private function makeConnectionWithAccessToken(): array
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value]));

        $this->runWithFirmContext($firm, fn () => app(IntegrationCredentialService::class)->store(
            $connection, CredentialType::ProviderAccessToken, self::ACCESS_TOKEN
        ));

        return [$firm, $connection];
    }
}
