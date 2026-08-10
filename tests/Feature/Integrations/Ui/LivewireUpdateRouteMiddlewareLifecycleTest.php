<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\PlatformRoleCode;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PlatformIntegrationOverviewPage;
use App\Filament\Widgets\PlatformEnvironmentBadgeWidget;
use App\Http\Middleware\EstablishFirmTenantContextForLivewireUpdate;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\CanonicalUrlService;
use App\Services\EntitlementService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LivewireUpdateRouteMiddlewareLifecycleTest — Checkpoint 13 P1
 * (p1-livewire-fix-frozen-design.md §6 items B, D). The inverse of
 * PersistentMiddlewareTenantContextLifetimeTest: that test proved the
 * CP10 approach tore context down BEFORE hydration; these prove the P1
 * middleware (EstablishFirmTenantContextForLivewireUpdate) establishes
 * context precisely WHEN hydration runs and clears it strictly after —
 * driven directly through the middleware's real `handle()` wrapping a
 * `$next` that stands in for `handleUpdate()`/`ModelSynth::hydrate()`.
 *
 * Item B: `app.current_firm_id` is active precisely when hydration runs
 *         (the FORCE-RLS `firstOrFail()` re-fetch succeeds inside `$next`)
 *         and is cleared/non-leaking after; cross-firm fails closed.
 * Item D: cross-panel non-regression — an Admin/SuperAdmin (`admin` path)
 *         update establishes NO firm context (including the dual-login
 *         edge case), and real admin-panel Livewire components never
 *         receive firm context after the update-route change.
 */
final class LivewireUpdateRouteMiddlewareLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function middleware(): EstablishFirmTenantContextForLivewireUpdate
    {
        return app(EstablishFirmTenantContextForLivewireUpdate::class);
    }

    /**
     * A realistic `POST /livewire/update` request arriving on the given
     * canonical host, carrying a component snapshot with the given
     * render `memo.path`. Mission 1 (canonical reconstruction) moved the
     * middleware's gate off `memo.path` (see
     * EstablishFirmTenantContextForLivewireUpdate's own docblock) onto
     * the request's own Host header — exactly what a real browser's
     * same-origin Livewire fetch() always carries — so `$host` is now
     * the load-bearing gate input; `$memoPath` is retained only as
     * realistic payload shape, not as a signal the middleware reads.
     */
    private function updateRequest(string $host, string $memoPath, ?object $user): Request
    {
        $request = Request::create('http://'.$host.'/livewire/update', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session']->driver());
        $request->merge([
            'components' => [
                ['snapshot' => json_encode(['memo' => ['path' => $memoPath]])],
            ],
        ]);

        return $request;
    }

    private function runMiddleware(Request $request, Closure $next): mixed
    {
        return $this->middleware()->handle($request, $next);
    }

    private function dbFirmSetting(): ?string
    {
        return DB::selectOne("select current_setting('app.current_firm_id', true) as v")->v;
    }

    private function establishedFirm(): array
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create(['external_account_id' => null]));
        $firmUser = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create());

        return [$firm, $connection, $firmUser];
    }

    // ============================================================
    // Item B — lifecycle ordering: context active exactly during $next
    // (where hydration runs), cleared strictly after.
    // ============================================================

    public function test_firm_context_is_active_when_hydration_runs_and_cleared_after(): void
    {
        [$firm, $connection, $firmUser] = $this->establishedFirm();

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'clean slate');
        $this->assertNoDatabaseTenantContext('Expected no context before the middleware runs.');

        $request = $this->updateRequest(app(CanonicalUrlService::class)->firmAppHost(), 'firm-integrations/'.$connection->uuid, $firmUser->user);

        $observed = [];
        $response = $this->runMiddleware($request, function () use (&$observed, $connection) {
            // This closure stands in for handleUpdate()/ModelSynth::hydrate().
            $observed['hasContext'] = app(TenantContextService::class)->hasFirmContext();
            $observed['dbSetting'] = $this->dbFirmSetting();
            // The EXACT operation ModelSynth::hydrate() performs on the
            // FORCE-RLS #[Locked] $record — a context-less version of this
            // threw ModelNotFoundException before P1.
            $observed['hydrated'] = FirmIntegration::query()->where('id', $connection->id)->firstOrFail();

            return new Response('ok');
        });

        $this->assertInstanceOf(Response::class, $response);

        // DURING $next (i.e. exactly when hydration runs):
        $this->assertTrue($observed['hasContext'], 'PHP-memory firm context must be active while hydration runs.');
        $this->assertSame((string) $firm->id, $observed['dbSetting'], 'app.current_firm_id must be the firm during hydration.');
        $this->assertSame($connection->id, $observed['hydrated']->id, 'The FORCE-RLS hydration re-fetch must SUCCEED under P1-established context — the exact previously-broken step.');

        // AFTER $next (strictly cleaned up by the finally):
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'PHP-memory firm context must be cleared after the response is built — no leak.');
        $this->assertNoDatabaseTenantContext('app.current_firm_id must be cleared after the response — no leak into later requests.');
    }

    public function test_a_cross_firm_actor_fails_closed_and_leaves_no_context_behind(): void
    {
        [, $connectionA] = $this->establishedFirm();

        // A different firm's owner drives an update carrying firm A's path.
        $firmB = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firmB, 'integration', EntitlementSource::AdminOverride, true);
        $ownerB = $this->runWithFirmContext($firmB, fn () => FirmUser::factory()->forFirm($firmB)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create());

        $request = $this->updateRequest(app(CanonicalUrlService::class)->firmAppHost(), 'firm-integrations/'.$connectionA->uuid, $ownerB->user);

        $threw = false;
        try {
            $this->runMiddleware($request, function () use ($connectionA) {
                // Firm B's context is active; firm A's connection is excluded
                // by FORCE RLS -> ModelNotFoundException (fail closed) — the
                // SAME exception as the original bug, now for the CORRECT
                // reason (wrong-firm exclusion), not a universal failure.
                return FirmIntegration::query()->where('id', $connectionA->id)->firstOrFail();
            });
        } catch (ModelNotFoundException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A cross-firm actor must fail closed — firm A\'s connection must be invisible under firm B\'s context.');
        $this->assertNoDatabaseTenantContext('Even on the fail-closed path, the finally must clear context — no leak.');
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
    }

    // ============================================================
    // Item D — cross-panel non-regression (middleware-level)
    // ============================================================

    public function test_an_admin_panel_update_establishes_no_firm_context(): void
    {
        [, , $firmUser] = $this->establishedFirm();

        // A component whose render path is under the admin panel — even if
        // the request user happens to have a firm membership, the path gate
        // must make the middleware no-op.
        $request = $this->updateRequest(app(CanonicalUrlService::class)->adminHost(), 'platform-integration-overview', $firmUser->user);

        $observed = [];
        $this->runMiddleware($request, function () use (&$observed) {
            $observed['hasContext'] = app(TenantContextService::class)->hasFirmContext();
            $observed['dbSetting'] = $this->dbFirmSetting();

            return new Response('ok');
        });

        $this->assertFalse($observed['hasContext'], 'An admin-path update must NOT establish firm context.');
        $this->assertTrue($observed['dbSetting'] === null || $observed['dbSetting'] === '', 'app.current_firm_id must stay empty for an admin-path update.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_the_dual_login_edge_case_admin_path_still_establishes_no_firm_context(): void
    {
        // The classic dual-login edge: one session authenticated on BOTH the
        // web (firm) and platform_admin guards. The request user resolves to
        // a firm member (so guard-only scoping would wrongly establish firm
        // context), but the component's render path is the ADMIN panel — the
        // path gate must win.
        [, , $firmUser] = $this->establishedFirm();

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        // Authenticate BOTH guards simultaneously.
        $this->actingAs($firmUser->user, 'web');
        $this->actingAs($admin, 'platform_admin');

        $request = $this->updateRequest(app(CanonicalUrlService::class)->adminHost(), 'platform-integration-overview', $firmUser->user);

        $observed = [];
        $this->runMiddleware($request, function () use (&$observed) {
            $observed['hasContext'] = app(TenantContextService::class)->hasFirmContext();
            $observed['dbSetting'] = $this->dbFirmSetting();

            return new Response('ok');
        });

        $this->assertFalse($observed['hasContext'], 'The path gate must win over guard resolution — an admin-path update must not establish firm context even with a firm user resolvable.');
        $this->assertTrue($observed['dbSetting'] === null || $observed['dbSetting'] === '');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_a_firm_path_update_with_no_active_firm_membership_establishes_no_context(): void
    {
        // A firm-path update by a user with NO active FirmUser -> the
        // middleware establishes nothing and lets the request proceed
        // (a genuinely unauthorized action still fails closed downstream
        // under FORCE RLS with no firm context).
        $stranger = User::factory()->create();

        $request = $this->updateRequest(app(CanonicalUrlService::class)->firmAppHost(), 'firm-integrations/whatever', $stranger);

        $observed = [];
        $this->runMiddleware($request, function () use (&$observed) {
            $observed['hasContext'] = app(TenantContextService::class)->hasFirmContext();
            $observed['dbSetting'] = $this->dbFirmSetting();

            return new Response('ok');
        });

        $this->assertFalse($observed['hasContext'], 'A user with no active firm membership must not get firm context.');
        $this->assertTrue($observed['dbSetting'] === null || $observed['dbSetting'] === '');
    }

    public function test_a_guest_firm_path_update_establishes_no_context(): void
    {
        $request = $this->updateRequest(app(CanonicalUrlService::class)->firmAppHost(), 'firm-integrations/whatever', null);

        $observed = [];
        $this->runMiddleware($request, function () use (&$observed) {
            $observed['hasContext'] = app(TenantContextService::class)->hasFirmContext();

            return new Response('ok');
        });

        $this->assertFalse($observed['hasContext'], 'An unauthenticated update must establish no firm context.');
    }

    // ============================================================
    // Item D — real admin-panel Livewire components never get firm context
    // ============================================================

    public function test_real_admin_livewire_components_never_receive_firm_context(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin, 'platform_admin');

        $this->assertNoDatabaseTenantContext('clean slate');

        // Admin Dashboard — App\Filament\Pages\Dashboard, the Executive
        // Dashboard that now REPLACES the stock Filament\Pages\Dashboard
        // in AdminPanelProvider (Phase 1 FirmsVault Admin Control
        // Center, final scope item). This is the real, currently
        // registered dashboard component, not the stock vendor class.
        Livewire::test(Dashboard::class)->assertOk();
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'The admin Dashboard must never establish firm context.');
        $this->assertNoDatabaseTenantContext();

        // PlatformEnvironmentBadgeWidget (a real admin panel Livewire
        // widget, mounted on the Executive Dashboard above — the stock
        // AccountWidget this test used to exercise here is no longer
        // registered on this panel at all, see AdminPanelProvider's own
        // docblock).
        Livewire::test(PlatformEnvironmentBadgeWidget::class)->assertOk();
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'PlatformEnvironmentBadgeWidget must never establish firm context.');
        $this->assertNoDatabaseTenantContext();

        // An admin Resource-style page WITH a table (the integration
        // oversight overview), driven with a real interaction (a table
        // search) — firm context must stay absent throughout.
        $overview = Livewire::test(PlatformIntegrationOverviewPage::class);
        $overview->assertOk();
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();

        $overview->set('tableSearch', 'anything')->assertOk();
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'An admin table interaction must never establish firm context.');
        $this->assertNoDatabaseTenantContext('app.current_firm_id must stay empty through an admin table update.');
    }
}
