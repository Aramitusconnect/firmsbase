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
        // firms.deployment_mode is NOT NULL DEFAULT 'saas' at the schema
        // level -- a fully-loaded, persisted Firm can never legitimately
        // have this be null. Null here always means the caller loaded $firm
        // with a restricted column list that omitted deployment_mode (e.g.
        // ->get(['id', 'uuid', 'name']) or ->select('id')) and then passed
        // that partial model straight into runWithFirmContext()/
        // activateForFirm() instead of passing the firm id/uuid (which
        // makes the caller re-fetch the full row). Failing loudly here with
        // an actionable message, rather than letting a bare TypeError
        // bubble up from the TenantContext constructor, is what lets this
        // class of bug get caught immediately instead of producing a
        // confusing crash several frames away from its real cause.
        if ($firm->deployment_mode === null) {
            throw new \RuntimeException(sprintf(
                'Cannot resolve TenantContext for firm #%d: deployment_mode was not loaded on this Firm instance. '.
                'Every query that feeds a Firm model into TenantContextResolver/TenantContextService::runWithFirmContext() '.
                'must either select the full row or pass the firm id/uuid instead of a partially-selected model.',
                $firm->id,
            ));
        }

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
