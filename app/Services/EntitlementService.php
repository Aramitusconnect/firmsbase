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
 */
class EntitlementService
{
    public function resolve(int $firmId, string $moduleCode): EntitlementResolution
    {
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
    }
}
