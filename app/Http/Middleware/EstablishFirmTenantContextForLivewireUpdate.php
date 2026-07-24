<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 * global): the primary gate is the incoming component snapshot's
 * `memo.path` (each snapshot carries its render path) — only requests
 * whose component belongs to the `firm` panel path establish firm
 * context; Admin/SuperAdmin (`platform_admin` guard, `admin` path)
 * requests fall straight through untouched. Path-gating (not
 * guard-only) is deliberate so the dual-login edge case — one browser
 * session authenticated on BOTH `web` and `platform_admin` guards —
 * still correctly no-ops for an admin-panel update.
 */
class EstablishFirmTenantContextForLivewireUpdate
{
    /**
     * The Firm panel's own path prefix (FirmPanelProvider::path('firm')).
     * The Admin panel uses 'admin' and is never matched here.
     */
    private const FIRM_PANEL_PATH_PREFIX = 'firm';

    public function __construct(private readonly TenantContextService $tenant)
    {
    }

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
     * True only when at least one component in the update payload renders
     * under the Firm panel's path prefix. Each Livewire component
     * snapshot is a JSON string carrying `memo.path` — the render path of
     * the page the component belongs to.
     */
    private function isFirmPanelUpdate(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);

            if (! is_array($snapshot)) {
                continue;
            }

            $path = ltrim((string) ($snapshot['memo']['path'] ?? ''), '/');
            $firstSegment = explode('/', $path)[0] ?? '';

            if ($firstSegment === self::FIRM_PANEL_PATH_PREFIX) {
                return true;
            }
        }

        return false;
    }
}
