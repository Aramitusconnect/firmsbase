<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FirmIntegrationReconnectActionTest — FirmsVault Live Integrations,
 * Checkpoint 2 (test-writer pass). Covers
 * ViewFirmIntegration::reconnectAction() ("Add Capabilities / Reconnect",
 * checkpoint2-combined-design.md §4; checkpoint2-design-ui.md §4):
 * visibility per connection status, and that a genuine submission calls
 * ProviderConnectionService::updateRequestedCapabilities() and redirects
 * to the existing `integrations.oauth.initiate` route.
 *
 * Follows the EXACT pattern already proven in
 * FirmIntegrationConnectProviderDropdownVisibilityTest
 * (`Filament::setCurrentPanel(Filament::getPanel('firm'))` in setUp())
 * and FirmIntegrationConnectionLifecycleActionsTest (firm/entitlement/
 * auth setup, and the "wrap the ENTIRE mountAction()/callMountedAction()
 * round trip in runWithFirmContext()" discipline that page's own class
 * docblock documents as necessary for a genuine mutating-action round
 * trip against this Filament version).
 */
final class FirmIntegrationReconnectActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Visibility
    // ------------------------------------------------------------

    /**
     * @return array<string, array{0: ConnectionStatus}>
     */
    public static function visibleStatusProvider(): array
    {
        return [
            'active' => [ConnectionStatus::Active],
            'scope insufficient' => [ConnectionStatus::ScopeInsufficient],
            'reauthorization required' => [ConnectionStatus::ReauthorizationRequired],
        ];
    }

    #[DataProvider('visibleStatusProvider')]
    public function test_reconnect_action_is_visible_for_active_scope_insufficient_and_reauthorization_required(ConnectionStatus $status): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, $status);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]),
        );

        $test->assertActionVisible('reconnect');
    }

    /**
     * @return array<string, array{0: ConnectionStatus}>
     */
    public static function hiddenStatusProvider(): array
    {
        return [
            'pending' => [ConnectionStatus::Pending],
            'disconnected' => [ConnectionStatus::Disconnected],
            'error' => [ConnectionStatus::Error],
        ];
    }

    #[DataProvider('hiddenStatusProvider')]
    public function test_reconnect_action_is_hidden_for_pending_disconnected_and_error(ConnectionStatus $status): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, $status);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]),
        );

        $test->assertActionHidden('reconnect');
    }

    public function test_reconnect_action_is_hidden_below_the_configure_ceiling_even_for_an_active_connection(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ConnectionStatus::Active);
        // LegalAssistant is within IntegrationAccessPolicyService::VIEW_ROLES
        // (so the page itself mounts) but below MANAGEMENT_ROLES/
        // canConfigure()'s ceiling — mirrors
        // FirmIntegrationConnectionLifecycleActionsTest::test_rename_connection_is_denied_below_the_configure_ceiling's
        // identical role choice for the same "below ceiling, but page-
        // viewable" proof. Receptionist is deliberately NOT used here:
        // it is below even canView()'s ceiling (this class's own
        // docblock: "Receptionist never appears in any allowlist below,
        // full stop."), so mounting the page for a Receptionist 403s
        // before Livewire's dehydrate hook ever fires — a different,
        // page-level-inaccessible scenario, not "page visible, action
        // hidden."
        $this->actingAsRole($firm, FirmUserRole::LegalAssistant);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]),
        );

        $test->assertActionHidden('reconnect');
    }

    // ------------------------------------------------------------
    // Submission
    // ------------------------------------------------------------

    public function test_submitting_reconnect_action_updates_requested_capabilities_and_redirects_to_oauth_initiate(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ConnectionStatus::Active, initialCapabilities: []);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]),
        );

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('reconnect');
            $test->setActionData(['capabilities' => ['contact']]);
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertRedirect(route('integrations.oauth.initiate', $connection));

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame(['contact'], $fresh->requested_capabilities_json, 'A genuine submission must call updateRequestedCapabilities() and persist the new capability set.');
    }

    public function test_submitting_reconnect_action_with_an_empty_capability_set_clears_requested_capabilities_and_still_redirects(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ConnectionStatus::ScopeInsufficient, initialCapabilities: ['contact']);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]),
        );

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('reconnect');
            $test->setActionData(['capabilities' => []]);
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertRedirect(route('integrations.oauth.initiate', $connection));

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame([], $fresh->requested_capabilities_json);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function entitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function makeTestProviderRow(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', ProviderKey::Test->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => ProviderKey::Test->value]);
    }

    private function connectionFor(Firm $firm, ConnectionStatus $status, array $initialCapabilities = ['contact']): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        $provider = $this->makeTestProviderRow();

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($provider)->create([
                'external_account_id' => null,
                'status' => $status->value,
                'requested_capabilities_json' => $initialCapabilities,
            ]),
        );
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create(),
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
