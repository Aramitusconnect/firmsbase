<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Providers\Plaid;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\SyncCursorService;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PlaidProviderPullSyncTest — FirmsVault Live Integrations, Checkpoint 4
 * (Plaid financial evidence add-on) test-writer pass. Proves
 * `PlaidProvider::pull()` — most deeply for Transactions via
 * `/transactions/sync`'s cursor model (added/modified/removed/has_more,
 * `version_token` construction, and cursor persistence through
 * `SyncCursorService` — the real, shipped services this provider's
 * cursor value round-trips through), plus `supportsIncrementalFor()`
 * and internal-credential-decryption discipline shared by every pull
 * branch.
 *
 * Deliberately calls `PlaidProvider::pull()`/`SyncCursorService`
 * directly rather than through `App\Jobs\PullSyncJob`: for a
 * `RequiresBillableCallPipelineContract` provider like Plaid,
 * `PullSyncJob::runBatchLoop()`'s own pull call is billing-pipeline-gated
 * (`checkpoint4-design-cost-control.md` §2.1 call site #1) — a separate
 * test-writer's scope per this task's own assignment (see
 * `ProviderConnectionServicePlaidLinkTokenTest.php`'s class docblock for
 * the identical reasoning applied to `bootstrapWebhookSubscriptions()`).
 * `PullSyncJob`'s OWN credential-liveness safety-net widening for Plaid
 * (a real, disclosed, cross-cutting fix that runs BEFORE that job's
 * billing-gated call) is proven separately in
 * `tests/Unit/Integrations/Jobs/PullSyncJobPlaidCredentialLivenessTest.php`.
 */
final class PlaidProviderPullSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const ACCESS_TOKEN = 'access-sandbox-fixture-token-pull-0001';

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
    // supportsIncrementalFor()
    // ------------------------------------------------------------

    public function test_only_transaction_is_declared_incremental(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->supportsIncrementalFor(ResourceType::Transaction->value));

        foreach ([ResourceType::BankAccount, ResourceType::Income, ResourceType::Liability, ResourceType::Investment, ResourceType::Statement, ResourceType::Identity] as $fullSnapshotType) {
            $this->assertFalse($provider->supportsIncrementalFor($fullSnapshotType->value), "{$fullSnapshotType->value} must be full-snapshot-only, never incremental.");
        }
    }

    // ------------------------------------------------------------
    // pull() — internal credential decryption (shared by every branch)
    // ------------------------------------------------------------

    public function test_pull_throws_when_there_is_no_active_credential_for_any_resource_type(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()
            ->forFirm($firm)
            ->forProvider($this->plaidProviderRow())
            ->create(['status' => ConnectionStatus::Active->value]));

        try {
            $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));
            $this->fail('Expected a SanitizedProviderHttpException when no credential exists.');
        } catch (SanitizedProviderHttpException $e) {
            $this->assertSame(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, $e->category());
        }

        Http::assertNothingSent();
    }

    public function test_pull_throws_on_an_unsupported_resource_type(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        $this->expectException(\InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], 'not_a_real_resource_type', null));
    }

    // ------------------------------------------------------------
    // /transactions/sync — added/modified/removed/has_more
    // ------------------------------------------------------------

    public function test_pull_transactions_maps_added_and_modified_items_and_reports_the_next_cursor(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/transactions/sync' => Http::response([
                'added' => [
                    ['transaction_id' => 'tx-added-1', 'amount' => 12.5, 'pending' => false, 'date' => '2026-07-01', 'merchant_name' => 'Coffee Shop', 'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK']],
                ],
                'modified' => [
                    ['transaction_id' => 'tx-modified-1', 'amount' => 99.0, 'pending' => false, 'date' => '2026-07-02', 'merchant_name' => 'Grocery Store', 'personal_finance_category' => ['primary' => 'GROCERIES']],
                ],
                'removed' => [],
                'next_cursor' => 'cursor-page-1',
                'has_more' => true,
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));

        $this->assertCount(2, $result['items']);
        $this->assertSame('cursor-page-1', $result['next_cursor']);
        $this->assertTrue($result['has_more']);

        $externalIds = array_column($result['items'], 'external_id');
        $this->assertContains('tx-added-1', $externalIds);
        $this->assertContains('tx-modified-1', $externalIds);

        foreach ($result['items'] as $item) {
            $this->assertSame(64, strlen($item['version_token']), 'version_token must be a sha256 hex digest for a live (non-removed) transaction.');
        }

        Http::assertSent(function (HttpClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === self::SANDBOX_BASE.'/transactions/sync'
                && $body['access_token'] === self::ACCESS_TOKEN
                && ! array_key_exists('cursor', $body); // null cursor is filtered out by withPlatformCredentials()
        });
    }

    public function test_pull_transactions_maps_removed_items_with_a_removed_marker_and_no_content_hash(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/transactions/sync' => Http::response([
                'added' => [],
                'modified' => [],
                'removed' => [['transaction_id' => 'tx-removed-1']],
                'next_cursor' => 'cursor-page-1',
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));

        $this->assertCount(1, $result['items']);
        $this->assertSame('tx-removed-1', $result['items'][0]['external_id']);
        $this->assertSame('removed', $result['items'][0]['version_token']);
        $this->assertTrue($result['items'][0]['raw']['_removed']);
        $this->assertFalse($result['has_more']);
    }

    /**
     * A modified transaction (e.g. pending -> posted) must produce a
     * DIFFERENT version_token than the same transaction_id's earlier
     * snapshot, so PullSyncJob's own conflict-detection branch is
     * correctly reached rather than a silent no-op overwrite.
     */
    public function test_a_modified_transactions_version_token_differs_from_its_earlier_pending_snapshot(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        // Http::fake() calls are CUMULATIVE within a single test — the
        // FIRST-registered stub for a given URL wins for every
        // subsequent request to it (see
        // ProviderConnectionServicePlaidLinkTokenTest.php's
        // fakeTwoSequentialPublicTokenExchanges() docblock for the full
        // explanation) — so the two /transactions/sync responses this
        // test needs must be provided as a genuine Http::sequence(),
        // never as two separate Http::fake() calls.
        Http::fake([
            self::SANDBOX_BASE.'/transactions/sync' => Http::sequence()
                ->push([
                    'added' => [['transaction_id' => 'tx-1', 'amount' => 50.0, 'pending' => true, 'date' => '2026-07-01', 'merchant_name' => 'Store', 'personal_finance_category' => null]],
                    'modified' => [], 'removed' => [], 'next_cursor' => 'cursor-1', 'has_more' => false,
                ], 200)
                ->push([
                    'added' => [],
                    'modified' => [['transaction_id' => 'tx-1', 'amount' => 50.0, 'pending' => false, 'date' => '2026-07-01', 'merchant_name' => 'Store', 'personal_finance_category' => null]],
                    'removed' => [], 'next_cursor' => 'cursor-2', 'has_more' => false,
                ], 200),
        ]);

        $pendingResult = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));
        $postedResult = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'cursor-1'));

        $this->assertNotSame($pendingResult['items'][0]['version_token'], $postedResult['items'][0]['version_token']);
    }

    public function test_pull_transactions_passes_a_null_cursor_on_the_first_call_and_the_supplied_cursor_on_a_subsequent_call(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([self::SANDBOX_BASE.'/transactions/sync' => Http::response(['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'cursor-2', 'has_more' => false], 200)]);

        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, 'cursor-1'));

        Http::assertSent(fn (HttpClientRequest $request): bool => ($request->data()['cursor'] ?? null) === 'cursor-1');
    }

    // ------------------------------------------------------------
    // Cursor persistence via the real SyncCursorService
    // ------------------------------------------------------------

    public function test_the_next_cursor_returned_by_pull_persists_correctly_through_sync_cursor_service(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();
        $cursors = app(SyncCursorService::class);

        $cursor = $this->runWithFirmContext($firm, fn () => $cursors->firstOrCreate($connection, ResourceType::Transaction->value, SyncDirection::Inbound));
        $this->assertNull($this->runWithFirmContext($firm, fn () => $cursors->decryptCursorValue($connection, $cursor)), 'A brand-new cursor must start with no persisted value.');

        Http::fake([
            self::SANDBOX_BASE.'/transactions/sync' => Http::response([
                'added' => [['transaction_id' => 'tx-1', 'amount' => 10.0, 'pending' => false, 'date' => '2026-07-01', 'merchant_name' => 'Store', 'personal_finance_category' => null]],
                'modified' => [],
                'removed' => [],
                'next_cursor' => 'plaid-cursor-value-page-1',
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, null));

        $claimed = $this->runWithFirmContext($firm, fn () => $cursors->claim($cursor->id, 1));
        $this->assertNotNull($claimed);

        $advanced = $this->runWithFirmContext($firm, fn () => $cursors->advance($connection, $cursor->id, $claimed->cursor_version, $result['next_cursor']));

        $persisted = $this->runWithFirmContext($firm, fn () => $cursors->decryptCursorValue($connection, $advanced));
        $this->assertSame('plaid-cursor-value-page-1', $persisted, 'The cursor value pull() returned must round-trip correctly through SyncCursorService::advance()/decryptCursorValue().');

        // A subsequent pull() call reads that persisted cursor back and
        // forwards it as the request's own cursor field — proving the
        // full round-trip, not merely that advance()/decrypt() agree
        // with each other in isolation.
        Http::fake([self::SANDBOX_BASE.'/transactions/sync' => Http::response(['added' => [], 'modified' => [], 'removed' => [], 'next_cursor' => 'plaid-cursor-value-page-2', 'has_more' => false], 200)]);
        $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Transaction->value, $persisted));

        Http::assertSent(fn (HttpClientRequest $request): bool => ($request->data()['cursor'] ?? null) === 'plaid-cursor-value-page-1');
    }

    // ------------------------------------------------------------
    // Full-snapshot resource types — spot checks (BankAccount, Liability)
    // ------------------------------------------------------------

    public function test_pull_bank_accounts_correlates_account_numbers_by_account_id_and_never_advances_a_cursor(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/auth/get' => Http::response([
                'accounts' => [['account_id' => 'account-1', 'verification_status' => 'verified']],
                'numbers' => ['ach' => [['account_id' => 'account-1', 'account' => '1234567890', 'routing' => '021000021']]],
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::BankAccount->value, null));

        $this->assertCount(1, $result['items']);
        $this->assertSame('account-1', $result['items'][0]['external_id']);
        $this->assertNull($result['next_cursor']);
        $this->assertFalse($result['has_more']);
    }

    public function test_pull_liabilities_tags_each_item_with_its_liability_type_as_a_sibling_of_raw(): void
    {
        [$firm, $connection] = $this->makeConnectionWithAccessToken();

        Http::fake([
            self::SANDBOX_BASE.'/liabilities/get' => Http::response([
                'liabilities' => [
                    'credit' => [['account_id' => 'credit-account-1', 'aprs' => []]],
                    'mortgage' => [['account_id' => 'mortgage-account-1']],
                    'student' => [],
                ],
            ], 200),
        ]);

        $result = $this->runWithFirmContext($firm, fn () => $this->provider()->pull(['connection' => $connection], ResourceType::Liability->value, null));

        $this->assertCount(2, $result['items']);
        $byType = array_column($result['items'], 'liability_type', 'external_id');
        $this->assertSame('credit', $byType['credit-account-1']);
        $this->assertSame('mortgage', $byType['mortgage-account-1']);

        // liability_type is a SIBLING of raw, never mixed into it.
        foreach ($result['items'] as $item) {
            $this->assertArrayNotHasKey('liability_type', $item['raw']);
        }
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
