<?php

use App\Http\Controllers\ClientPortal\DocumentDownloadController as ClientPortalDocumentDownloadController;
use App\Http\Controllers\ClientPortal\PlaidExchangeController;
use App\Http\Controllers\Firm\DocumentDownloadController;
use App\Http\Controllers\Integrations\OAuthConnectionController;
use App\Http\Controllers\MyAttorney\AttorneyProfileController;
use App\Http\Controllers\MyAttorney\CorrectionRequestController;
use App\Http\Controllers\MyAttorney\FirmProfileController;
use App\Http\Controllers\MyAttorney\HomeController as MyAttorneyHomeController;
use App\Http\Controllers\MyAttorney\MarketplaceIntakeDocumentController;
use App\Http\Controllers\MyAttorney\MarketplaceIntakeStartController;
use App\Http\Controllers\MyAttorney\SitemapController;
use App\Http\Controllers\PublicSignup\RegistrationRequestController;
use App\Http\Controllers\Seo\RobotsTxtController;
use App\Http\Controllers\SignatureRecipientController;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EstablishClientPortalTenantContext;
use App\Livewire\ClientPortal\AcceptInvitationPage;
use App\Livewire\Marketplace\PublicIntakePage;
use App\Livewire\PaymentRequests\PublicPaymentPage;
use App\Services\CanonicalUrlService;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$hosts = app(CanonicalUrlService::class);

/*
|--------------------------------------------------------------------------
| robots.txt — every canonical host
|--------------------------------------------------------------------------
|
| Mission 2 (MyAttorney Marketplace Core), checkpoint 12. Deliberately
| NOT wrapped in a Route::domain() group — RobotsTxtController itself
| branches on the request's Host header, so this one registration
| answers correctly for marketing/Firm/Client/Admin/API (none of which
| have a catch-all route of their own to compete with it). The
| MyAttorney host is the one exception: its own `/{any?}` catch-all
| below is domain-scoped, and a domain-scoped route wins over this
| domain-less one for the same request regardless of file order — so
| MyAttorney additionally registers its own explicit `/robots.txt`
| route (same controller) ahead of that catch-all, the same way its
| sitemap routes already have to. Replaces the previously-static
| public/robots.txt file, which could never vary by host.
*/
Route::get('/robots.txt', RobotsTxtController::class);

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
| Public Signature Recipient page (Non-payment completion program —
| e-signature signer-facing flow)
|--------------------------------------------------------------------------
|
| Deliberately NOT wrapped in a Route::domain() group, mirroring
| public.payment-requests.show immediately above — this is a link
| shared with a Firm's own external signer (not a Firm-internal or
| platform-internal surface), and the canonical host it should live
| under is not resolved by this workstream. TrustHosts (see
| bootstrap/app.php) still requires the request arrive on one of the
| six canonical hostnames; only the domain-routing layer leaves this
| one unconstrained among those six.
|
| Deliberately NOT `signed` middleware, unlike public.payment-requests.show:
| the access secret here is the signer's OWN hashed
| signature_request_recipients.access_token_hash (a per-recipient,
| CSPRNG-derived, hash_equals()-compared bearer token minted by
| SignatureRequestWorkflowService::send()), not a Laravel URL signature
| — SignatureRecipientController itself is the authorization boundary,
| never this route's middleware alone. `{uuid}` is the recipient's own
| public uuid (HasPublicUuid); the raw bearer token travels separately,
| as a `?token=` query parameter, and is never accepted as a route
| segment (so it is never captured by generic uuid-shaped route-pattern
| assumptions elsewhere in the app). throttle:30,1 mirrors
| public.payment-requests.show's own generous-but-bounded allowance for
| a genuinely public, unauthenticated surface.
*/
Route::middleware(['throttle:30,1'])->group(function () {
    Route::get('/sign/{uuid}', [SignatureRecipientController::class, 'show'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('public.signature-recipients.show');

    Route::get('/sign/{uuid}/document', [SignatureRecipientController::class, 'document'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('public.signature-recipients.document');

    Route::post('/sign/{uuid}/consent', [SignatureRecipientController::class, 'consent'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('public.signature-recipients.consent');

    Route::post('/sign/{uuid}/sign', [SignatureRecipientController::class, 'sign'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('public.signature-recipients.sign');

    Route::post('/sign/{uuid}/decline', [SignatureRecipientController::class, 'decline'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->name('public.signature-recipients.decline');
});

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
| Document download — Firm app host
|--------------------------------------------------------------------------
|
| Mission 3 (Document Center Completion), section 3.5. Session-
| authenticated (the same 'auth' web guard + firmAppHost domain the
| OAuth group above already uses for this panel — deliberately NOT a
| 'signed' public URL, matching Document's own "never a public URL"
| project rule). `{document}` is intentionally a plain string (the
| document's public uuid), not an implicit Eloquent-bound parameter —
| see DocumentDownloadController's own docblock for why: `documents`
| carries permanent FORCE ROW LEVEL SECURITY, and this app's global
| middleware-priority order (bootstrap/app.php, frozen for this
| mission) runs route-model-binding substitution ahead of any tenant-
| context middleware, so an implicit binding would always resolve the
| row before any context exists. The controller resolves firm context
| from the authenticated user itself instead — the same shape
| OAuthConnectionController already established for this exact
| problem. DocumentSecurityService::canBeDownloadedBy() remains the
| real, finer-grained authorization boundary; this route's middleware
| only proves "some authenticated firm user."
*/
Route::domain($hosts->firmAppHost())
    ->middleware(['auth', 'throttle:60,1'])
    ->get('documents/{document}/download', [DocumentDownloadController::class, 'show'])
    ->name('firm.documents.download');

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
| Client Portal document download — Client Portal host
|--------------------------------------------------------------------------
|
| Follow-up 1 (Client Portal Documents). Mirrors firm.documents.
| download's own middleware minimalism (auth + throttle only — no
| ambient tenant-context middleware) and its "the controller
| establishes context itself, never trusting the route parameter as a
| context source" discipline; see
| App\Http\Controllers\ClientPortal\DocumentDownloadController's own
| docblock for the full reasoning. Session-authenticated (auth:client)
| — deliberately never a public signed URL, same "documents are
| private by default" project rule the Firm-side route already
| documents.
*/
Route::domain($hosts->clientPortalHost())
    ->middleware(['auth:client', 'throttle:60,1'])
    ->get('documents/{document}/download', [ClientPortalDocumentDownloadController::class, 'show'])
    ->name('client-portal.documents.download');

/*
|--------------------------------------------------------------------------
| Client Portal invitation acceptance — Client Portal host
|--------------------------------------------------------------------------
|
| Mission 3A (MyAttorney Launch-Flow Closure). The one public,
| unauthenticated page a Client Portal invitation link resolves to —
| mirrors public.marketplace-intakes.show's own architecture exactly:
| 'signed' verifies the token/expiry Laravel itself embedded in the URL
| (see ClientPortalService::invitationUrl()), ConfigurePanelSessionCookie
| establishes the SAME session cookie name the client-portal panel
| itself uses, so a successful sign-in here carries into the panel's own
| subsequent authenticated requests. throttle:20,1 mirrors the intake
| resume page's own generous-but-bounded allowance.
*/
Route::domain($hosts->clientPortalHost())
    ->middleware([ConfigurePanelSessionCookie::class.':client', 'signed', 'throttle:20,1'])
    ->get('accept-invitation/{token}', AcceptInvitationPage::class)
    ->where('token', '[0-9a-fA-F-]{36}')
    ->name('client-portal.invitation.accept');

/*
|--------------------------------------------------------------------------
| Signup entry points — Firm host and Client Portal host
|--------------------------------------------------------------------------
|
| The panel sign-in pages now offer "Register your firm" / "Create client
| account". These are the pages those buttons land on.
|
| They are REQUEST forms, not account creation. Both record a PlatformLead via
| the canonical PlatformSalesLeadService and stop there — no Firm, User,
| FirmUser, Client or ClientPortalUser is created, and neither form accepts
| firm_id/client_id/matter_id/uuid from the browser. Accounts continue to be
| created only by FirmProvisioningService (firms) and the firm's own
| invite -> ClientPortalService::activate() flow (clients). See
| RegistrationRequestController for why the domain cannot represent a
| self-registered portal identity without schema change.
|
| Each route stays on its own host and carries that host's own panel session
| cookie, so neither widens a cookie or crosses a session boundary. There is
| deliberately NO equivalent on the Admin host: platform administrators are
| never self-registered.
*/
Route::domain($hosts->firmAppHost())
    ->middleware([ConfigurePanelSessionCookie::class.':firm'])
    ->group(function (): void {
        Route::get('register', [RegistrationRequestController::class, 'showFirmForm'])
            ->middleware('throttle:20,1')
            ->name('firm.register');

        Route::post('register', [RegistrationRequestController::class, 'storeFirmRequest'])
            ->middleware('throttle:5,1')
            ->name('firm.register.store');
    });

Route::domain($hosts->clientPortalHost())
    ->middleware([ConfigurePanelSessionCookie::class.':client'])
    ->group(function (): void {
        Route::get('register', [RegistrationRequestController::class, 'showClientForm'])
            ->middleware('throttle:20,1')
            ->name('client-portal.register');

        Route::post('register', [RegistrationRequestController::class, 'storeClientRequest'])
            ->middleware('throttle:5,1')
            ->name('client-portal.register.store');
    });

/*
|--------------------------------------------------------------------------
| MyAttorney host — myattorney.firmsvault.com
|--------------------------------------------------------------------------
|
| Mission 2 (MyAttorney Marketplace Core), checkpoint 4: real public
| routes replace the Mission 1 "coming soon" placeholder. Plain
| Laravel routes (not a Filament panel) — see
| docs/product/mission-2-myattorney-marketplace-design.md for why: this
| surface is mostly public, unauthenticated, SEO-indexable reads, which
| don't fit a panel's admin-oriented UX model, and the marketing host
| above is the only existing precedent for exactly this shape.
|
| No session/CSRF middleware on the pure-read routes below (home/firms/
| attorneys show) — kept exactly as checkpoint 4 left them, preserving
| cacheability (section 78). The correction-report routes (checkpoint
| 8, section 51) are the first real form on this host, so they alone
| get ConfigurePanelSessionCookie::class.':myattorney' — the same
| mechanism the Firm/Client/Admin panels use for their own distinctly-
| named, host-only session cookie (section 63: MyAttorney's session
| boundary must stay independent, never widen any `.firmsvault.com`
| cookie) — plus a request-volume-bounded throttle (section 64).
|
| Explicit slug lookups in each controller, not implicit route-model
| binding — DirectoryFirm/DirectoryAttorney's own HasPublicUuid trait
| already claims getRouteKeyName() for internal/API uuid resolution
| (section 43: the public SEO slug and the internal opaque identifier
| stay fully independent).
|
| Checkpoint 12 (SEO/sitemap/structured data): sitemap.xml/sitemap-
| pages.xml/sitemap-{firms,attorneys}-{page}.xml below, registered
| BEFORE the catch-all so it never shadows them. Real search-engine
| indexability itself stays config-gated
| (CanonicalUrlService::myAttorneyIndexingEnabled(), default off — see
| config/hosts.php) — building this surface is not the same decision
| as publicly launching it (Mission 1C's SAFE_TO_LAUNCH_MYATTORNEY_
| PUBLICLY = NO boundary).
*/
Route::domain($hosts->myAttorneyHost())->group(function () {
    // Every throttle below is a NAMED limiter, not an inline throttle:max,min.
    // That is not a style choice: ThrottleRequests keys an inline throttle on
    // domain + client IP with the URI excluded, so all of these routes would
    // share ONE counter and each would test it against its own maximum — a
    // visitor who read a profile a few times was then refused at the first
    // click of "Start Secure Intake". Named limiters key on the limiter name
    // too, giving each concern its own budget. See
    // MyAttorneyRateLimitServiceProvider for the limits and reasoning.

    // Mission 2 checkpoint 14 (security hardening): every route below
    // was completely unthrottled before this checkpoint — an
    // unauthenticated, DB-querying surface (search/candidates has no
    // row cap of its own either — see MarketplaceSearchService's
    // MAX_CANDIDATES — and every profile view fans out into several
    // more queries) is a real scraping/compute-amplification target
    // without one. `throttle:` keys by IP here (no session cookie on
    // these routes — section 63/78 — so IP is the only signal
    // available, matching how every other public/unauthenticated
    // throttled route in this codebase already works). Limits are
    // deliberately generous (real search/browsing traffic, and
    // legitimate crawlers once indexing is enabled — see
    // AddSearchIndexingHeader — must not be rate-limited into a bad
    // experience) rather than tight abuse thresholds.
    Route::middleware('throttle:myattorney-public')->group(function () {
        Route::get('/', [MyAttorneyHomeController::class, 'index'])->name('myattorney.home');
        Route::get('/attorneys/{slug}', [AttorneyProfileController::class, 'show'])->name('myattorney.attorneys.show');
    });

    // The firm profile is the one "read" route that is no longer purely a
    // read: Mission 3A put a "Start Secure Intake" POST form on it.
    //
    // A @csrf token is only meaningful against the session the POST target
    // will read, and the POST target runs under
    // ConfigurePanelSessionCookie:myattorney. Rendering the token WITHOUT that
    // middleware wrote it into the default `firmsvault-session` cookie while
    // the POST looked for `firmsvault-myattorney-session` — a brand-new,
    // empty session, so every single Start Intake submission answered 419.
    // The two routes have to agree on which cookie they mean; that agreement
    // is the fix.
    //
    // Cacheability (section 78) was already lost the moment a per-visitor CSRF
    // token appeared in this page's HTML — a shared cache storing one
    // visitor's token and replaying it to another is worse than not caching.
    // The controller now says so explicitly with a private/no-store header
    // rather than leaving it to a CDN's defaults.
    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'throttle:myattorney-public'])->group(function () {
        Route::get('/firms/{slug}', [FirmProfileController::class, 'show'])->name('myattorney.firms.show');
    });

    // The global robots.txt route above (no domain constraint) loses
    // to this host's own catch-all below when both are candidate
    // matches for the same request — Laravel resolves a domain-scoped
    // route ahead of a domain-less one regardless of file order. An
    // explicit route here, same controller, wins the same way the
    // sitemap routes below already do.
    Route::middleware('throttle:myattorney-crawl')->group(function () {
        Route::get('/robots.txt', RobotsTxtController::class);

        Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('myattorney.sitemap.index');
        Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('myattorney.sitemap.pages');
        Route::get('/sitemap-firms-{page}.xml', [SitemapController::class, 'firms'])->where('page', '[0-9]+')->name('myattorney.sitemap.firms');
        Route::get('/sitemap-attorneys-{page}.xml', [SitemapController::class, 'attorneys'])->where('page', '[0-9]+')->name('myattorney.sitemap.attorneys');
    });

    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'throttle:myattorney-correction'])->group(function () {
        Route::get('/firms/{slug}/report-correction', [CorrectionRequestController::class, 'create'])->name('myattorney.firms.report-correction.create');
        Route::post('/firms/{slug}/report-correction', [CorrectionRequestController::class, 'store'])->middleware('throttle:myattorney-correction-submit')->name('myattorney.firms.report-correction.store');
    });

    // Mission 3A (MyAttorney Launch-Flow Closure) — the "Start Secure
    // Intake" entry point on a Firm's public profile. Same throttle
    // tightness as report-correction's own store action (a real,
    // state-creating action, not a read) — mirrors that route's own
    // session-cookie + throttle shape exactly. Redirects to the
    // signed resumable-link page below on success; never itself
    // reachable via 'signed' since the visitor holds no link yet.
    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'throttle:myattorney-intake-start'])->group(function () {
        Route::post('/firms/{slug}/start-intake', [MarketplaceIntakeStartController::class, 'store'])->name('myattorney.firms.start-intake');
    });

    // "What do you need help with?" — shown only when the firm publishes more
    // than one practice area. A GET, and deliberately read-only: no intake row
    // exists until the visitor answers, so backing out leaves nothing behind.
    // Carries the myattorney session cookie for the same reason the profile
    // does — it renders a CSRF-protected form that posts to start-intake.
    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'throttle:myattorney-intake-choose'])->group(function () {
        Route::get('/firms/{slug}/start-intake', [MarketplaceIntakeStartController::class, 'choose'])
            ->name('myattorney.firms.start-intake.choose');
    });

    // Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 2 —
    // the one public, unauthenticated page a prospect's resumable
    // intake link resolves to. 'signed' verifies the uuid/expiry
    // Laravel itself embedded in the URL (see
    // MarketplaceIntakeService::signedUrl()); ConfigurePanelSessionCookie
    // establishes the isolated myattorney-panel session cookie the
    // same way the correction-request form above does, since later
    // checkpoints' multi-step answer-collection UI will need a real
    // session. the myattorney-intake-resume limiter mirrors payment_requests' own public link
    // page — generous enough for a legitimate prospect returning to
    // finish an intake, not a volumetric-abuse allowance.
    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'signed', 'throttle:myattorney-intake-resume'])->group(function () {
        Route::get('/intake/{uuid}', PublicIntakePage::class)
            ->where('uuid', '[0-9a-fA-F-]{36}')
            ->name('public.marketplace-intakes.show');
    });

    // Mission 3, checkpoint 7 — the document-upload action for an
    // already-resolved intake. Deliberately NOT under 'signed': the
    // visitor already proved possession of the resumable link by
    // loading the GET route above (which establishes the myattorney
    // panel session cookie); this POST is a same-session follow-up
    // action, exactly like the correction-request store route below
    // is a follow-up to its own GET. myattorney-intake-documents — tighter than the
    // read-only resume page, looser than a single-shot form
    // submission, since a legitimate visitor may attach several files.
    Route::middleware([ConfigurePanelSessionCookie::class.':myattorney', 'throttle:myattorney-intake-documents'])->group(function () {
        Route::post('/intake/{uuid}/documents', [MarketplaceIntakeDocumentController::class, 'store'])
            ->where('uuid', '[0-9a-fA-F-]{36}')
            ->name('public.marketplace-intakes.documents.store');
    });

    // The catch-all. It is domain-scoped, and Laravel resolves a domain-scoped
    // route ahead of a domain-less one regardless of file order (the same
    // precedence the robots.txt note above relies on) — so without the
    // exclusion below it also swallows framework routes registered without a
    // domain, and answers them with this placeholder.
    //
    // That is not hypothetical. It served `MyAttorney — coming soon.` as
    // text/html for /livewire/livewire.min.js, so Livewire never booted on the
    // public intake wizard: the page rendered, and every wire:click was inert
    // HTML — no request, no error, no message. It survived testing because
    // /livewire/update is a POST and this route is GET-only, so server-side
    // tests kept passing against an endpoint no browser could reach.
    //
    // Excluded by prefix rather than by listing today's asset paths, so a
    // Livewire upgrade that renames or adds a GET route cannot silently
    // reintroduce this.
    Route::get('/{any?}', function () {
        return response('MyAttorney — coming soon.', 200);
    })->where('any', '(?!livewire/).*');
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
