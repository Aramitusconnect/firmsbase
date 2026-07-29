<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\PlaidItemResource\Pages\ViewPlaidItem;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PlaidItemReconnectDisconnectActorResolutionTest — H4 regression
 * coverage. `ViewPlaidItem`'s reconnect/disconnect actions previously
 * passed `$firmUser->id` (a `firm_users.id`) as the
 * `ProviderConnectionService` actor parameter, instead of
 * `$firmUser->user_id` (the real `users.id`
 * `ProviderConnectionService::resolveActingFirmUser()` actually looks
 * the FirmUser up by). Whenever the two independent auto-increment
 * sequences don't coincidentally align — the overwhelming majority of
 * rows in any real database — this made both actions throw "User {id}
 * has no active FirmUser membership in firm {id}" for every firm
 * staff member. Mirrors the identical, already-fixed defect at
 * `PlaidAccountSelectionPage`/`PlaidExchangeController`.
 *
 * Every fixture below deliberately pads the `users` id sequence ahead
 * of the `firm_users` row it creates, so `firm_users.id` and
 * `firm_users.user_id` can never coincidentally match — a fresh test
 * database's default factory usage is exactly what let this defect
 * hide in every prior test (small, coincidentally-aligned ids).
 */
final class PlaidItemReconnectDisconnectActorResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'unit-test-plaid-client-id-h4-actor';

    private const SECRET = 'unit-test-plaid-secret-h4-actor';

    private const SANDBOX_BASE = 'https://sandbox.plaid.test';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));

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

    public function test_reconnect_action_succeeds_when_firm_users_id_differs_from_its_own_user_id(): void
    {
        $firm = $this->plaidEntitledFirm();
        $connection = $this->connectionFor($firm, ConnectionStatus::ReauthorizationRequired);
        $firmUser = $this->offsetFirmUser($firm, FirmUserRole::FirmOwner);
        $this->actingAs($firmUser->user);

        Http::fake([
            self::SANDBOX_BASE.'/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-fixture-token-h4',
                'expiration' => '2026-08-01T00:00:00Z',
            ], 200),
        ]);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ViewPlaidItem::class, ['record' => $connection->uuid]));

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('reconnect');
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertNotified('Reconnect Link session started');
    }

    public function test_disconnect_action_succeeds_when_firm_users_id_differs_from_its_own_user_id(): void
    {
        $firm = $this->plaidEntitledFirm();
        $connection = $this->connectionFor($firm, ConnectionStatus::Active);
        $firmUser = $this->offsetFirmUser($firm, FirmUserRole::FirmOwner);
        $this->actingAs($firmUser->user);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ViewPlaidItem::class, ['record' => $connection->uuid]));

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('disconnect');
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertNotified('Connection disconnected');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function plaidEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function plaidProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->firstOrFail();
    }

    private function connectionFor(Firm $firm, ConnectionStatus $status): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()
                ->forFirm($firm)
                ->forProvider($this->plaidProviderRow())
                ->create([
                    'status' => $status->value,
                    'external_account_id' => 'ext-item-fixture-h4',
                    // initiateLinkTokenConnection() -> PlaidProvider::createLinkToken()
                    // requires a non-empty requested_capabilities array
                    // (it throws InvalidArgumentException otherwise,
                    // sanitized by OutboundProviderHttpClient::execute()
                    // into a RuntimeException) — a real connection always
                    // has this set from its original startConnection()
                    // call.
                    'requested_capabilities_json' => [ResourceType::Transaction->value],
                ]),
        );
    }

    /**
     * Deliberately offsets `users.id` from the `firm_users.id` this
     * fixture produces — see this class's own docblock. Padding the
     * `users` sequence with unrelated rows BEFORE creating the actual
     * actor's own User guarantees `firm_users.id !== users.id` for the
     * very first FirmUser row created in an isolated (RefreshDatabase)
     * test method, never relying on the two independent sequences
     * accidentally landing on different values.
     */
    private function offsetFirmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        User::factory()->count(5)->create();

        $user = User::factory()->create();

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser($user)->role($role)->create()
        );

        $this->assertNotSame(
            $firmUser->id,
            $firmUser->user_id,
            'Fixture setup must produce a FirmUser whose id differs from its own user_id — the exact condition the H4 defect depends on.'
        );

        return $firmUser;
    }
}
