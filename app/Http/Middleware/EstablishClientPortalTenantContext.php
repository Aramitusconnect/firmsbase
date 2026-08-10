<?php

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * EstablishClientPortalTenantContext — Mission 1 (Domain & Security
 * Boundary Architecture), the client.firmsvault.com Client Portal
 * panel's equivalent of EstablishFirmTenantContext. Simpler than its
 * Firm counterpart: a Client belongs to exactly one Firm directly
 * (clients.firm_id — there is no membership table to resolve, unlike
 * FirmUser), so this reads that column straight off the already-
 * authenticated `client`-guard user (Filament\Http\Middleware\
 * Authenticate has already called Auth::shouldUse() for this panel's
 * guard by the time authMiddleware runs, so $request->user() resolves
 * correctly here with no explicit guard name).
 *
 * A guest (no authenticated Client) is left with no context set at
 * all — fail-closed, exactly the same convention as
 * EstablishFirmTenantContext. Always clears context in a finally
 * block, even if the next handler throws, so no firm's context can
 * ever leak into a later request sharing the same worker process.
 */
class EstablishClientPortalTenantContext
{
    public function __construct(private readonly TenantContextService $tenantContextService) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $client = $request->user();

        if ($client === null) {
            return $next($request);
        }

        $this->tenantContextService->setFirmContext($client->firm_id);

        try {
            return $next($request);
        } finally {
            $this->tenantContextService->clearFirmContext();
        }
    }
}
