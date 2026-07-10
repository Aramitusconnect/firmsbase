<?php

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * EstablishFirmTenantContext — internal login/panel access wiring. This
 * is the resolution point ApplyTenantDatabaseContext's own docblock has
 * always deferred to: given an already-authenticated firm user (the
 * `web` guard's User model), it resolves which firm that user is
 * currently acting as (their one ACTIVE FirmUser membership, via
 * User::activeFirmUser() — the same self-lookup bootstrap
 * User::canAccessPanel() itself already relies on) and activates
 * PHP-memory tenant context for exactly the duration of the request,
 * via TenantContextService — never by guessing from raw user input (a
 * query string, a header, a route parameter), only from the user's own
 * real membership row.
 *
 * A user with no active firm membership is left with no context set at
 * all (fail-closed: every BelongsToTenant-scoped query then sees
 * nothing, and Postgres RLS sees no session firm_id) rather than being
 * blocked here — User::canAccessPanel() is the actual gate that keeps
 * such a user off the firm panel in the first place.
 *
 * Always clears context in a finally block, even if the next handler
 * throws, so no firm's context can ever leak into a later request
 * sharing the same worker process.
 */
class EstablishFirmTenantContext
{
    public function __construct(private readonly TenantContextService $tenantContextService)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        $firmUser = $user?->activeFirmUser();

        if ($firmUser === null) {
            return $next($request);
        }

        $this->tenantContextService->setFirmContext($firmUser->firm_id);

        try {
            return $next($request);
        } finally {
            $this->tenantContextService->clearFirmContext();
        }
    }
}
