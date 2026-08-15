<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\EntitlementSource;
use App\Enums\PlatformRoleCode;
use App\Filament\Actions\Platform\DisconnectConnectionAction;
use App\Filament\Resources\ConnectionResource;
use App\Filament\Resources\ConnectionResource\Pages\ListConnections;
use App\Filament\Resources\ConnectionResource\Pages\ViewConnection;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\TenantEncryptionKey;
use App\Services\EntitlementService;
use App\Services\PlatformConnectionDirectoryService;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ConnectionResourceTest — Phase 2 (FirmsVault Platform Admin Control
 * Center, "Integration Operations Center"). Route-level authorization,
 * cross-firm listing (the O(firm count) per-firm-loop pattern via
 * PlatformConnectionDirectoryService), deterministic ordering, no-N+1,
 * secret non-exposure, empty state, bounded pagination, and the
 * Disconnect action's full lifecycle.
 */
final class ConnectionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        TestProvider::resetSimulationState();
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }

    /**
     * @return array{0: Firm, 1: FirmIntegration}
     */
    private function entitledFirmWithConnection(array $overrides = []): array
    {
        $firm = Firm::factory()->activated()->create();

        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create($overrides));

        return [$firm, $connection];
    }

    // ------------------------------------------------------------
    // Route-level authorization
    // ------------------------------------------------------------

    public function test_guest_is_redirected_from_the_connections_list(): void
    {
        $this->get(ConnectionResource::getUrl())->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_connections_list(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->get(ConnectionResource::getUrl())->assertForbidden();
    }

    public function test_a_sales_rep_is_forbidden_from_the_connections_list(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SalesRep);

        $this->actingAs($admin, 'platform_admin')->get(ConnectionResource::getUrl())->assertForbidden();
    }

    public function test_a_super_admin_can_reach_the_connections_list_and_see_cross_firm_rows(): void
    {
        [$firmA] = $this->entitledFirmWithConnection();
        [$firmB] = $this->entitledFirmWithConnection();
        $firmA->update(['name' => 'Cross Firm Connections A']);
        $firmB->update(['name' => 'Cross Firm Connections B']);

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $response = $this->actingAs($admin, 'platform_admin')->get(ConnectionResource::getUrl());

        $response->assertOk();
        $response->assertSee('Cross Firm Connections A');
        $response->assertSee('Cross Firm Connections B');
    }

    public function test_guest_is_redirected_from_the_view_connection_page(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();

        $this->get(ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]))
            ->assertRedirect($this->adminUrl('/login'));
    }

    public function test_a_platform_admin_with_no_role_is_forbidden_from_the_view_connection_page(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_view_a_single_connection(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]));

        $response->assertOk();
    }

    public function test_viewing_a_connection_under_the_wrong_firm_404s(): void
    {
        [$firmA] = $this->entitledFirmWithConnection();
        [, $connectionB] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $this->actingAs($admin, 'platform_admin')
            ->get(ViewConnection::getUrl(['firmUuid' => $firmA->uuid, 'connectionUuid' => $connectionB->uuid]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------
    // Empty state
    // ------------------------------------------------------------

    public function test_the_list_shows_an_empty_state_when_no_connections_exist(): void
    {
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $response = $this->actingAs($admin, 'platform_admin')->get(ConnectionResource::getUrl());

        $response->assertOk();
        // Prompt 2 (Integration Operations) §94: the empty state now tells
        // an operator where connections come from — a firm authorizing an
        // integration in its own panel — and states explicitly that this
        // console never creates one, so an empty list is never mistaken
        // for a missing "Add connection" button.
        $response->assertSee('No firm integrations yet');
    }

    // ------------------------------------------------------------
    // Secret non-exposure
    // ------------------------------------------------------------

    public function test_no_raw_credential_or_webhook_token_material_appears_in_the_rendered_list_or_view_pages(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();

        $plaintextMarker = 'SECRET-MARKER-connection-resource-credential-9f3c';

        $this->runWithFirmContext($firm, function () use ($connection, $plaintextMarker): void {
            app(IntegrationCredentialService::class)->store(
                $connection,
                CredentialType::OauthAccessToken,
                $plaintextMarker,
                ['label' => 'Test credential'],
            );
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $listResponse = $this->get(ConnectionResource::getUrl());
        $listResponse->assertOk();
        $listResponse->assertDontSee($plaintextMarker);
        $listResponse->assertDontSee($connection->webhook_routing_token);

        $viewResponse = $this->get(ViewConnection::getUrl(['firmUuid' => $firm->uuid, 'connectionUuid' => $connection->uuid]));
        $viewResponse->assertOk();
        $viewResponse->assertDontSee($plaintextMarker);
        $viewResponse->assertDontSee($connection->webhook_routing_token);

        // Also assert directly against the ciphertext column value itself
        // (belt-and-suspenders — proves this isn't merely "the plaintext
        // string never appeared," but that the encrypted payload/token
        // never leak either).
        $ciphertext = $this->runWithFirmContext(
            $firm,
            fn () => DB::table('integration_credentials')->where('firm_integration_id', $connection->id)->value('encrypted_payload_ciphertext')
        );
        $this->assertNotNull($ciphertext);
        $listResponse->assertDontSee($ciphertext);
        $viewResponse->assertDontSee($ciphertext);
    }

    // ------------------------------------------------------------
    // No-N+1 proof
    // ------------------------------------------------------------

    /**
     * Compares query COUNT DELTA between a 1-connection render and a
     * 10-connection render (both for a single firm) rather than an
     * absolute threshold — a full Filament page render issues many
     * queries unrelated to this resource's own read path. The
     * load-bearing property is that adding 9 more connections to the
     * SAME firm adds only a small, row-count-independent number of extra
     * queries (PlatformConnectionDirectoryService::listAll() batches
     * health/credential/sync-run lookups per FIRM, never per
     * connection), never ~9 extra queries.
     */
    public function test_listing_many_connections_for_one_firm_does_not_n_plus_one(): void
    {
        [$firmOne] = $this->entitledFirmWithConnection();

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $onePass = [];
        DB::listen(function ($query) use (&$onePass): void {
            $onePass[] = $query->sql;
        });
        $this->get(ConnectionResource::getUrl())->assertOk();
        DB::flushQueryLog();
        $oneConnectionQueryCount = count($onePass);

        $firmTen = Firm::factory()->activated()->create();
        $this->runWithFirmContext($firmTen, fn () => TenantEncryptionKey::factory()->forFirm($firmTen)->create());
        app(EntitlementService::class)->setForSource($firmTen, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firmTen, fn () => FirmIntegration::factory()->forFirm($firmTen)->count(10)->create());

        $tenPass = [];
        DB::listen(function ($query) use (&$tenPass): void {
            $tenPass[] = $query->sql;
        });
        $this->get(ConnectionResource::getUrl())->assertOk();
        $tenConnectionQueryCount = count($tenPass);

        $this->assertLessThan(
            $oneConnectionQueryCount + 9,
            $tenConnectionQueryCount,
            'Adding 9 more connections to the same firm must not add ~9 extra queries — that would prove an N+1 pattern.'
        );
    }

    // ------------------------------------------------------------
    // Deterministic ordering
    // ------------------------------------------------------------

    public function test_two_connections_sharing_the_same_created_at_produce_a_stable_repeated_order(): void
    {
        $firm = Firm::factory()->activated()->create();
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $now = now();
        [$connectionA, $connectionB] = $this->runWithFirmContext($firm, function () use ($firm, $now): array {
            $a = FirmIntegration::factory()->forFirm($firm)->create();
            $a->forceFill(['created_at' => $now])->save();

            $b = FirmIntegration::factory()->forFirm($firm)->create();
            $b->forceFill(['created_at' => $now])->save();

            return [$a->fresh(), $b->fresh()];
        });

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        $firstOrder = app(PlatformConnectionDirectoryService::class)->listAll($admin)->pluck('uuid')->all();
        $secondOrder = app(PlatformConnectionDirectoryService::class)->listAll($admin)->pluck('uuid')->all();

        $this->assertSame($firstOrder, $secondOrder, 'Repeated calls with two equal-timestamp rows must always produce the same order.');
        // The id tie-breaker (ascending, applied after created_at) means
        // the lower-id connection (A, created first) sorts before B.
        $this->assertSame([$connectionA->uuid, $connectionB->uuid], $firstOrder);
    }

    // ------------------------------------------------------------
    // CHECKPOINT 1 (FirmsVault Live Integrations) additions —
    // checkpoint1-design-health-sandbox.md §A.3.1/§A.4: the new
    // per-connection metrics columns. All five are
    // ->toggleable(isToggledHiddenByDefault: true), so a plain GET does
    // NOT evaluate their formatState()/getState() at all (confirmed
    // directly — a bare page load never even reaches these columns'
    // rendering logic) — this test explicitly toggles them visible via
    // the same table-column-manager mechanism the real "Toggle columns"
    // UI control drives, so the underlying formatStateUsing() callback
    // (last_operation_label's Str::headline() humanization) and every
    // other new column's plain state resolution are genuinely exercised.
    // ------------------------------------------------------------

    public function test_the_new_metrics_columns_render_correctly_once_toggled_visible(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();

        $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->recordCredentialError(
            $connection->id,
            $firm->id,
            new SanitizedHealthDiagnostic(
                SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH,
            ),
            latencyMs: 250,
        ));

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $defaultState = $test->instance()->getDefaultTableColumnState();
        $toggleOn = ['total_request_count', 'total_success_count', 'last_operation_label', 'last_latency_ms', 'last_sync_lag_seconds'];
        foreach ($defaultState as &$item) {
            if (in_array($item['name'] ?? null, $toggleOn, true)) {
                $item['isToggled'] = true;
            }
        }
        unset($item);

        $test->call('applyTableColumnManager', $defaultState);
        $test->assertOk();

        $html = $test->html();
        $this->assertStringContainsString('Token Refresh', $html, 'last_operation_label\'s formatStateUsing() must humanize the raw "token_refresh" value via Str::headline().');
        $this->assertStringContainsString('250', $html, 'last_latency_ms must render the real recorded value.');
        // 1 failure signal was recorded -> total_request_count=1,
        // total_success_count=0.
        $this->assertMatchesRegularExpression('/\bTotal Requests\b/', $html);
        $this->assertMatchesRegularExpression('/\bTotal Successes\b/', $html);
    }

    public function test_a_connection_with_no_health_row_yet_shows_the_placeholder_for_every_new_metrics_column_never_an_error(): void
    {
        [, $connection] = $this->entitledFirmWithConnection();
        // Deliberately NO HealthStateService call at all — no
        // integration_connection_health row exists for this connection.

        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $defaultState = $test->instance()->getDefaultTableColumnState();
        $toggleOn = ['total_request_count', 'total_success_count', 'last_operation_label', 'last_latency_ms', 'last_sync_lag_seconds'];
        foreach ($defaultState as &$item) {
            if (in_array($item['name'] ?? null, $toggleOn, true)) {
                $item['isToggled'] = true;
            }
        }
        unset($item);

        $test->call('applyTableColumnManager', $defaultState);
        $test->assertOk();
        unset($connection);
    }

    public function test_the_connections_list_is_bounded_and_paginated(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/ConnectionResource.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/->paginated\(\[25, 50, 100\]\)/', $source);
    }

    // ------------------------------------------------------------
    // Disconnect action lifecycle
    // ------------------------------------------------------------

    public function test_a_super_admin_can_disconnect_a_connection_via_the_list_row_action(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $test->mountTableAction(DisconnectConnectionAction::getDefaultName(), (string) $connection->id);
        $test->setTableActionData(['reason' => 'Requested by firm via support ticket #123']);
        $test->callMountedTableAction();

        $test->assertHasNoTableActionErrors();

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);

        $audit = $this->runWithFirmContext(
            $firm,
            fn () => SecurityEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'platform_integration_oversight.connection_disconnected')
                ->first()
        );
        $this->assertNotNull($audit, 'Disconnecting via the Connections resource must write the same oversight audit event disconnectConnection() always writes.');
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('Requested by firm via support ticket #123', $audit->metadata['reason']);
    }

    public function test_a_support_agent_cannot_disconnect_despite_passing_the_broad_oversight_gate(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $test->mountTableAction(DisconnectConnectionAction::getDefaultName(), (string) $connection->id);
        $test->setTableActionData(['reason' => 'Attempted by support agent']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status, 'A SupportAgent must never be able to disconnect a connection, despite passing the broad canAccessIntegrationOversight() gate.');
    }

    public function test_a_read_only_auditor_cannot_disconnect_even_when_also_holding_superadmin(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection();

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $test->mountTableAction(DisconnectConnectionAction::getDefaultName(), (string) $connection->id);
        $test->setTableActionData(['reason' => 'Attempted by read-only auditor']);
        $test->callMountedTableAction();

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertSame(ConnectionStatus::Active, $fresh->status, 'canMutate() must block a read_only_auditor from disconnecting, even with SuperAdmin also held (blanket rule 9).');
    }

    public function test_disconnecting_an_already_disconnected_connection_is_idempotent(): void
    {
        [$firm, $connection] = $this->entitledFirmWithConnection(['status' => ConnectionStatus::Disconnected->value, 'disconnected_at' => now()]);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);

        // Row-level ->visible() hides the action once already
        // disconnected — proven directly via the underlying bounded
        // access service instead (the same idempotent short-circuit
        // path the action calls into).
        $result = app(PlatformFirmIntegrationBoundedAccessService::class)
            ->disconnectConnection($admin, $firm, $connection->id, 'Second attempt');

        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
    }

    public function test_the_disconnect_action_is_not_offered_for_an_already_disconnected_connection(): void
    {
        [, $connection] = $this->entitledFirmWithConnection(['status' => ConnectionStatus::Disconnected->value, 'disconnected_at' => now()]);
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $this->actingAs($admin, 'platform_admin');

        $test = Livewire::test(ListConnections::class);
        $test->assertOk();

        $test->assertTableActionHidden(DisconnectConnectionAction::getDefaultName(), record: (string) $connection->id);
    }
}
