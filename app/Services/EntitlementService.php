<?php

namespace App\Services;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\FirmEntitlementEvent;
use App\Models\User;
use App\ValueObjects\EntitlementResolution;
use Illuminate\Support\Facades\DB;

/**
 * EntitlementService — the ONLY place entitlement precedence is
 * resolved. Precedence (highest wins): admin_override > firm_override
 * > org_inherited > plan, implemented via EntitlementSource::precedence(),
 * not array order.
 *
 * org_inherited and plan sources have no real upstream data yet (org
 * licensing and plans are Phase 6) — this service still implements the
 * full four-source resolution now, so nothing needs to change when
 * Phase 6 starts writing those sources.
 *
 * No caching layer — a plain query per resolution. Caching is a valid
 * later optimization, not core scope.
 *
 * Section 39A-3L, Checkpoint 4 - firm_entitlements now has FORCE ROW
 * LEVEL SECURITY active. resolve() is the sole PRECEDENCE-RESOLUTION
 * read chokepoint (~13 downstream consumer services funnel through it
 * for "is this module enabled" checks, confirmed by grep) and
 * setForSource() is the sole write chokepoint (confirmed by grep - no
 * other file anywhere calls FirmEntitlement::create/update) — each
 * self-wraps its entire body in one runWithFirmContext() call. Neither
 * of setForSource()'s callers (EntitlementOverrideService::
 * setOverride(), EntitlementPlanSyncService::syncPlanEntitlements()/
 * syncOrgInheritedEntitlements()) establishes any context of its own,
 * so there is no nesting risk there. Do not wrap those callers.
 *
 * CORRECTION: resolve() is NOT the sole *read* path against
 * firm_entitlements — it is only the sole path for resolving one
 * module's precedence. DowngradeEvaluationService::evaluate() and
 * DeploymentFeatureFlagAuditService::isFullyAudited() each read
 * firm_entitlements directly (to enumerate/count raw rows rather than
 * resolve one module's precedence) and were independently wrapped in
 * their own runWithFirmContext() calls in this same batch — see those
 * files' diffs. Any future direct read against firm_entitlements
 * outside EntitlementService must be wrapped the same way; do not
 * assume resolve()'s wrap alone covers every read path against this
 * table.
 */
class EntitlementService
{
    /**
     * Must never be called from inside an already-active outer
     * runWithFirmContext() — this method establishes its own whole-body
     * context. Calling it from within another active context would
     * nest two wraps, and this method's own finally block would clear
     * the outer caller's context the moment it returns (the "decoy
     * wrap" bug this project's convention explicitly guards against).
     */
    public function resolve(int $firmId, string $moduleCode): EntitlementResolution
    {
        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId, $moduleCode) {
            $candidates = FirmEntitlement::query()
                ->where('firm_id', $firmId)
                ->where('module_code', $moduleCode)
                ->get()
                ->filter(fn (FirmEntitlement $e) => $e->isWithinActiveWindow());

            if ($candidates->isEmpty()) {
                return EntitlementResolution::notEntitled();
            }

            $winner = $candidates->sortByDesc(
                fn (FirmEntitlement $e) => $e->source->precedence()
            )->first();

            return EntitlementResolution::fromEntitlement($winner);
        });
    }

    public function isEnabled(int $firmId, string $moduleCode): bool
    {
        return $this->resolve($firmId, $moduleCode)->enabled;
    }

    /**
     * Create or replace the entitlement record for one specific
     * source, and write the corresponding audit event, atomically.
     * Does not touch records belonging to other sources — precedence
     * resolution at read time decides which one currently wins.
     *
     * Must never be called from inside an already-active outer
     * runWithFirmContext() — this method establishes its own whole-body
     * context (wrapping the existing DB::transaction() rather than
     * replacing it). Calling it from within another active context
     * would nest two wraps, and this method's own finally block would
     * clear the outer caller's context the moment it returns (the
     * "decoy wrap" bug this project's convention explicitly guards
     * against). Its own callers (EntitlementOverrideService::
     * setOverride(), EntitlementPlanSyncService) must not wrap this
     * call themselves.
     */
    public function setForSource(
        Firm $firm,
        string $moduleCode,
        EntitlementSource $source,
        bool $enabled,
        array $settings = [],
        ?User $actor = null,
        ?string $reason = null,
        ?\DateTimeInterface $startsAt = null,
        ?\DateTimeInterface $endsAt = null,
    ): FirmEntitlement {
        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $moduleCode, $source, $enabled, $settings, $actor, $reason, $startsAt, $endsAt
        ) {
            return DB::transaction(function () use (
                $firm, $moduleCode, $source, $enabled, $settings, $actor, $reason, $startsAt, $endsAt
            ) {
                $existing = FirmEntitlement::query()
                    ->where('firm_id', $firm->id)
                    ->where('module_code', $moduleCode)
                    ->where('source', $source->value)
                    ->first();

                $attributes = [
                    'firm_id' => $firm->id,
                    'module_code' => $moduleCode,
                    'enabled' => $enabled,
                    'source' => $source,
                    'settings_json' => $settings,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'created_by' => $actor?->id,
                ];

                $entitlement = $existing
                    ? tap($existing)->update($attributes)
                    : FirmEntitlement::create($attributes);

                FirmEntitlementEvent::create([
                    'firm_entitlement_id' => $entitlement->id,
                    'firm_id' => $firm->id,
                    'module_code' => $moduleCode,
                    'source' => $source->value,
                    'action' => $existing ? 'updated' : 'granted',
                    'reason' => $reason,
                    'actor_type' => $actor ? User::class : 'System',
                    'actor_id' => $actor?->id,
                    'metadata' => ['enabled' => $enabled],
                ]);

                return $entitlement->fresh();
            });
        });
    }
}
