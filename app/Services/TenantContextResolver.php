<?php

namespace App\Services;

use App\Models\Firm;
use App\ValueObjects\TenantContext;

/**
 * TenantContextResolver — resolves a Firm into a TenantContext, and
 * holds the currently-active context via a static registry.
 *
 * Explicitly OUT OF SCOPE for Phase 1 (deferred to the routing/
 * middleware phase that owns routes/ and bootstrap/providers.php, both
 * frozen files): HTTP middleware that calls set() at the start of a
 * request and clear() at the end; queue-job middleware doing the same;
 * console-command wiring; service-container binding of TenantContext.
 * Phase 1 only builds the resolver and context object plus the
 * fail-closed enforcement primitive (BelongsToTenant) that consumes
 * current(). Wiring this into the live request lifecycle is a distinct,
 * later, explicitly-approved gate.
 */
class TenantContextResolver
{
    private static ?TenantContext $current = null;

    public function resolveForFirm(Firm $firm): TenantContext
    {
        return new TenantContext(
            firmId: $firm->id,
            firmUuid: $firm->uuid,
            organizationId: $firm->organization_id,
            deploymentMode: $firm->deployment_mode,
        );
    }

    public function activateForFirm(Firm $firm): TenantContext
    {
        $context = $this->resolveForFirm($firm);
        self::set($context);

        return $context;
    }

    public static function set(TenantContext $context): void
    {
        self::$current = $context;
    }

    public static function current(): ?TenantContext
    {
        return self::$current;
    }

    public static function hasContext(): bool
    {
        return self::$current !== null;
    }

    /**
     * Must be called at the end of every request/job/command that
     * called set()/activateForFirm() — prevents a tenant context from
     * one unit of work leaking into the next when the same PHP process
     * is reused (queue workers, octane, etc.).
     */
    public static function clear(): void
    {
        self::$current = null;
    }
}
