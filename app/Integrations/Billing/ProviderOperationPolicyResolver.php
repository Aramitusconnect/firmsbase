<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\ProviderKillSwitchActiveException;
use App\Integrations\Exceptions\ProviderOptionalOperationSuspendedException;
use App\Integrations\Models\ProviderFirmOperationPolicy;
use App\Integrations\Models\ProviderKillSwitch;
use App\Integrations\Models\ProviderOperationDefaultPolicy;
use App\Models\Firm;
use App\Services\TenantContextService;

/**
 * ProviderOperationPolicyResolver — pipeline step 7
 * (checkpoint4-design-cost-control.md §2 step 7; §4.3). Reads
 * `provider_kill_switches` (PLATFORM scope only — checked broad to
 * narrow: product -> endpoint_category -> operation) and, per the
 * coordinator-resolved two-table split
 * (checkpoint4-combined-design.md §1.8), the firm-scoped
 * `provider_firm_operation_policies` table first, falling back to the
 * global `provider_operation_default_policies` table on a per-field
 * miss — resolving Finding 3 of `checkpoint4-security-review.md` (the
 * combined design's own §8.3 still narrated the pre-split, rejected
 * single-table name; this implementation is built directly against the
 * two real, split tables, never a merged one).
 *
 * Throws `ProviderKillSwitchActiveException` immediately on any matching
 * PLATFORM-scope kill switch — no further pipeline step runs. Throws
 * `ProviderOptionalOperationSuspendedException` immediately when the
 * firm has suspended its own optional operation (only ever checked for
 * an `isOptional` classification — core Item lifecycle/Transactions
 * sync can never reach this branch). Both are deliberately the FIRST
 * hard-stop points in the pipeline after entitlement/capability, per
 * the design's own "cheapest possible failure point" reasoning.
 */
final class ProviderOperationPolicyResolver
{
    public function resolve(
        ProviderKey $providerKey,
        ProviderBillingClassification $classification,
        Firm $firm,
        string $environment,
    ): ProviderOperationPolicy {
        $this->assertNoPlatformKillSwitchActive($providerKey, $classification);

        [$firmPolicy, $defaultPolicy] = (new TenantContextService())->runWithFirmContext($firm, function () use ($providerKey, $classification, $environment, $firm) {
            $firmPolicy = ProviderFirmOperationPolicy::query()
                ->where('firm_id', $firm->id)
                ->where('provider_key', $providerKey->value)
                ->where('product', $classification->product)
                ->where('environment', $environment)
                ->first();

            $defaultPolicy = ProviderOperationDefaultPolicy::query()
                ->where('provider_key', $providerKey->value)
                ->where('product', $classification->product)
                ->where('environment', $environment)
                ->first();

            return [$firmPolicy, $defaultPolicy];
        });

        if ($classification->isOptional && $firmPolicy !== null && $firmPolicy->optional_operation_suspended) {
            throw new ProviderOptionalOperationSuspendedException(
                $providerKey->value,
                $classification->product,
                (int) $firm->id,
            );
        }

        return new ProviderOperationPolicy(
            softLimitQuantity: $firmPolicy?->soft_limit_quantity ?? $defaultPolicy?->soft_limit_quantity,
            hardLimitQuantity: $firmPolicy?->hard_limit_quantity ?? $defaultPolicy?->hard_limit_quantity,
            limitWindowSeconds: $firmPolicy?->limit_window_seconds ?? $defaultPolicy?->limit_window_seconds ?? 86400,
            cooldownSeconds: $firmPolicy?->cooldown_seconds ?? $defaultPolicy?->cooldown_seconds
                ?? (int) config('integrations.provider_billing.default_cooldown_seconds', 0),
            cacheTtlSeconds: $firmPolicy?->cache_ttl_seconds ?? $defaultPolicy?->cache_ttl_seconds,
        );
    }

    /**
     * Broad-to-narrow platform-scope check only (design §4.3 points 1-3).
     * Point 4 of the design's own check order — a firm-scope kill-switch
     * row for optional-operation suspension — is deliberately NOT
     * checked here; that mechanism lives on
     * `provider_firm_operation_policies.optional_operation_suspended`
     * instead, per this class's own docblock and
     * `ProviderOptionalOperationSuspendedException`'s docblock.
     */
    private function assertNoPlatformKillSwitchActive(ProviderKey $providerKey, ProviderBillingClassification $classification): void
    {
        $checks = [
            [ProviderKillSwitch::LEVEL_PRODUCT, $classification->product],
            [ProviderKillSwitch::LEVEL_ENDPOINT_CATEGORY, $classification->endpointCategory],
            [ProviderKillSwitch::LEVEL_OPERATION, "{$classification->product}:{$classification->billingOperation}"],
        ];

        foreach ($checks as [$level, $target]) {
            $match = ProviderKillSwitch::query()
                ->where('provider_key', $providerKey->value)
                ->where('scope_type', ProviderKillSwitch::SCOPE_PLATFORM)
                ->whereNull('scope_id')
                ->where('level', $level)
                ->where('target', $target)
                ->where('suspended', true)
                ->first();

            if ($match !== null) {
                throw new ProviderKillSwitchActiveException(
                    $providerKey->value,
                    $level,
                    $target,
                    $match->reason,
                );
            }
        }
    }
}
