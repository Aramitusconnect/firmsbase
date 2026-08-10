<?php

use App\Services\CanonicalUrlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$hosts = app(CanonicalUrlService::class);

/*
|--------------------------------------------------------------------------
| Marketing host — firmsvault.com
|--------------------------------------------------------------------------
|
| Mission 1 (Domain & Security Boundary Architecture). Explicitly
| domain-scoped (rather than left unconstrained, as it was before this
| mission) so it can never accidentally shadow a Filament panel's own
| domain-bound routes on app./client./admin.firmsvault.com — Laravel
| matches an unconstrained route against every Host header, and a plain
| Route::get('/', ...) with no domain() would otherwise compete with
| each panel's own root path on its own hostname.
*/
Route::domain($hosts->marketingHost())->group(function () use ($hosts) {
    Route::get('/', function () {
        return view('welcome');
    });

    /*
    |----------------------------------------------------------------
    | Legacy path compatibility — section 14/45.
    |----------------------------------------------------------------
    |
    | Before this mission, the Firm and SuperAdmin panels lived at
    | firmsvault.com/firm and firmsvault.com/admin. GET-only, safe
    | temporary (302) redirects to the new canonical hostnames so an
    | existing bookmark/link keeps working — never for
    | POST/PUT/PATCH/DELETE (section 14 is explicit: cross-host
    | redirecting a state-changing request can break CSRF and produce
    | dangerous semantics). No mutation route existed at these paths
    | before this mission anyway (both panels registered no
    | Resources/Pages beyond the built-in login/dashboard), so nothing
    | here silently drops a working POST endpoint — a POST to either
    | legacy path correctly falls through to Laravel's normal
    | "method not allowed"/"not found" handling instead of being
    | replayed cross-host.
    |
    | The redirect target is built entirely from CanonicalUrlService
    | (server-controlled config) plus the request's OWN path suffix and
    | query string — never from a caller-supplied redirect/return_to
    | parameter — so this cannot be used as an open redirect (section
    | 16): the destination host is always one of FirmsVault's own
    | canonical hosts, never attacker-chosen.
    */
    Route::get('/firm/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->firmAppUrl().'/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');

    Route::get('/admin/{path?}', function (Request $request, string $path = '') use ($hosts) {
        return redirect($hosts->adminUrl().'/'.ltrim($path, '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 302);
    })->where('path', '.*');
});

/*
|--------------------------------------------------------------------------
| MyAttorney host — myattorney.firmsvault.com (RESERVED — section 3/57)
|--------------------------------------------------------------------------
|
| Hostname/routing/session boundary only, in this mission. A safe,
| honestly-labeled placeholder — never the FirmsVault marketing welcome
| view (that would misrepresent MyAttorney's own future identity, and
| section 32 requires independent site identity, not a shared one) and
| never any directory/search/AI-intake/marketplace functionality
| (explicitly out of scope until Mission 2 — section 57).
*/
Route::domain($hosts->myAttorneyHost())->group(function () {
    Route::get('/{any?}', function () {
        return response('MyAttorney — coming soon.', 200);
    })->where('any', '.*');
});

/*
|--------------------------------------------------------------------------
| API host — api.firmsvault.com (RESERVED — section 3)
|--------------------------------------------------------------------------
|
| Deliberately no routes registered under this host at all. Section 3:
| "Do not expose a new public API in this mission." Any request here
| falls through to Laravel's normal no-route-matched 404 — the
| hostname is trusted (recognized by TrustHosts/CanonicalUrlService)
| but answers nothing, which is the correct shape for "reserved."
*/
