<?php

use App\Http\Controllers\ClientPortal\PlaidExchangeController;
use App\Http\Controllers\Integrations\OAuthConnectionController;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishClientPortalTenantContext;
use App\Livewire\PaymentRequests\PublicPaymentPage;
use App\Services\CanonicalUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$hosts = app(CanonicalUrlService::class);

/*
|--------------------------------------------------------------------------
| Marketing host — firmsvault.com
|--------------------------------------------------------------------------
|
| Mission 1 (canonical reconstruction — Domain & Security Boundary
| Architecture). Explicitly domain-scoped (rather than left
| unconstrained, as it was before this mission) so it can never
| accidentally shadow a Filament panel's own domain-bound routes on
| app./client./admin.firmsvault.com — Laravel matches an unconstrained
| route against every Host header, and a plain Route::get('/', ...)
| with no domain() would otherwise compete with each panel's own root
| path on its own hostname.
*/
Route::domain($hosts->marketingHost())->group(function () use ($hosts) {
    Route::get('/', function () {
        return view('welcome');
    });

    /*
    |----------------------------------------------------------------
    | Legacy path compatibility.
    |----------------------------------------------------------------
    |
    | Before this mission, the Firm panel lived at firmsvault.com/firm,
    | the Client Portal at firmsvault.com/portal, and the Admin panel
    | at firmsvault.com/admin (all sharing one host, distinguished only
    | by path — see each PanelProvider's own docblock for the
    | pre-Mission-1 mounting). GET-only, safe temporary (302) redirects
    | to the new canonical hostnames so an existing bookmark/link keeps
    | working — never for POST/PUT/PATCH/DELETE (redirecting a
    | state-changing request cross-host can break CSRF and produce
    | dangerous semantics). The redirect target is built entirely from
    | CanonicalUrlService (server-controlled config) plus the request's
    | own path suffix and query string — never from a caller-supplied
    | redirect/return_to parameter — so this cannot be used as an open
    | redirect: the destination host is always one of FirmsVault's own
    | canonical hosts, never attacker-chosen.
    */
    Route::get('/firm/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->firmAppUrl().'/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');

    Route::get('/portal/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->clientPortalUrl().'/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');

    Route::get('/admin/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->adminUrl().'/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');

    // The OAuth callback is GET (Authorization Code flow convention —
    // confirmed via the canonical audit) and was previously reachable
    // at this same single host under /integrations/oauth/*, not under
    // any panel's own path — a safety net for any in-flight redirect
    // from before this mission's cutover moves the registered
    // redirect_uri to the new canonical Firm-app host. State/PKCE are
    // stored server-side (DB-keyed, not session-keyed — see
    // IntegrationOAuthStateService), so this redirect cannot break an
    // in-progress flow's own validation.
    Route::get('/integrations/oauth/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->firmAppUrl().'/integrations/oauth/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');
});

/*
|--------------------------------------------------------------------------
| Public Payment Request page (Payment Link / QR Routing phase)
|--------------------------------------------------------------------------
|
| Deliberately NOT wrapped in a Route::domain() group. This is a
| signed, unauthenticated link shared with a Firm's own clients/payers
| (not a Firm-internal or platform-internal surface), and the actual
| canonical host it should live under is not resolved by this mission
| — leaving it host-unconstrained means it keeps resolving under
| whichever trusted host the signed link was generated against
| (typically wherever PaymentRequestService::signedUrl() is called
| from today), with zero behavior change from before this mission.
| TrustHosts (see bootstrap/app.php) still requires the request arrive
| on one of the six canonical hostnames; only the domain-routing layer
| leaves this one unconstrained among those six.
*/
Route::get('/pay/{uuid}', PublicPaymentPage::class)
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('public.payment-requests.show');

/*
|--------------------------------------------------------------------------
| Integration OAuth Routes — Firm app host
|--------------------------------------------------------------------------
|
| Moved from a top-level, host-unscoped route group to
| app.firmsvault.com (section 17: "Firm integrations should normally
| initiate and return through app.firmsvault.com"). Guard, middleware,
| and controller logic are byte-for-byte unchanged from before this
| mission — only the domain binding is new. State/PKCE storage is
| server-side (Postgres, keyed by a hashed token — see
| IntegrationOAuthStateService), not session-based, so this move does
| not affect an in-progress OAuth handshake's own validation.
|
| EXTERNAL_CONFIGURATION_REQUIRED: Google Cloud Console's OAuth client
| and Azure Entra's app registration must each have
| "https://app.firmsvault.com/integrations/oauth/callback" added to
| their allow-listed redirect URIs before real cutover — see this
| mission's final report.
|
| Mission 1B (Extreme Security Hardening), section 14: throttle:20,1
| added — OAuth initiation/callback had no rate limit at all before
| this mission (a real gap section 14 explicitly names). 20/minute is
| generous for a human-driven "connect this integration" flow while
| still bounding automated abuse of the state/PKCE generation and
| callback-validation paths.
*/
Route::domain($hosts->firmAppHost())->middleware(['auth', 'throttle:20,1'])->prefix('integrations/oauth')->name('integrations.oauth.')->group(function () {
    Route::get('{firmIntegration}/initiate', [OAuthConnectionController::class, 'initiate'])->name('initiate');
    Route::get('callback', [OAuthConnectionController::class, 'callback'])->name('callback');
});

/*
|--------------------------------------------------------------------------
| Client Portal Plaid Link exchange — Client Portal host
|--------------------------------------------------------------------------
|
| Moved from path `portal/plaid/exchange` (tied to the panel's old
| path('portal') mount) to `plaid/exchange` on client.firmsvault.com,
| alongside the panel's own new path('') root — see
| ClientPortalPanelProvider's own docblock. Guard/middleware/controller
| logic unchanged, including the Checkpoint 4 ApplyTenantDatabaseContext
| middleware this route has always carried.
|
| Mission 1B (Extreme Security Hardening), section 14: throttle:10,1
| added — this endpoint exchanges a Plaid Link public_token for a real
| access token and had no rate limit at all before this mission.
*/
Route::domain($hosts->clientPortalHost())
    ->middleware(['auth:client', EstablishClientPortalTenantContext::class, ApplyTenantDatabaseContext::class, 'throttle:10,1'])
    ->post('plaid/exchange', [PlaidExchangeController::class, 'exchange'])
    ->name('client-portal.plaid.exchange');

/*
|--------------------------------------------------------------------------
| MyAttorney host — myattorney.firmsvault.com (RESERVED)
|--------------------------------------------------------------------------
|
| Hostname/routing/session boundary only, in this mission. A safe,
| honestly-labeled placeholder — never the FirmsVault marketing welcome
| view (that would misrepresent MyAttorney's own future identity) and
| never any directory/search/AI-intake/marketplace functionality
| (explicitly out of scope until Mission 2).
*/
Route::domain($hosts->myAttorneyHost())->group(function () {
    Route::get('/{any?}', function () {
        return response('MyAttorney — coming soon.', 200);
    })->where('any', '.*');
});

/*
|--------------------------------------------------------------------------
| API host — api.firmsvault.com (RESERVED)
|--------------------------------------------------------------------------
|
| Deliberately no routes registered under this host. Any request here
| falls through to Laravel's normal no-route-matched 404 — the
| hostname is trusted (recognized by TrustHosts/CanonicalUrlService)
| but answers nothing, the correct shape for "reserved."
*/
