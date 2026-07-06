<?php

namespace App\Services;

use App\Enums\DegradedBehavior;
use App\Enums\IntegrationType;
use App\Models\IntegrationDegradationMode;

/**
 * IntegrationDegradationRegistryService — read-only query layer over
 * the seeded integration_degradation_modes table. Declaration-only
 * (approved decision #1) — this service never calls Stripe/email/
 * virus-scan/telemetry, and no existing service of those kinds is
 * wired to consult it in Phase 16.
 */
class IntegrationDegradationRegistryService
{
    public function behaviorFor(IntegrationType $type): DegradedBehavior
    {
        $mode = IntegrationDegradationMode::query()->where('integration_type', $type->value)->first();

        if (! $mode) {
            throw new \RuntimeException("No degradation mode declared for integration type: {$type->value}.");
        }

        return $mode->degraded_behavior;
    }

    /**
     * @return array<string, DegradedBehavior>
     */
    public function allDeclarations(): array
    {
        return IntegrationDegradationMode::query()
            ->get()
            ->mapWithKeys(fn (IntegrationDegradationMode $mode) => [$mode->integration_type->value => $mode->degraded_behavior])
            ->all();
    }

    /**
     * Admin-visibility query output (project rule: no UI in Phase 16 —
     * dashboard visibility via service/query outputs). True only when
     * every IntegrationType case has a declared row.
     */
    public function everyIntegrationHasADeclaredMode(): bool
    {
        $declared = $this->allDeclarations();

        foreach (IntegrationType::cases() as $type) {
            if (! array_key_exists($type->value, $declared)) {
                return false;
            }
        }

        return true;
    }
}
