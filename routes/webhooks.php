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
| ***WIRED AND REACHABLE SINCE CHECKPOINT 7***
| This file is required directly from bootstrap/app.php's existing
| `then: function (): void { ... }` closure (the same closure that
| registers the `/readyz` route), via:
|
|     require __DIR__.'/../routes/webhooks.php';
|
| That require line was added in this file's own creation commit
| (`01605b6`, Checkpoint 7) — this route has therefore been reachable by
| real traffic since Checkpoint 7, not merely "implemented but not yet
| wired." See bootstrap/app.php for the require call.
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
