<?php

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * ApplyTenantDatabaseContext — Section 39A. Route-independent:
 * deliberately NOT registered in bootstrap/app.php and NOT attached to
 * any route (confirmed by direct inspection — routes/web.php has only
 * the default welcome route, routes/api.php does not exist, no
 * Fortify/Breeze/auth scaffolding exists). Wiring this into a real
 * request pipeline is deferred to whichever future auth/routing
 * section establishes how a request resolves "which firm is the
 * authenticated user acting as" — that resolution does not exist yet,
 * and this middleware must never guess it from raw user input.
 *
 * It only bridges an ALREADY-RESOLVED PHP-memory tenant context
 * (TenantContextService::hasFirmContext() — set by whatever upstream
 * code legitimately resolved the firm, e.g. from an authenticated
 * FirmUser membership) into the PostgreSQL session setting the RLS
 * policies read, and always clears it once the response has been
 * produced, even if the next handler throws.
 */
class ApplyTenantDatabaseContext
{
    public function __construct(private readonly TenantContextService $tenantContextService)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->tenantContextService->hasFirmContext()) {
            $this->tenantContextService->setDatabaseTenantContext();
        }

        try {
            return $next($request);
        } finally {
            $this->tenantContextService->clearDatabaseTenantContext();
        }
    }
}
