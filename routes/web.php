<?php

use App\Http\Controllers\ClientPortal\PlaidExchangeController;
use App\Http\Controllers\Integrations\OAuthConnectionController;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishClientPortalTenantContext;
use App\Livewire\PaymentRequests\PublicPaymentPage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Public Payment Request page (Payment Link / QR Routing phase)
|--------------------------------------------------------------------------
|
| The first genuinely public, unauthenticated PAGE this codebase has
| ever had (routes/webhooks.php's inbound webhook route is API-only,
| deliberately registered outside the `web` group; this route
| deliberately IS inside the implicit `web` group — via being declared
| in this file — because the payment form needs session/CSRF, unlike
| that stateless intake endpoint).
|
| `signed` is Laravel's own ValidateSignature middleware — the exact
| mechanism FirmOwnerInvitationNotification::resetUrl() already uses
| for password-reset links (see that class's own docblock), reused
| here rather than inventing a second signing scheme.
| PaymentRequestService::signedUrl() always generates this URL via
| URL::temporarySignedRoute(), so `expires`/`signature` are Laravel's
| own query parameters, never anything this app hand-rolls; a modified
| `{uuid}` or a signature past its own expiry both 403 here, before
| PublicPaymentPage::mount() ever runs.
|
| `throttle:30,1` mirrors the IP-keyed convention routes/webhooks.php
| already established for the only other public route in this app.
|
| `{uuid}` is a plain string, not an implicit Eloquent route-model
| binding — PublicPaymentPage resolves it itself via
| PaymentRequestService::resolveByUuid(), which runs inside the RLS
| self-lookup bootstrap hop (TenantContextService::
| withPaymentRequestSelfLookupContext()) — an implicit binding would
| attempt the same query before any context exists and always see zero
| rows under payment_requests' own FORCE ROW LEVEL SECURITY.
*/
Route::get('/pay/{uuid}', PublicPaymentPage::class)
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('public.payment-requests.show');

/*
|--------------------------------------------------------------------------
| Integration OAuth Routes (Checkpoint 5)
|--------------------------------------------------------------------------
|
| First HTTP-facing routes in the App\Integrations domain
| (checkpoint-00-final-specification.md §6; agent-h-security-architecture-review.md
| item 22 — confirmed no prior route/controller existed anywhere in this
| domain). Both routes are session-`auth`-guarded (the default `web`
| guard, matching config/auth.php and the same guard the firm/admin
| Filament panels already authenticate against) — an unauthenticated
| request is redirected to the sign-in screen by the `auth` middleware
| before ever reaching OAuthConnectionController.
|
| No explicit CSRF exclusion is added for the callback route: Laravel's
| VerifyCsrfToken middleware (part of the `web` group already applied to
| this whole file) only verifies POST/PUT/PATCH/DELETE requests — a GET
| route is never subject to CSRF verification in the first place, so
| there is nothing to explicitly withoutMiddleware() here. This is
| exactly the frozen design's "no CSRF middleware on the callback GET
| route" requirement, satisfied structurally by the route's own verb
| rather than by an extra exclusion that would otherwise be redundant.
|
| The `{firmIntegration}` parameter on the initiate route is
| DELIBERATELY a plain string, not an implicit Eloquent route-model
| binding — see OAuthConnectionController's own class docblock for why
| (firm_integrations has no self-lookup RLS carve-out; the controller
| resolves it itself, inside an explicit tenant context, once the
| current user's own firm is known).
|
*/
Route::middleware(['auth'])->prefix('integrations/oauth')->name('integrations.oauth.')->group(function () {
    Route::get('{firmIntegration}/initiate', [OAuthConnectionController::class, 'initiate'])->name('initiate');
    Route::get('callback', [OAuthConnectionController::class, 'callback'])->name('callback');
});

/*
|--------------------------------------------------------------------------
| Client Portal Plaid Link exchange (Checkpoint 4, "Plaid financial
| evidence add-on")
|--------------------------------------------------------------------------
|
| checkpoint4-design-workspace-and-admin-ui.md §4.3;
| checkpoint4-combined-design.md §1.3 — the ONE new, hand-written,
| non-Filament HTTP endpoint this checkpoint adds. Path is
| `portal/plaid/exchange` (NOT `client-portal/plaid/exchange`, per §1.3's
| found-and-fixed drift: the client-portal panel's Filament-internal id
| is `client-portal`, but its actual mounted URL path is `portal` —
| ClientPortalPanelProvider::panel()->path('portal')). Carries the same
| `auth:client` guard check + `EstablishClientPortalTenantContext` +
| `ApplyTenantDatabaseContext` stack every other authenticated Client
| Portal action already has (EncryptCookies/StartSession/VerifyCsrfToken
| are already applied to this whole file via the implicit `web` group).
*/
Route::middleware(['auth:client', EstablishClientPortalTenantContext::class, ApplyTenantDatabaseContext::class])
    ->post('portal/plaid/exchange', [PlaidExchangeController::class, 'exchange'])
    ->name('client-portal.plaid.exchange');
