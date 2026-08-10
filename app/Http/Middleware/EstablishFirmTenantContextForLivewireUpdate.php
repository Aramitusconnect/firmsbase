<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CanonicalUrlService;
use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * EstablishFirmTenantContextForLivewireUpdate — CP13 P1
 * (p1-livewire-fix-frozen-design.md §2/§3/§5). Dedicated middleware for
 * the app's own replacement Livewire update route
 * (`Livewire::setUpdateRoute()`, registered in AppServiceProvider::boot()),
 * which every mutating Filament action on ViewFirmIntegration and its
 * three RelationManagers actually executes through.
 *
 * Why this exists (and why it does NOT touch
 * EstablishFirmTenantContext/ApplyTenantDatabaseContext — those stay
 * exactly as-is, used only by page-LOAD routes): the shared
 * `livewire/update` route never carried this app's tenant-context
 * middleware, so on every subsequent POST /livewire/update request
 * Livewire's `ModelSynth::hydrate()` re-fetched the `#[Locked]`
 * Model-typed `$record`/`$ownerRecord` properties via `firstOrFail()`
 * with NO `app.current_firm_id` PostgreSQL session setting active —
 * FORCE ROW LEVEL SECURITY then returned zero rows, throwing
 * ModelNotFoundException for every real, authorized user.
 *
 * This does NOT repeat Checkpoint 10's reverted
 * `Livewire::addPersistentMiddleware()` attempt: that appended to
 * Livewire's persistent-replay list, whose terminal `$next` is a dummy
 * Response, so the `finally` teardown fired BEFORE hydration (see
 * PersistentMiddlewareTenantContextLifetimeTest). This is genuine route
 * middleware wrapping the real `handleUpdate()` controller — `handle()`
 * runs strictly before hydration, `finally` unwinds strictly after the
 * full response is built.
 *
 * Panel scoping is done INSIDE the middleware (the update route is a
 * single shared route for all panels, so any middleware on it is
 * global, and is itself deliberately domain-unconstrained so every
 * panel's browser can reach it). Originally gated on the incoming
 * component snapshot's `memo.path` first segment being the literal
 * string `firm` (FirmPanelProvider::path('firm')); Mission 1 (canonical
 * reconstruction) moved every panel from a shared host + distinct path
 * onto its own distinct canonical host (path('') on each), which
 * removed that `firm/` prefix from every real snapshot. The gate is
 * therefore now the REQUEST'S OWN Host header against
 * CanonicalUrlService::firmAppHost() — equally reliable (a real
 * browser's Livewire POST always carries the Host of the page it was
 * rendered on) and immune to the same dual-login edge case: an
 * admin-panel update arrives on admin.firmsvault.com, never
 * app.firmsvault.com, regardless of which guards the session happens to
 * carry.
 */
class EstablishFirmTenantContextForLivewireUpdate
{
    public function __construct(
        private readonly TenantContextService $tenant,
        private readonly CanonicalUrlService $canonicalUrlService,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->isFirmPanelUpdate($request)) {
            return $next($request);
        }

        $firmUser = $request->user()?->activeFirmUser();

        if ($firmUser === null) {
            // Not an authenticated firm member (or no active membership):
            // establish nothing and let the request proceed — the shared
            // update route serves more than just this app's firm panel,
            // and a genuinely unauthorized action still fails closed
            // downstream under FORCE RLS with no firm context.
            return $next($request);
        }

        $this->tenant->setFirmContext((int) $firmUser->firm_id);
        $this->tenant->setDatabaseTenantContext();

        try {
            return $next($request);
        } finally {
            $this->tenant->clearDatabaseTenantContext();
            $this->tenant->clearFirmContext();
        }
    }

    /**
     * True only when this request arrived on the Firm app's own
     * canonical host — see the class docblock for why the Host header
     * replaced the old `memo.path` prefix check.
     */
    private function isFirmPanelUpdate(Request $request): bool
    {
        return $request->getHost() === $this->canonicalUrlService->firmAppHost();
    }
}
