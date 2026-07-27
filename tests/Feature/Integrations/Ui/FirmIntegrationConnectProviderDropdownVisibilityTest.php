<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ListFirmIntegrations;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmIntegrationConnectProviderDropdownVisibilityTest — Checkpoint 12
 * (frozen-design-post-security-review.md §2 F1, §3, §8). Proves the
 * confirmed §18 violation (12H verification item 5: "no
 * ProviderRegistry/isConfigured() reference anywhere in the file") is
 * genuinely closed: with the TestProvider environment flag OFF, the
 * migration-seeded `integration_providers` row (code='test', status=
 * 'active' — see
 * database/migrations/2026_09_01_010001_create_integration_providers_table.php)
 * must be excluded from ConnectProviderAction's dropdown options even
 * though the catalog row itself is Active; with the flag ON, it must be
 * included. Also proves the orphaned-catalog-row edge case: a row whose
 * `code` does not map to any ProviderKey case is gracefully filtered
 * out, never a fatal error (ProviderKey::tryFrom() returning null,
 * short-circuiting the ->filter() closure's registry->has() call).
 *
 * Checkpoint 2 update: ConnectProviderAction became a 2-step wizard
 * (Filament's Action::steps()). Earlier revisions of this test reflected
 * directly into the action's private, unmounted schema tree to read the
 * Select's options() closure result — that approach broke once the
 * Select moved into a nested Step, whose child components only become
 * fully bound (Schema `container` initialized) during a real Livewire
 * mount. Rewritten to mount the action for real, through the actual
 * owning page (`ListFirmIntegrations`, via `Livewire::test()` — the same
 * proven pattern `FirmIntegrationConnectionLifecycleActionsTest` already
 * uses) and assert against the genuinely rendered modal HTML
 * (`assertSee()`/`assertDontSee()`) rather than any internal component
 * tree. This is a stronger proof, not a weaker one: it exercises the
 * exact same code path a real browser render would, including Filament's
 * own hydration/hiding logic, instead of a hand-walked reflection of
 * private framework internals.
 *
 * Root-cause fix for the "Attempt to read property `mountedActions` on
 * null" failure this rewrite initially hit (test-harness-only issue,
 * confirmed via a full stack trace + direct dump of
 * FirmIntegrationResource::canAccess()): `Livewire::test()`'s initial
 * render hits its component through a synthetic, ad-hoc route
 * (`InitialRender::registerRouteBeforeExistingRoutes()`) that carries
 * NO middleware at all — none of `FirmPanelProvider`'s
 * `SetUpPanel`/`IdentifyTenant`/etc. ever runs, so
 * `Filament::getCurrentPanel()` stays null for that render.
 * `ListFirmIntegrations::mount()` (inherited from
 * `Filament\Resources\Pages\ListRecords`) still independently calls
 * `Filament\Resources\Pages\Concerns\CanAuthorizeResourceAccess::
 * authorizeResourceAccess()` — a genuine Livewire `mount*` hook that
 * always fires regardless of HTTP routing — which calls
 * `FirmIntegrationResource::canAccess()`. That in turn resolves the
 * acting user via `Filament::auth()->user()`
 * (`Filament\helpers.php::get_authorization_response()`), and
 * `FilamentManager::auth()` is `getCurrentOrDefaultPanel()->auth()`: with
 * no current panel set, it falls back to whichever panel is registered
 * `->default()` — `AdminPanelProvider`'s `admin` panel, whose
 * `authGuard('platform_admin')` is a COMPLETELY DIFFERENT guard from the
 * `web` guard `$this->actingAs()` authenticates against below. So
 * `Filament::auth()->user()` resolved to the guest/null platform-admin
 * user, `Gate::forUser(null)->inspect('viewAny', FirmIntegration::class)`
 * denied, and `authorizeResourceAccess()` called `abort_unless(false,
 * 403)` — aborting `ListFirmIntegrations`'s mount with a genuine 403
 * BEFORE Livewire's `dehydrate` hook ever fires. Livewire's own test
 * harness (`InitialRender::extractComponentAndBladeView()`) only
 * captures the component instance from that `dehydrate` event, so
 * `Testable::instance()` (`$this->lastState->getComponent()`) stayed
 * null — and `TestsActions::mountAction()`'s very first line,
 * `count($this->instance()->mountedActions)`, then threw exactly the
 * reported error reading a property off that null instance. Confirmed
 * directly: a temporary dump of `FirmIntegrationResource::canAccess()`
 * right before the `Livewire::test()` call returned `false` here despite
 * every underlying entitlement/role check (`isFirmEntitled()`,
 * `IntegrationAccessPolicyService::canView()`) independently returning
 * `true` — isolating the gap to `Filament::auth()`'s guard resolution,
 * not this test's fixture setup. `FirmIntegrationConnectionLifecycleActionsTest`
 * (the working sibling) never hits this because its own `setUp()`
 * explicitly calls `Filament::setCurrentPanel(Filament::getPanel('firm'))`
 * before every test — establishing the correct panel (and therefore the
 * correct `web` guard) up front, exactly as `SetUpPanel` middleware would
 * on a real request. This class adopts the identical, proven fix below;
 * no production code is implicated — a real browser/HTTP request always
 * goes through `SetUpPanel` and never observes this gap.
 *
 * SECOND, separate test-harness-only gap found and fixed after the above:
 * once `mountAction()` succeeded, `assertSee('Internal Test Provider
 * (non-production)')` still failed — `$test->html()` kept returning the
 * PAGE'S HTML FROM BEFORE `mountAction()` WAS EVER CALLED, with no modal
 * content at all. Root cause, confirmed via direct dumps of
 * `getMountedActions()`/`shouldOpenModal()`/`mountedActionHasSchema()`
 * (all correctly true/populated) plus reading
 * `Filament\Support\Livewire\Partials\PartialsComponentHook` and
 * `Livewire\Mechanisms\HandleComponents\HandleComponents::render()`
 * directly: Filament ships its own partial-render optimization for
 * action modals — when a request's ONLY effect is mounting/advancing an
 * action, `PartialsComponentHook::shouldSkipRender()` returns `true`
 * (`shouldRenderMountedActionsOnly()` matches, since
 * `getOriginallyMountedActionIndex()` — captured during `boot()`, i.e.
 * from the INCOMING pre-request snapshot — is still `null` the first
 * time an action mounts). `HandleComponents::render()` then honours
 * `store($component)->get('skipRender')` and returns without ever
 * calling `$context->addEffect('html', ...)` — the response carries a
 * separate `partials` effect (the freshly-rendered `action-modals` HTML)
 * instead of a top-level `html` effect. A real browser's Filament JS
 * (`filamentActionModals`) splices that `partials` effect into the DOM
 * itself, so this is invisible in production. But Livewire's OWN test
 * harness (`Livewire\Features\SupportTesting\SubsequentRender`) only
 * knows about the `html` effect: `$html = $effects['html'] ?? $this->
 * lastState->getHtml(...)` — with no `html` effect present, it silently
 * forwards the PREVIOUS (pre-mountAction) render's HTML, which is
 * exactly the stale content this test kept seeing. Filament's own
 * `InteractsWithActions::forceRender()` (public, calls
 * `PartialsComponentHook::forceRender($this)`, which
 * `shouldSkipRender()` checks first and unconditionally returns `false`
 * for) exists precisely to opt back into a full render — so
 * `mountConnectProviderAction()` below issues one extra `$test->
 * call('forceRender')` round trip immediately after `mountAction()`,
 * guaranteeing the next `$test->html()`/`assertSee()`/`assertDontSee()`
 * reflects the actual, freshly-rendered modal rather than a stale
 * snapshot. No production code is implicated: this is purely
 * `Livewire::test()`'s ignorance of Filament's `partials` effect, never
 * observed by a real browser.
 */
final class FirmIntegrationConnectProviderDropdownVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_the_test_provider_option_is_excluded_when_the_flag_is_off(): void
    {
        // The migration-seeded row already exists (status='active',
        // code='test') — deliberately does NOT re-create or override it,
        // proving the real seeded catalog row is what gets excluded.
        config(['integrations.providers' => [ProviderKey::Test->value => null]]);

        $this->mountConnectProviderAction()
            ->assertDontSee(
                'Internal Test Provider (non-production)',
            );
    }

    public function test_the_test_provider_option_is_included_when_the_flag_is_on(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        $this->mountConnectProviderAction()
            ->assertSee('Internal Test Provider (non-production)');
    }

    public function test_an_inactive_catalog_row_is_excluded_even_when_its_provider_key_is_registered(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        IntegrationProvider::query()->where('code', ProviderKey::Test->value)->update(['status' => 'inactive']);

        $this->mountConnectProviderAction()
            ->assertDontSee('Internal Test Provider (non-production)');
    }

    public function test_an_orphaned_catalog_row_whose_code_matches_no_provider_key_is_gracefully_excluded_not_a_fatal_error(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        IntegrationProvider::factory()->create([
            'code' => 'a-code-with-no-matching-provider-key-case',
            'display_name' => 'Orphaned Catalog Row',
            'status' => 'active',
        ]);

        // Must not throw (ProviderKey::tryFrom() returning null for the
        // orphaned row's code, rather than ProviderKey::from() which
        // would throw a ValueError) and must not appear in the options.
        // The genuine, resolvable TestProvider row must still be present
        // alongside the gracefully-skipped orphaned row — proves the
        // orphan doesn't abort the whole options() evaluation.
        $this->mountConnectProviderAction()
            ->assertDontSee('Orphaned Catalog Row')
            ->assertSee('Internal Test Provider (non-production)');
    }

    public function test_an_active_catalog_row_whose_code_matches_no_provider_key_never_appears_alongside_a_disabled_test_provider_either(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => null]]);

        IntegrationProvider::factory()->create([
            'code' => 'another-orphaned-code',
            'display_name' => 'Another Orphaned Row',
            'status' => 'active',
        ]);

        // With TestProvider disabled and only an orphaned catalog row
        // present, the dropdown must be genuinely empty — neither the
        // orphan nor TestProvider ever appears, and mounting must not
        // fatally error even with zero real options available.
        $this->mountConnectProviderAction()
            ->assertDontSee('Another Orphaned Row')
            ->assertDontSee('Internal Test Provider (non-production)');
    }

    /**
     * Mounts ConnectProviderAction for real, through the real owning
     * page, under a freshly created, entitled, authenticated firm —
     * returns the Livewire test instance so callers can chain
     * assertSee()/assertDontSee() against the genuinely
     * rendered modal HTML.
     */
    private function mountConnectProviderAction(): Testable
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role(FirmUserRole::FirmOwner)->create()
        );
        $this->actingAs($firmUser->user);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListFirmIntegrations::class));
        $test->mountAction(ConnectProviderAction::getDefaultName());

        // See class docblock's second root-cause writeup: mountAction()
        // alone leaves the component's dehydrated response carrying only
        // a `partials` effect (no top-level `html` effect), which
        // Livewire's SubsequentRender test harness doesn't understand —
        // it would silently keep serving the pre-mountAction HTML.
        // forceRender() (Filament\Actions\Concerns\InteractsWithActions,
        // public) forces the next render to be a genuine full render, so
        // the assertSee()/assertDontSee() calls below see the real,
        // freshly-mounted modal content.
        $test->call('forceRender');

        return $test;
    }
}
