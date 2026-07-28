<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Exceptions\ProviderCooldownActiveException;
use App\Integrations\Models\FirmIntegration;
use Illuminate\Support\Facades\Cache;

/**
 * ProviderCooldownService — pipeline step 10
 * (checkpoint4-design-cost-control.md §2 step 10/§5.1). `Cache`-backed
 * (not DB) — ephemeral, short-TTL, read-heavy state, the same shape
 * `App\Integrations\Support\PerConnectionRateLimiter` already uses
 * `Illuminate\Cache\RateLimiter`-adjacent primitives for; a cooldown
 * that has expired is simply absent from cache, no sweep job needed.
 *
 * `start()` is called only from pipeline step 15 (finalize), only on a
 * `finalized_billable` outcome — a non-billable or uncertain outcome
 * never starts a cooldown (no reason to penalize a caller for a call
 * Plaid never actually processed).
 */
final class ProviderCooldownService
{
    public function assertNotCoolingDown(FirmIntegration $connection, ProviderBillingClassification $classification, ProviderOperationPolicy $policy, ?string $accountId = null): void
    {
        $remaining = $this->remainingSeconds($connection, $classification, $accountId);

        if ($remaining > 0) {
            throw new ProviderCooldownActiveException($remaining);
        }
    }

    public function remainingSeconds(FirmIntegration $connection, ProviderBillingClassification $classification, ?string $accountId): int
    {
        $expiresAt = Cache::get($this->key($connection, $classification, $accountId));

        if ($expiresAt === null) {
            return 0;
        }

        $remaining = (int) round(now()->diffInSeconds($expiresAt, false));

        return max(0, $remaining);
    }

    public function start(FirmIntegration $connection, ProviderBillingClassification $classification, ?string $accountId, int $cooldownSeconds): void
    {
        if ($cooldownSeconds <= 0) {
            return;
        }

        Cache::put(
            $this->key($connection, $classification, $accountId),
            now()->addSeconds($cooldownSeconds),
            $cooldownSeconds,
        );
    }

    private function key(FirmIntegration $connection, ProviderBillingClassification $classification, ?string $accountId): string
    {
        return "provider-cooldown:{$connection->id}:{$classification->capability()}".($accountId !== null ? ":{$accountId}" : '');
    }
}
