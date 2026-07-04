<?php

namespace App\Models\Concerns;

use App\Exceptions\TenantIsolationException;
use App\Services\TenantContextResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * BelongsToTenant — the app-layer half of defense-in-depth tenant
 * isolation. The database-level half is the RLS policies prepared in
 * the tenancy migration.
 *
 * Design — "narrow only, never widen":
 *   - If a TenantContext IS currently active, every query against a
 *     model using this trait is automatically constrained to that
 *     firm_id.
 *   - If NO context is active, the scope adds no constraint at all —
 *     it defers to whatever the caller does explicitly.
 *
 * This is deliberate, not an oversight: making the scope fail-closed
 * (empty results) whenever no context is active would break every
 * test and every console command/job/seeder in the application, since
 * the middleware that calls TenantContextResolver::set() per request
 * does not exist yet — wiring it requires touching routes/ and/or
 * bootstrap/providers.php, both frozen files. The real fail-closed
 * guarantee is designed to live at the database layer: RLS policies
 * evaluate current_setting('app.current_firm_id', true), which is
 * NULL until a session variable is explicitly set, and NULL never
 * equals any firm_id. That activation (FORCE ROW LEVEL SECURITY plus
 * the SET LOCAL middleware) is intentionally not enabled yet — see the
 * migration's own doc comment for the exact follow-up gate.
 *
 * Apply this trait only to models with a firm_id column. Models
 * without one (e.g. ActivationChecklistItem, scoped transitively
 * through its parent ActivationChecklist) must not use this trait.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (TenantContextResolver::hasContext()) {
                $builder->where(
                    $builder->getModel()->getTable().'.firm_id',
                    TenantContextResolver::current()->firmId
                );
            }
        });

        static::creating(function ($model) {
            if (empty($model->firm_id) && TenantContextResolver::hasContext()) {
                $model->firm_id = TenantContextResolver::current()->firmId;
            }
        });
    }

    /**
     * Explicit, intentional escape hatch for legitimate cross-firm
     * operations (e.g. a platform-admin console command).
     */
    public function scopeWithoutTenantScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope('tenant');
    }

    /**
     * Explicit cross-firm lookup by a specific firm id, ignoring
     * whatever context (if any) is currently active.
     */
    public static function forFirmIgnoringContext(int $firmId): Builder
    {
        return static::query()->withoutGlobalScope('tenant')->where('firm_id', $firmId);
    }

    /**
     * Defensive guard for the "fetched by uuid via route-model binding"
     * case. Throws if a context is active and the model's firm_id does
     * not match it. No-ops if no context is active.
     */
    public function assertBelongsToActiveTenant(): void
    {
        if (! TenantContextResolver::hasContext()) {
            return;
        }

        if ($this->firm_id !== TenantContextResolver::current()->firmId) {
            throw new TenantIsolationException(
                sprintf(
                    '%s [id=%s] does not belong to the active tenant context (firm_id=%s).',
                    static::class,
                    $this->getKey(),
                    TenantContextResolver::current()->firmId
                )
            );
        }
    }
}
