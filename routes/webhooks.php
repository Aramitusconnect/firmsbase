<?php

declare(strict_types=1);

use App\Http\Middleware\LimitInboundWebhookPayloadSize;
use App\Integrations\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound Integration Webhook Routes (Checkpoint 7)
|--------------------------------------------------------------------------
|
| reviews/checkpoint-07/frozen-design-post-security-review.md §1. Exactly
| ONE route: an unauthenticated, session-less, CSRF-exempt inbound
| webhook intake endpoint. `{provider}` is a route segment matched
| against the closed, non-dynamic `integration_providers.code` catalog
| (App\Integrations\Services\WebhookConnectionResolverService); the
| routing/identity token itself travels on a request header, never in
| the URL (frozen design §1/§9 — required for the timing-oracle
| mitigation and to avoid URL-based leakage into access/proxy logs).
|
| Deliberately NOT registered inside routes/web.php: that file's
| Route::get()/Route::middleware(['auth'])->... calls are all
| implicitly wrapped in the `web` middleware group (session, CSRF,
| cookie encryption) by bootstrap/app.php's `web:` routing parameter —
| exactly what this stateless, unauthenticated webhook intake route
| must NOT carry. Registering it in its own file, required directly
| (NOT via Route::middleware(['web'])->group(...) and NOT via the `web:`
| routing parameter), keeps it free of any implicit group middleware:
| only the two middleware explicitly attached below ever run.
|
| ***WIRING GAP — DISCLOSED, NOT SILENTLY WORKED AROUND***
| This file is created by this checkpoint but is NOT YET required by
| bootstrap/app.php. This application's routing (see bootstrap/app.php's
| `->withRouting(web: ..., commands: ..., health: ..., then: ...)` call)
| currently loads ONLY routes/web.php and routes/console.php — there is
| no `api:` parameter, no RouteServiceProvider, and no existing
| mechanism that auto-discovers additional route files. Actually
| loading this file requires ONE additional line inside bootstrap/
| app.php's existing `then: function (): void { ... }` closure (the
| same closure that already registers the `/readyz` route), e.g.:
|
|     require __DIR__.'/../routes/webhooks.php';
|
| bootstrap/app.php is OUTSIDE this checkpoint's strict production-file
| allowlist (reviews/checkpoint-07/frozen-design-post-security-review.md
| §17) — modifying it was not authorized for this implementation pass.
| This route is therefore fully implemented and independently correct,
| but NOT YET REACHABLE by real traffic until that one-line addition is
| made by a change explicitly authorized to touch bootstrap/app.php.
| This is flagged in the implementation report as a required follow-up,
| not silently left unmentioned.
|
*/

Route::post('/webhooks/integrations/{provider}', InboundWebhookController::class)
    ->where('provider', '[a-z0-9_]+')
    ->middleware([
        // IP-keyed throttling (frozen design §12) — Laravel's standard
        // throttle middleware, keyed by source IP by default for an
        // unauthenticated route (ThrottleRequests::resolveRequestSignature()
        // falls back to the request's IP when no authenticated user is
        // present). A 429 here is an explicitly disclosed, acceptable
        // exception to the collapse-to-false response-shape uniformity
        // (frozen design §12 — orthogonal to content correctness).
        'throttle:60,1',
        // 256 KB payload-size gate (frozen design §2), checked before
        // signature verification and before any database write.
        LimitInboundWebhookPayloadSize::class,
    ])
    ->name('integrations.webhooks.inbound');
