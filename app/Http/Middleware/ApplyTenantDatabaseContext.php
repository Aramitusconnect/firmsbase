<?php

namespace App\Http\Middleware;

use App\Services\TenantContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * ApplyTenantDatabaseContext — Section 39A. Internal login/panel access
 * wiring now attaches this to the firm Filament panel's authMiddleware
 * (app/Providers/Filament/FirmPanelProvider.php), always AFTER
 * EstablishFirmTenantContext — that middleware is what legitimately
 * resolves "which firm is the authenticated user acting as" from the
 * user's active FirmUser membership; this middleware never resolves
 * the firm itself.
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
