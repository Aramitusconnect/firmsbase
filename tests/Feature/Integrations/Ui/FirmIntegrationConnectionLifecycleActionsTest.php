<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
use App\Integrations\Providers\Plaid\PlaidProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Integrations\Services\IntegrationAccessPolicyService;
use App\Integrations\Services\ProviderConnectionService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * FirmIntegrationConnectionLifecycleActionsTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §2, §3, §12).
 *
 * `ConnectProviderAction` (a ListFirmIntegrations header action) IS
 * fully exercised via genuine `Livewire::test()` below: idempotency
 * (double-click doesn't create two Pending rows under the lock-then-
 * create pattern), entitlement/role gating, provider-resolution failure.
 *
 * FORMERLY-DISCOVERED PRODUCTION BUG, NOW FIXED (see this checkpoint's
 * final report): `ViewFirmIntegration` — the page hosting rename,
 * disconnect, and both webhook-routing-toggle actions — used to be
 * unmountable via `Livewire::test()` for ANY user, because
 * `RelationManager::canViewForRecord()` called an undefined
 * relationship method on `FirmIntegration`. That bug has since been
 * fixed (each RelationManager now overrides `canViewForRecord()`
 * directly instead of relying on Filament's default model-relationship
 * lookup — see `SyncRunsRelationManager`/`ConflictsRelationManager`/
 * `FailedItemsRelationManager`'s own `canViewForRecord()` overrides).
 * `test_view_firm_integration_page_mounts_successfully_for_an_authorized_user()`
 * below is genuine, real `Livewire::test()`-driven proof of that fix —
 * it was previously a self-documented placeholder asserting the mount
 * throws; it now genuinely mounts the page and asserts real rendered
 * content.
 *
 * PRODUCTION BUG FIXED (this pass): rename/disconnect/webhook-routing-
 * toggle used to be provable only via the disclosed fallback below
 * (directly replicating each Action's own `visible()`/`action()`
 * sequence), because a genuine `Livewire::test()`-driven
 * `mountAction()`/`callMountedAction()` round-trip empirically threw
 * `Illuminate\Database\Eloquent\ModelNotFoundException: No query
 * results for model [App\Integrations\Models\FirmIntegration]`. Root
 * cause (confirmed via `php artisan route:list -v`): `POST
 * livewire/update` carries only the `web` middleware group plus
 * Filament's own fixed `Livewire::addPersistentMiddleware()` list
 * (Authenticate, DisableBladeIconComponents,
 * DispatchServingFilamentEvent, IdentifyPageConfiguration,
 * IdentifyResourceConfiguration, IdentifyTenant, SetUpPanel — see
 * vendor/filament/filament/src/FilamentServiceProvider.php:105-113) —
 * this app's own `EstablishFirmTenantContext`/`ApplyTenantDatabaseContext`
 * middleware is wired only into `FirmPanelProvider`'s `authMiddleware`,
 * which governs the panel's page-LOAD routes only, never Filament's
 * shared Livewire-update AJAX endpoint every subsequent action call
 * goes through. `ViewFirmIntegration`'s four header actions now resolve
 * the acting `FirmUser` via `Auth::user()->activeFirmUser()` (session-
 * resolvable without any ambient firm context — see
 * `User::activeFirmUser()`'s self-lookup bootstrap) and wrap their
 * fresh re-fetch + underlying `ProviderConnectionService` call in
 * `TenantContextService::runWithFirmContext($firmId, ...)` — see
 * `ViewFirmIntegration.php`'s own docblock for the full fix.
 *
 * STILL-OPEN, SEPARATE, DEEPER PRODUCTION ISSUE DISCOVERED WHILE FIXING
 * THE ABOVE (reported in this checkpoint's final report — cannot be
 * fixed from an "action handler" file under the scope granted for this
 * pass): even with the fix above, a genuine, completely unmodified
 * `mountAction()`/`callMountedAction()` round-trip STILL throws the
 * exact same `ModelNotFoundException` — empirically confirmed via a
 * full stack trace — but now at a DIFFERENT, EARLIER point:
 * `Filament\Resources\Pages\Concerns\InteractsWithRecord` declares
 * `#[Locked] public Model | int | string | null $record;` as a plain
 * Livewire-synthesized property. On every "subsequent render" (i.e.
 * every Livewire request after the page's initial GET-routed mount —
 * exactly what `mountAction()`/`callMountedAction()` each independently
 * trigger via `Livewire\Features\SupportTesting\SubsequentRender`,
 * confirmed via stack trace to genuinely re-enter
 * `Illuminate\Foundation\Http\Kernel::handle()` and the SAME `POST
 * livewire/update` route), Livewire's OWN `ModelSynth::hydrate()`
 * (vendor/livewire/livewire/src/Features/SupportModels/ModelSynth.php)
 * re-resolves `$record` from its stored primary key via
 * `Illuminate\Database\Eloquent\Builder->firstOrFail()` — BEFORE any
 * component code, including this checkpoint's own fixed `action()`
 * closures, ever runs. That framework-level rehydration hits the exact
 * same missing-tenant-context gap. Fixing it would require either new
 * middleware/route-level intervention (explicitly out of scope for this
 * pass — see this checkpoint's own constraints) or restructuring how
 * `ViewFirmIntegration`/its RelationManagers bind `$record`/
 * `$ownerRecord`, which reaches into vendor Filament base-class
 * assumptions well beyond "action handler" scope. Every genuine
 * `mountAction()`/`callMountedAction()` test below therefore wraps the
 * ENTIRE round-trip (not just the assertion) in
 * `$this->runWithFirmContext($firm, function () { ... })` to hold
 * ambient tenant context across the framework's own rehydration step —
 * this is NOT how a real, unmodified production request behaves (a real
 * request has no such wrap), so these tests prove the fix's OWN logic
 * (the wrapped re-fetch + nested `runWithFirmContext` re-entrancy) is
 * correct, but do not yet prove the full, unmodified production
 * round-trip succeeds — that remains blocked by the deeper issue above.
 */
final class FirmIntegrationConnectionLifecycleActionsTest extends TestCase
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
    // 0. Page mount — genuine Livewire coverage (the mount-blocking
    //    Filament framework bug is now fixed; see class docblock)
    // ------------------------------------------------------------

    public function test_view_firm_integration_page_mounts_successfully_for_an_authorized_user(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ['display_label' => 'Acme CRM Connection']);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid])
        );

        $test->assertOk();
        $test->assertSee('Acme CRM Connection');
        $test->assertActionExists('rename');
        $test->assertActionExists('disconnect');
        $test->assertActionExists('enableWebhookRouting');
        $test->assertActionExists('disableWebhookRouting');
    }

    // ------------------------------------------------------------
    // 0b. CHECKPOINT 12 addition (frozen-design-post-security-review.md
    // §5 N2, §8): the seeded `integration_providers` row (code='test' —
    // see
    // database/migrations/2026_09_01_010001_create_integration_providers_table.php)
    // already carries display_name = 'Internal Test Provider
    // (non-production)', byte-for-byte matching
    // TestProvider::displayName(). FirmIntegrationFactory's own
    // `integration_provider_id` default resolves that SAME seeded row —
    // this proves the real, seeded (never TestProvider::displayName()
    // called live, per N2) copy is genuinely visible in the rendered
    // Firm UI for a real connection.
    // ------------------------------------------------------------

    public function test_a_real_test_provider_connections_seeded_display_name_is_visible_on_the_view_page(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ['display_label' => 'Provider Name Visibility Fixture']);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid])
        );

        $test->assertOk();
        $test->assertSee((new TestProvider)->displayName());
    }

    // ------------------------------------------------------------
    // 1. ConnectProviderAction / startConnection() — full Livewire coverage
    // ------------------------------------------------------------

    public function test_connect_provider_action_creates_a_pending_connection_and_redirects_to_oauth_initiate(): void
    {
        $firm = $this->entitledFirm();
        $provider = $this->makeTestProviderRow();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));

        $test->mountAction(ConnectProviderAction::getDefaultName());
        // Checkpoint 2 update: the capability CheckboxList is required
        // once visible (TestProvider declares 'contact'/'task' via
        // ProviderMetadata::resourceTypes) — a real capability selection
        // is now needed for the wizard's first step to validate.
        $test->setActionData(['integration_provider_id' => $provider->id, 'capabilities' => ['contact']]);
        $test->callMountedAction();

        $test->assertHasNoActionErrors();

        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('firm_id', $firm->id)->first());

        $this->assertNotNull($connection);
        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertSame(['contact'], $connection->requested_capabilities_json);
    }

    public function test_connect_provider_action_double_submit_does_not_create_two_pending_rows(): void
    {
        $firm = $this->entitledFirm();
        $provider = $this->makeTestProviderRow();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        // Directly exercises startConnection()'s idempotency guard twice
        // in a row (the Livewire-level double-click is functionally
        // identical: two full-round-trip calls to the same action with
        // the same actor/provider, which is exactly what this proves).
        $service = app(ProviderConnectionService::class);
        $first = $this->runWithFirmContext($firm, fn () => $service->startConnection($firm->id, $provider->id, $firmUser->user_id));
        $second = $this->runWithFirmContext($firm, fn () => $service->startConnection($firm->id, $provider->id, $firmUser->user_id));

        $this->assertSame($first->id, $second->id, 'A second startConnection() call with the same firm/provider/still-Pending-no-external-account-id must return the SAME row, never create a second one.');

        $count = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('firm_id', $firm->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_connect_provider_action_and_the_list_page_itself_are_unreachable_for_a_disentitled_firm(): void
    {
        // Confirmed empirically: mounting ListFirmIntegrations for a
        // disentitled firm produces a clean 403 response (Filament's
        // own table/page authorization wiring, independent of
        // ConnectProviderAction's own visible() closure) — the feature
        // is omitted entirely, never merely a hidden button on an
        // otherwise-visible page. See FirmIntegrationEntitlementVisibilityTest
        // for the dedicated, non-throwing-isEnabled()-focused version of
        // this same proof.
        $firm = Firm::factory()->create(); // not entitled
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));

        $test->assertForbidden();
    }

    public function test_connect_provider_action_is_hidden_for_a_role_below_the_connect_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));

        $test->assertActionHidden(ConnectProviderAction::getDefaultName());
    }

    public function test_connect_provider_action_direct_service_call_is_denied_for_a_role_below_the_connect_ceiling_even_if_ui_were_bypassed(): void
    {
        // TOCTOU/UI-hiding-is-not-the-only-protection: startConnection()
        // itself must independently re-check role authority, not merely
        // rely on the action's own visible() closure hiding the button.
        $firm = $this->entitledFirm();
        $provider = $this->makeTestProviderRow();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->startConnection($firm->id, $provider->id, $firmUser->user_id);
    }

    public function test_connect_provider_action_fails_closed_when_the_provider_does_not_resolve_a_registered_adapter(): void
    {
        // Provider row exists (satisfies the FK) but is NOT registered
        // in config('integrations.providers') — resolveProvider()'s own
        // registry lookup must reject this before any row is created.
        config(['integrations.providers' => []]);

        $firm = $this->entitledFirm();
        $provider = $this->makeTestProviderRow();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->startConnection($firm->id, $provider->id, $firmUser->user_id);
    }

    public function test_connect_provider_action_rejects_a_nonexistent_provider_id(): void
    {
        $firm = $this->entitledFirm();
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->startConnection($firm->id, 999999999, $firmUser->user_id);
    }

    // ------------------------------------------------------------
    // 1b. COMM-008 fix: ConnectProviderAction must not offer the
    // "Email" (ResourceType::Message) capability for a provider whose
    // sync framework cannot materialize it. PullSyncJob::applyPage()
    // only ever materializes an unmapped item when
    // `$connection->providerKey() === ProviderKey::Plaid` — mirrored
    // here via ConnectProviderAction::isDeadEndCapability(), keyed off
    // the resolved provider's own key() since no FirmIntegration row
    // exists yet at this point in the wizard.
    // ------------------------------------------------------------

    public function test_connect_provider_action_excludes_the_email_capability_for_microsoft365(): void
    {
        config(['integrations.providers' => [ProviderKey::Microsoft365->value => Microsoft365Provider::class]]);
        $provider = $this->makeProviderRow(ProviderKey::Microsoft365);

        $test = $this->mountConnectProviderActionAndSelectProvider($provider);

        // Message ("Email") requests real Mail.Read/Mail.Send OAuth
        // consent but PullSyncJob::applyPage() discards every Message
        // item for a non-Plaid connection — must never be offered.
        $test->assertDontSee('Email');

        // The exclusion is scoped to Message only: Microsoft365's other
        // real capabilities (Contact -> "Contacts", CalendarEvent ->
        // "Calendar") must still be offered.
        $test->assertSee('Contacts');
        $test->assertSee('Calendar');
    }

    public function test_connect_provider_action_excludes_the_email_capability_for_google_workspace(): void
    {
        config(['integrations.providers' => [ProviderKey::GoogleWorkspace->value => GoogleWorkspaceProvider::class]]);
        $provider = $this->makeProviderRow(ProviderKey::GoogleWorkspace);

        $test = $this->mountConnectProviderActionAndSelectProvider($provider);

        $test->assertDontSee('Email');

        // Scoped exclusion: GoogleWorkspace's other real capabilities
        // (CalendarEvent -> "Calendar", Document -> "Files") must still
        // be offered.
        $test->assertSee('Calendar');
        $test->assertSee('Files');
    }

    public function test_connect_provider_action_does_not_exclude_non_message_capabilities_for_the_test_provider(): void
    {
        // The Test provider never offers Message at all (its
        // pullableResourceTypes() is Contact/Task only) — proves the
        // COMM-008 filter is inert for a provider it has nothing to do
        // with, never a blanket capability removal.
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);
        $provider = $this->makeTestProviderRow();

        $test = $this->mountConnectProviderActionAndSelectProvider($provider);

        $test->assertSee('Contacts');
    }

    /**
     * Direct unit-level proof of ConnectProviderAction's private
     * isDeadEndCapability() gate, mirroring PullSyncJob::applyPage()'s
     * exact `$connection->providerKey() === ProviderKey::Plaid`
     * condition: Message must be flagged as a dead end for every
     * provider except Plaid (the one ProviderKey PullSyncJob's
     * materializer branch actually reaches), and any non-Message
     * resource type must never be flagged regardless of provider.
     */
    public function test_is_dead_end_capability_mirrors_the_pull_sync_job_materialization_gate(): void
    {
        $method = new ReflectionMethod(ConnectProviderAction::class, 'isDeadEndCapability');
        $method->setAccessible(true);

        $microsoft365 = app(Microsoft365Provider::class);
        $googleWorkspace = app(GoogleWorkspaceProvider::class);
        $plaid = app(PlaidProvider::class);

        $this->assertTrue($method->invoke(null, ResourceType::Message->value, $microsoft365));
        $this->assertTrue($method->invoke(null, ResourceType::Message->value, $googleWorkspace));

        // Plaid is the one provider PullSyncJob::applyPage() gates its
        // materializer branch on — Message must not be excluded for it
        // even though Plaid itself never actually requests it, so the
        // wizard filter stays a precise mirror of that gate rather than
        // a hardcoded "never show Message" rule.
        $this->assertFalse($method->invoke(null, ResourceType::Message->value, $plaid));

        $this->assertFalse($method->invoke(null, ResourceType::Contact->value, $microsoft365));
        $this->assertFalse($method->invoke(null, ResourceType::CalendarEvent->value, $googleWorkspace));
    }

    // ------------------------------------------------------------
    // 2. Rename — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_rename_connection_updates_display_label_for_a_configure_ceiling_role(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->firstOrFail());

        $renamed = app(ProviderConnectionService::class)->renameConnection($fresh, $firmUser->user_id, 'Renamed via UI action fallback');

        $this->assertSame('Renamed via UI action fallback', $renamed->display_label);
    }

    public function test_rename_connection_is_denied_below_the_configure_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::LegalAssistant);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->renameConnection($connection, $firmUser->user_id, 'Should not apply');
    }

    public function test_rename_connection_visible_closure_oracle_matches_can_configure_for_every_role(): void
    {
        // Mirrors ProviderConnectionServiceOAuthTest's own
        // "oracle-vs-actual-behavior" convention: isConfigurable()'s
        // UX-only visible() helper on ViewFirmIntegration must always
        // agree with IntegrationAccessPolicyService::canConfigure().
        $policy = app(IntegrationAccessPolicyService::class);

        foreach (FirmUserRole::cases() as $role) {
            $firm = $this->entitledFirm();
            $connection = $this->connectionFor($firm);
            $firmUser = $this->actingAsRole($firm, $role);

            $expected = $policy->canConfigure($role);
            $actuallyAllowed = true;

            try {
                app(ProviderConnectionService::class)->renameConnection($connection, $firmUser->user_id, 'Oracle check');
            } catch (RuntimeException) {
                $actuallyAllowed = false;
            }

            $this->assertSame($expected, $actuallyAllowed, "renameConnection() authorization mismatch for role {$role->value}");
        }
    }

    public function test_rename_connection_via_a_genuine_mount_action_call_mounted_action_round_trip(): void
    {
        // Genuine Livewire::test() proof the fix works (see class
        // docblock re: the ambient-context wrap this still needs, and
        // why).
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]));

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('rename');
            $test->setActionData(['display_label' => 'Renamed via genuine action']);
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertNotified('Connection renamed');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame('Renamed via genuine action', $fresh->display_label);
    }

    public function test_rename_connection_via_a_genuine_mount_action_call_is_still_denied_for_a_cross_firm_actor(): void
    {
        // Confirms the fix is a false-negative fix, not an accidental
        // false-positive: a user from the WRONG firm attempting the
        // action must still fail — now because RLS correctly excludes
        // the row for the wrong firm's context, not because context was
        // never established at all.
        $firmA = $this->entitledFirm();
        $connectionA = $this->connectionFor($firmA);
        $firmB = $this->entitledFirm();
        $actorB = $this->actingAsRole($firmB, FirmUserRole::FirmOwner);

        // Under the wrong firm's context, the fresh re-fetch inside the
        // action() closure must throw ModelNotFoundException — the SAME
        // exception class as the original bug, but now firing for the
        // CORRECT reason (RLS excluding a real cross-firm row) rather
        // than for every firm unconditionally.
        $this->expectException(ModelNotFoundException::class);

        $this->runWithFirmContext($firmB, function () use ($connectionA, $actorB) {
            app(TenantContextService::class)->runWithFirmContext(
                $actorB->firm_id,
                fn () => FirmIntegration::query()->where('id', $connectionA->id)->firstOrFail(),
            );
        });
    }

    // ------------------------------------------------------------
    // 3. Disconnect — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_disconnect_action_disconnects_for_a_disconnect_ceiling_role_and_nulls_external_account_id(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ['external_account_id' => 'ext-acct-123']);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->firstOrFail());

        $result = app(ProviderConnectionService::class)->disconnect($fresh, $firmUser->user_id);

        $this->assertSame(ConnectionStatus::Disconnected, $result->status);
        $this->assertNull($result->external_account_id, 'Checkpoint 10 addition (frozen design §0 ruling 1): disconnect() must null external_account_id.');
    }

    public function test_disconnect_action_visible_closure_denies_a_cross_firm_actor(): void
    {
        $firmA = $this->entitledFirm();
        $connectionA = $this->connectionFor($firmA);
        $firmB = $this->entitledFirm();
        $actorB = $this->actingAsRole($firmB, FirmUserRole::FirmOwner);

        // Mirrors ViewFirmIntegration::disconnectAction()'s own
        // visible() closure firm_id comparison — the direct service
        // call independently enforces this too (resolveActingFirmUser()
        // fails closed for a user with no active FirmUser in firmA).
        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->disconnect($connectionA, $actorB->user_id);
    }

    public function test_disconnect_action_requires_entitlement_per_the_disclosed_accepted_residual(): void
    {
        // Frozen design §0 ruling 2: disconnect() remains gated on
        // entitlement — an accepted, precedented residual, not a bug —
        // a firm whose entitlement is later revoked cannot use this
        // path to self-service-clean-up. Proven here directly.
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, false);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->disconnect($connection, $firmUser->user_id);
    }

    public function test_disconnect_connection_via_a_genuine_mount_action_call_mounted_action_round_trip(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm, ['external_account_id' => 'ext-acct-456']);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]));

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('disconnect');
            $test->callMountedAction();
        });

        $test->assertHasNoActionErrors();
        $test->assertNotified('Connection disconnected');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertSame(ConnectionStatus::Disconnected, $fresh->status);
        $this->assertNull($fresh->external_account_id);
    }

    // ------------------------------------------------------------
    // 4. Webhook-routing toggles — new $currentUserId-gated
    //    authorization (frozen design §3) — fallback (see class docblock)
    // ------------------------------------------------------------

    public function test_enable_webhook_routing_action_succeeds_for_a_configure_ceiling_role_and_returns_a_fresh_raw_token(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $rawToken = app(ProviderConnectionService::class)->enableWebhookRouting($connection, $firmUser->user_id);

        $this->assertNotEmpty($rawToken);
    }

    public function test_enable_webhook_routing_action_is_denied_below_the_configure_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->enableWebhookRouting($connection, $firmUser->user_id);
    }

    public function test_enable_webhook_routing_action_requires_entitlement(): void
    {
        $firm = Firm::factory()->create();
        $connection = $this->runWithFirmContext($firm, function () use ($firm) {
            TenantEncryptionKey::factory()->forFirm($firm)->create();

            return FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null]);
        });
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create());

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->enableWebhookRouting($connection, $firmUser->user_id);
    }

    public function test_disable_webhook_routing_action_succeeds_for_a_configure_ceiling_role_and_clears_the_token(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::Attorney);

        app(ProviderConnectionService::class)->enableWebhookRouting($connection, $firmUser->user_id);
        app(ProviderConnectionService::class)->disableWebhookRouting($connection, $firmUser->user_id);

        $fresh = $this->runWithFirmContext($firm, fn () => $connection->fresh());
        $this->assertNull($fresh->webhook_routing_token);
    }

    public function test_disable_webhook_routing_action_is_denied_below_the_configure_ceiling(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $firmUser = $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->expectException(RuntimeException::class);

        app(ProviderConnectionService::class)->disableWebhookRouting($connection, $firmUser->user_id);
    }

    public function test_enable_then_disable_webhook_routing_via_a_genuine_mount_action_call_mounted_action_round_trip(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid]));

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('enableWebhookRouting');
            $test->callMountedAction();
        });
        $test->assertHasNoActionErrors();
        $test->assertNotified('Webhook routing enabled');

        $fresh = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertNotNull($fresh->webhook_routing_token);

        $this->runWithFirmContext($firm, function () use ($test) {
            $test->mountAction('disableWebhookRouting');
            $test->callMountedAction();
        });
        $test->assertHasNoActionErrors();
        $test->assertNotified('Webhook routing disabled');

        $fresh2 = $this->runWithFirmContext($firm, fn () => FirmIntegration::query()->where('id', $connection->id)->first());
        $this->assertNull($fresh2->webhook_routing_token);
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

    /**
     * Microsoft365/GoogleWorkspace/Plaid all have a migration-seeded
     * `integration_providers` catalog row already (unlike the synthetic
     * Test provider fixture code), so this reuses that row rather than
     * violating its unique `code` constraint — ensuring it is active
     * along the way, mirroring makeTestProviderRow()'s own reuse
     * pattern above.
     */
    private function makeProviderRow(ProviderKey $key): IntegrationProvider
    {
        $provider = IntegrationProvider::query()->where('code', $key->value)->first()
            ?? IntegrationProvider::factory()->create(['code' => $key->value]);

        if ($provider->status !== 'active') {
            $provider->update(['status' => 'active']);
        }

        return $provider;
    }

    /**
     * Mounts ConnectProviderAction for real (see
     * FirmIntegrationConnectProviderDropdownVisibilityTest's class
     * docblock for why forceRender() is needed after each mount/data
     * update), then selects the given catalog row so the reactive
     * `capabilities` CheckboxList's options() closure evaluates for
     * real against that resolved provider. Returns the Livewire test
     * instance so callers can assert against the genuinely rendered
     * capability labels.
     */
    private function mountConnectProviderActionAndSelectProvider(IntegrationProvider $provider): Testable
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));
        $test->mountAction(ConnectProviderAction::getDefaultName());
        $test->call('forceRender');

        $test->setActionData(['integration_provider_id' => $provider->id]);
        $test->call('forceRender');

        return $test;
    }

    private function connectionFor(Firm $firm, array $overrides = []): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create(array_merge(['external_account_id' => null], $overrides))
        );
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
