<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Services;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthTenantMismatchException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * ProviderConnectionServicePlaidLinkTokenTest — FirmsVault Live
 * Integrations, Checkpoint 4 (Plaid financial evidence add-on)
 * test-writer pass. Proves `ProviderConnectionService::initiateLinkTokenConnection()`/
 * `completeLinkTokenConnection()`/`finishLinkTokenCallback()` end-to-end
 * against the real, shipped code — mirrors
 * GmailMailboxRoutingLifecycleTest.php's structure/rigor (full
 * ProviderConnectionService call, Http::fake()-only, RefreshDatabase).
 *
 * SCOPE NOTE on bootstrapWebhookSubscriptions(): PlaidProvider implements
 * `RequiresBillableCallPipelineContract`, so — per
 * `checkpoint4-design-cost-control.md` §2.1 call site #3 — a connect
 * whose `requested_capabilities_json` intersects `pullableResourceTypes()`
 * routes `subscribe()` through `App\Integrations\Billing\ProviderBillableCallPipeline::execute()`
 * (kill switches, entitlement, rate cards, dedup lock, cooldown, usage
 * limits — a SEPARATE test-writer's scope for this checkpoint, per this
 * task's own assignment). To keep this file's proof of the Link-token
 * EXCHANGE mechanics (credential storage, item/institution mismatch
 * detection, status transition, webhook-routing-token issuance)
 * independent of that unrelated subsystem, `requested_capabilities_json`
 * is cleared to `[]` between `initiateLinkTokenConnection()` (which does
 * need a non-empty, translatable set to succeed) and
 * `completeLinkTokenConnection()` — this makes
 * `bootstrapWebhookSubscriptions()`'s own `$resourceTypes` intersection
 * empty, so it returns before ever reaching the billing pipeline.
 * `enableWebhookRouting()` (webhook_routing_token issuance) is called
 * unconditionally beforehand and is NOT billing-gated, so that mechanic
 * is still fully exercised here.
 */
final class ProviderConnectionServicePlaidLinkTokenTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-0001';

    private const SECRET = 'unit-test-plaid-secret-0001';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    private const ACCESS_TOKEN = 'access-sandbox-fixture-token-super-secret-0001';

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

    // ------------------------------------------------------------
    // initiateLinkTokenConnection()
    // ------------------------------------------------------------

    public function test_initiate_link_token_connection_returns_the_link_token_and_records_an_audit_event(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();

        $result = $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->initiateLinkTokenConnection($connection, $firmUser->user_id)
        );

        $this->assertSame('link-sandbox-fixture-token', $result->linkToken);
        $this->assertSame('2026-08-01T00:00:00Z', $result->expiration);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('event_type', 'integration_link_token.issued')->where('firm_id', $firm->id)->first()
        );
        $this->assertNotNull($event, 'initiateLinkTokenConnection() must record an integration_link_token.issued audit event.');
    }

    public function test_initiate_link_token_connection_is_denied_for_a_non_management_role(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->pendingConnection($firm, [ResourceType::Transaction->value]);
        $firmUser = $this->firmUserFor($firm, FirmUserRole::Paralegal);
        $this->fakeLinkTokenCreate();

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->initiateLinkTokenConnection($connection, $firmUser->user_id)
        );
    }

    public function test_initiate_link_token_connection_is_denied_when_the_integration_entitlement_is_disabled(): void
    {
        $firm = Firm::factory()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        // Deliberately NOT enabling the 'integration' entitlement here.
        $connection = $this->pendingConnection($firm, [ResourceType::Transaction->value]);
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);
        $this->fakeLinkTokenCreate();

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->initiateLinkTokenConnection($connection, $firmUser->user_id)
        );
    }

    // ------------------------------------------------------------
    // completeLinkTokenConnection() — successful exchange
    // ------------------------------------------------------------

    public function test_complete_link_token_connection_stores_the_access_token_under_provider_access_token_credential_type_and_activates_the_connection(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $result = $this->connect($connection, $firmUser);

        $this->assertTrue($result->successful);
        $this->assertSame(ConnectionStatus::Active, $result->status);

        $credential = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()
                ->where('firm_integration_id', $connection->id)
                ->where('credential_type', CredentialType::ProviderAccessToken->value)
                ->where('status', IntegrationCredentialStatus::Active->value)
                ->first()
        );

        $this->assertNotNull($credential, 'completeLinkTokenConnection() must store the exchanged access_token under CredentialType::ProviderAccessToken.');
        $this->assertNull($credential->expires_at, "Plaid's access_token does not expire on its own.");
    }

    public function test_complete_link_token_connection_captures_external_account_id_and_external_tenant_id_on_first_connect(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $this->connect($connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame('item-sandbox-fixture-id', $fresh->external_account_id);
        $this->assertSame('ins_109508', $fresh->external_tenant_id);
        $this->assertNotNull($fresh->connected_at);
    }

    public function test_complete_link_token_connection_enables_webhook_routing_regardless_of_the_billing_gated_subscribe_step(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $this->connect($connection, $firmUser);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertNotNull($fresh->webhook_routing_token, 'enableWebhookRouting() must run unconditionally on a successful Link-token exchange for a SupportsWebhooksContract provider.');

        $routingIndexCount = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_webhook_routing_index')->where('firm_integration_id', $connection->id)->count()
        );
        $this->assertSame(1, $routingIndexCount);
    }

    public function test_complete_link_token_connection_records_an_exchange_succeeded_audit_event(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $this->connect($connection, $firmUser);

        $event = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('event_type', 'integration_link_token.exchange_succeeded')->where('firm_id', $firm->id)->first()
        );
        $this->assertNotNull($event);
    }

    // ------------------------------------------------------------
    // completeLinkTokenConnection() — duplicate-Item / mismatch detection
    // ------------------------------------------------------------

    public function test_complete_link_token_connection_rejects_a_reauthorization_that_returns_a_different_item_id(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        // A second Link-token exchange against the SAME connection (e.g. a
        // stale/replayed callback, or a duplicate-Item attempt) returns a
        // DIFFERENT item_id than the one already pinned.
        $this->fakeTwoSequentialPublicTokenExchanges(
            ['item-sandbox-original-id', 'item-sandbox-a-completely-different-id'],
            ['ins_109508', 'ins_109508'],
        );
        $this->connect($connection, $firmUser);

        $this->expectException(OAuthAccountMismatchException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->completeLinkTokenConnection($connection, 'public-sandbox-fixture-token-2', $firmUser->user_id)
        );
    }

    public function test_complete_link_token_connection_rejects_a_reauthorization_that_returns_a_different_institution_id(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeTwoSequentialPublicTokenExchanges(
            ['item-sandbox-fixture-id', 'item-sandbox-fixture-id'],
            ['ins_109508', 'ins_a_completely_different_institution'],
        );
        $this->connect($connection, $firmUser);

        $this->expectException(OAuthTenantMismatchException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->completeLinkTokenConnection($connection, 'public-sandbox-fixture-token-2', $firmUser->user_id)
        );
    }

    public function test_a_rejected_item_mismatch_rolls_back_the_whole_callback_and_never_stores_a_second_credential(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeTwoSequentialPublicTokenExchanges(
            ['item-sandbox-original-id', 'item-sandbox-a-completely-different-id'],
            ['ins_109508', 'ins_109508'],
        );
        $this->connect($connection, $firmUser);

        try {
            $this->runWithFirmContext(
                $firm,
                fn () => app(ProviderConnectionService::class)->completeLinkTokenConnection($connection, 'public-sandbox-fixture-token-2', $firmUser->user_id)
            );
            $this->fail('Expected an OAuthAccountMismatchException.');
        } catch (OAuthAccountMismatchException) {
            // expected
        }

        $activeCredentialCount = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()
                ->where('firm_integration_id', $connection->id)
                ->where('credential_type', CredentialType::ProviderAccessToken->value)
                ->where('status', IntegrationCredentialStatus::Active->value)
                ->count()
        );
        $this->assertSame(1, $activeCredentialCount, 'A rejected mismatch must never rotate in a second credential — exactly the original one remains Active.');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame('item-sandbox-original-id', $fresh->external_account_id, 'The originally captured item_id must be left untouched by a rejected reauthorization.');
    }

    // ------------------------------------------------------------
    // completeLinkTokenConnection() — already-disconnected guard
    // ------------------------------------------------------------

    public function test_complete_link_token_connection_throws_when_the_connection_is_already_disconnected(): void
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->pendingConnection($firm, [ResourceType::Transaction->value]);
        $connection = $this->runWithFirmContext($firm, function () use ($connection) {
            $connection->update(['status' => ConnectionStatus::Disconnected->value, 'disconnected_at' => now()]);

            return $connection->fresh();
        });
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        $this->runWithFirmContext(
            $firm,
            fn () => app(ProviderConnectionService::class)->completeLinkTokenConnection($connection, 'public-sandbox-fixture-token', $firmUser->user_id)
        );
    }

    // ------------------------------------------------------------
    // Token redaction — a real, provable assertion across multiple
    // code paths, not just an absence-of-a-string check on one path.
    // ------------------------------------------------------------

    public function test_the_plaintext_access_token_never_appears_in_any_audit_metadata_row_after_a_successful_connect(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $this->connect($connection, $firmUser);

        $events = $this->runWithFirmContext(
            $firm,
            fn () => TimelineEvent::query()->where('firm_id', $firm->id)->get()
        );

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $encoded = json_encode($event->metadata_json);
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $encoded, "Timeline event [{$event->event_type}] metadata must never contain the plaintext access_token.");
        }
    }

    public function test_the_plaintext_access_token_never_appears_in_the_stored_credentials_masked_display_metadata(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeLinkTokenCreate();
        $this->fakePublicTokenExchange('item-sandbox-fixture-id', 'ins_109508');

        $this->connect($connection, $firmUser);

        $credential = $this->runWithFirmContext(
            $firm,
            fn () => IntegrationCredential::query()->where('firm_integration_id', $connection->id)->where('credential_type', CredentialType::ProviderAccessToken->value)->first()
        );

        $this->assertNotNull($credential);
        $encoded = json_encode($credential->masked_display_metadata);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $encoded);

        // The ciphertext column must not merely be "not containing the
        // plaintext" (a weak, coincidental-non-substring check) -- it
        // must be genuinely different from the plaintext, proving
        // encryption actually ran rather than a pass-through no-op.
        $this->assertNotSame(self::ACCESS_TOKEN, $credential->encrypted_payload_ciphertext);
    }

    public function test_the_plaintext_access_token_never_appears_in_an_item_mismatch_exceptions_message(): void
    {
        [$firm, $connection, $firmUser] = $this->firmConnectionAndActor();
        $this->fakeTwoSequentialPublicTokenExchanges(
            ['item-sandbox-original-id', 'item-sandbox-a-completely-different-id'],
            ['ins_109508', 'ins_109508'],
        );
        $this->connect($connection, $firmUser);

        try {
            $this->runWithFirmContext(
                $firm,
                fn () => app(ProviderConnectionService::class)->completeLinkTokenConnection($connection, 'public-sandbox-fixture-token-2', $firmUser->user_id)
            );
            $this->fail('Expected an OAuthAccountMismatchException.');
        } catch (Throwable $e) {
            $this->assertStringNotContainsString(self::ACCESS_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('item-sandbox-original-id', $e->getMessage(), 'Per this codebase\'s "never in raw form" audit rule, the mismatch exception must not even echo the raw identifiers.');
        }
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

    private function pendingConnection(Firm $firm, array $requestedCapabilities): FirmIntegration
    {
        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->plaidProviderRow())
                ->pending()
                ->create([
                    'external_account_id' => null,
                    'requested_capabilities_json' => $requestedCapabilities,
                    'webhook_routing_token' => null,
                ])
        );
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration, 2: FirmUser}
     */
    private function firmConnectionAndActor(): array
    {
        $firm = $this->firmWithActiveKey();
        $connection = $this->pendingConnection($firm, [ResourceType::Transaction->value]);
        $firmUser = $this->firmUserFor($firm, FirmUserRole::FirmOwner);

        return [$firm, $connection, $firmUser];
    }

    /**
     * Full initiate + complete round-trip. See this file's class docblock
     * for why requested_capabilities_json is cleared between the two
     * calls.
     */
    private function connect(FirmIntegration $connection, FirmUser $firmUser, ?string $publicToken = null)
    {
        $service = app(ProviderConnectionService::class);

        $this->runWithFirmContext(
            $connection->firm,
            fn () => $service->initiateLinkTokenConnection($connection, $firmUser->user_id)
        );

        $this->runWithFirmContext(
            $connection->firm,
            fn () => FirmIntegration::query()->where('id', $connection->id)->update(['requested_capabilities_json' => []])
        );

        return $this->runWithFirmContext(
            $connection->firm,
            fn () => $service->completeLinkTokenConnection($connection, $publicToken ?? 'public-sandbox-fixture-token', $firmUser->user_id)
        );
    }

    private function fakeLinkTokenCreate(): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
        ]);
    }

    private function fakePublicTokenExchange(string $itemId, ?string $institutionId): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::response([
                'access_token' => self::ACCESS_TOKEN,
                'item_id' => $itemId,
            ], 200),
            self::SANDBOX_BASE.'/item/get' => $institutionId !== null
                ? Http::response(['item' => ['item_id' => $itemId, 'institution_id' => $institutionId]], 200)
                : Http::response(['error_code' => 'INTERNAL_SERVER_ERROR'], 500),
        ]);
    }

    /**
     * Http::fake() calls are CUMULATIVE within one test (each call merges
     * a new stub callback onto the end of the existing list, and matching
     * picks the FIRST callback that returns non-null — see
     * Illuminate\Http\Client\PendingRequest::buildStubHandler()) — so two
     * separate fakePublicTokenExchange() calls in the SAME test would NOT
     * override each other; the first-registered stub would win both
     * times. This helper instead uses Http::sequence() to give the two
     * exchange/item-get calls their own genuinely distinct, ordered
     * responses within a single Http::fake() call.
     *
     * @param  array{0: string, 1: string}  $itemIds
     * @param  array{0: string, 1: string}  $institutionIds
     */
    private function fakeTwoSequentialPublicTokenExchanges(array $itemIds, array $institutionIds): void
    {
        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
            self::SANDBOX_BASE.'/item/public_token/exchange' => Http::sequence()
                ->push(['access_token' => self::ACCESS_TOKEN, 'item_id' => $itemIds[0]], 200)
                ->push(['access_token' => self::ACCESS_TOKEN, 'item_id' => $itemIds[1]], 200),
            self::SANDBOX_BASE.'/item/get' => Http::sequence()
                ->push(['item' => ['item_id' => $itemIds[0], 'institution_id' => $institutionIds[0]]], 200)
                ->push(['item' => ['item_id' => $itemIds[1], 'institution_id' => $institutionIds[1]]], 200),
        ]);
    }
}
