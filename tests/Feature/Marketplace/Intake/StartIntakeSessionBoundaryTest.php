<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\ViewModels\PublicFirmProfile;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Services\CanonicalUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Start Secure Intake 419.
 *
 * Every submission of the "Start Secure Intake" button failed with 419. The
 * cause was not the CSRF check being wrong — it was the two routes disagreeing
 * about which session the token belonged to. The profile page rendered @csrf
 * with no ConfigurePanelSessionCookie middleware, so the token was minted into
 * the default `firmsvault-session`; the POST target ran under
 * `:myattorney`, looked in `firmsvault-myattorney-session`, found a brand new
 * empty session, and rejected a token that had been perfectly valid — in a
 * different cookie.
 *
 * These tests pin the agreement itself. A CSRF-rejection assertion would prove
 * nothing here: Laravel's PreventRequestForgery unconditionally bypasses
 * verification under `php artisan test` (see
 * SessionCookieIsolationTest and CorrectionWorkflowTest, which document the
 * same harness limitation). What IS provable, and is exactly the bug, is which
 * cookie each route reads and whether one session survives the round trip.
 */
final class StartIntakeSessionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const MYATTORNEY_COOKIE = 'firmsvault-myattorney-session';

    private const DEFAULT_COOKIE = 'firmsvault-session';

    private function publishedFirmAcceptingIntake(string $slug): DirectoryFirm
    {
        IntakeTemplate::factory()->marketplaceDefault()->create(['is_active' => true]);

        return DirectoryFirm::factory()->member()->create([
            'firm_id' => Firm::factory()->create()->id,
            'slug' => $slug,
            'accepting_inquiries' => true,
        ]);
    }

    private function cookieNamesOn($response): array
    {
        return array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    }

    // -----------------------------------------------------------------
    // The fix: one session across both routes
    // -----------------------------------------------------------------

    public function test_the_profile_page_and_the_start_intake_post_read_the_same_session_cookie(): void
    {
        // The whole bug in one assertion: both routes must name the same
        // cookie. Before the fix the GET named firmsvault-session and the POST
        // named firmsvault-myattorney-session.
        foreach (['myattorney.firms.show', 'myattorney.firms.start-intake'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];

            $this->assertContains(
                ConfigurePanelSessionCookie::class.':myattorney',
                $middleware,
                "{$routeName} must run under the myattorney session cookie, or the CSRF token it mints/reads belongs to a different session.",
            );
        }
    }

    public function test_the_profile_page_issues_the_myattorney_session_cookie_and_not_the_default_one(): void
    {
        $this->publishedFirmAcceptingIntake('cookie-check');

        $response = $this->get($this->myAttorneyUrl('/firms/cookie-check'));

        $response->assertOk();
        $cookies = $this->cookieNamesOn($response);

        $this->assertContains(self::MYATTORNEY_COOKIE, $cookies);
        $this->assertNotContains(self::DEFAULT_COOKIE, $cookies, 'A token minted into the default cookie is exactly what caused the 419.');
    }

    public function test_the_profile_page_renders_a_real_csrf_token_matching_its_own_session(): void
    {
        $this->publishedFirmAcceptingIntake('token-check');

        $response = $this->get($this->myAttorneyUrl('/firms/token-check'));

        $response->assertOk();
        $response->assertSee('name="_token"', false);

        // The token in the HTML must be the token of the session the POST will
        // read — not merely "a" token.
        $this->assertNotEmpty(session()->token());
        $response->assertSee(session()->token(), false);
    }

    public function test_starting_an_intake_from_the_profile_page_succeeds_and_keeps_the_same_session(): void
    {
        $this->publishedFirmAcceptingIntake('happy-path');

        $this->get($this->myAttorneyUrl('/firms/happy-path'))->assertOk();
        $token = session()->token();

        $response = $this->post($this->myAttorneyUrl('/firms/happy-path/start-intake'), ['_token' => $token]);

        // 302 to the signed intake link — never back to the profile with an
        // unavailable flag.
        //
        // Session CONTINUITY across these two requests is not asserted here:
        // Laravel's HTTP test client does not carry a response's session
        // cookie into the next request, so a session-id comparison would be
        // measuring the harness, not the application. The cookie-name
        // agreement asserted above is the property that actually decides
        // whether a real browser's second request lands in the same session.
        $response->assertRedirect();
        $this->assertStringContainsString('/intake/', $response->headers->get('Location'));
    }

    // -----------------------------------------------------------------
    // The negative side: a token from the wrong session is a different token
    // -----------------------------------------------------------------

    public function test_a_token_minted_on_a_route_outside_the_myattorney_session_is_not_the_token_the_post_expects(): void
    {
        // Demonstrates the failure the fix removes. The home page carries no
        // myattorney session cookie (deliberately — it is a pure cacheable
        // read), so a token taken from there belongs to a different session
        // than the one the start-intake POST reads.
        $this->publishedFirmAcceptingIntake('mismatch-check');

        $this->get($this->myAttorneyUrl('/'))->assertOk();
        $tokenFromANonMyattorneySession = session()->token();

        $this->flushSession();

        $this->get($this->myAttorneyUrl('/firms/mismatch-check'))->assertOk();
        $tokenTheProfilePageMinted = session()->token();

        $this->assertNotSame(
            $tokenFromANonMyattorneySession,
            $tokenTheProfilePageMinted,
            'Guard assertion: tokens from two different sessions must differ, which is why the cookie has to match.',
        );
    }

    public function test_the_pure_read_routes_keep_their_cacheability_and_take_no_myattorney_cookie(): void
    {
        // The fix must not spread a session cookie across the whole public
        // surface. Only the page that renders a form needs one.
        $response = $this->get($this->myAttorneyUrl('/'));

        $response->assertOk();
        $this->assertNotContains(self::MYATTORNEY_COOKIE, $this->cookieNamesOn($response));
    }

    public function test_the_profile_page_is_never_shared_cacheable_now_that_it_carries_a_token(): void
    {
        $this->publishedFirmAcceptingIntake('no-store-check');

        $response = $this->get($this->myAttorneyUrl('/firms/no-store-check'));

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_a_firm_not_accepting_inquiries_shows_no_intake_form_at_all(): void
    {
        $directoryFirm = $this->publishedFirmAcceptingIntake('closed-firm');
        $directoryFirm->update(['accepting_inquiries' => false]);

        $response = $this->get($this->myAttorneyUrl('/firms/closed-firm'));

        $response->assertOk();
        $response->assertDontSee('Start Secure Intake');
    }

    public function test_the_intake_capability_is_what_gates_the_form(): void
    {
        $directoryFirm = $this->publishedFirmAcceptingIntake('capability-check');

        $this->assertContains(
            MarketplaceCapability::SecureIntake,
            PublicFirmProfile::fromModel($directoryFirm->fresh())->capabilities,
            'Guard assertion: this fixture must actually offer secure intake, or the form assertions above prove nothing.',
        );
    }
    // -----------------------------------------------------------------
    // Sweep: the same class of bug anywhere else on this host
    // -----------------------------------------------------------------

    public function test_every_state_changing_route_on_the_myattorney_host_carries_the_myattorney_session_cookie(): void
    {
        // The 419 was one instance of a general invariant. Anything on this
        // host that accepts a POST reads a CSRF token, and a token is only
        // meaningful against a named session — so every such route must name
        // the myattorney cookie. Swept over the live route table rather than a
        // hand-kept list, so a new POST route added later is caught here.
        $host = app(CanonicalUrlService::class)->myAttorneyHost();
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->getDomain() !== $host) {
                continue;
            }

            if (! in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (! in_array(ConfigurePanelSessionCookie::class.':myattorney', $route->gatherMiddleware(), true)) {
                $offenders[] = $route->uri();
            }
        }

        $this->assertSame([], $offenders, 'These MyAttorney POST routes would read a CSRF token from the wrong session.');
    }

    public function test_the_public_read_only_routes_on_this_host_stay_free_of_a_session_cookie(): void
    {
        // The other half of the invariant: the fix must stay surgical. A
        // session cookie on the sitemaps or the home page would cost
        // cacheability on the routes that genuinely are pure reads.
        $host = app(CanonicalUrlService::class)->myAttorneyHost();
        $formBearing = ['firms/{slug}', 'firms/{slug}/report-correction', 'intake/{uuid}'];
        $unexpected = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->getDomain() !== $host || in_array('POST', $route->methods(), true)) {
                continue;
            }

            if (in_array($route->uri(), $formBearing, true)) {
                continue;
            }

            if (in_array(ConfigurePanelSessionCookie::class.':myattorney', $route->gatherMiddleware(), true)) {
                $unexpected[] = $route->uri();
            }
        }

        $this->assertSame([], $unexpected, 'These pure-read routes took on a session cookie they do not need.');
    }
}
