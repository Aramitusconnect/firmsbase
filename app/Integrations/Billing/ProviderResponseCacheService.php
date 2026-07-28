<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Models\FirmIntegration;
use Illuminate\Support\Facades\Cache;

/**
 * ProviderResponseCacheService — pipeline step 8
 * (checkpoint4-design-cost-control.md §2 step 8). A thin `Cache`
 * wrapper, TTL from the resolved `ProviderOperationPolicy->cacheTtlSeconds`.
 * Only classifications explicitly marked cacheable
 * (`ProviderBillingClassification::$isCacheable`) ever populate a cache
 * key — for `('balance', *)` this is always a structural no-op (Balance
 * is documented real-time/never-cached by Plaid itself; see
 * `App\Integrations\Models\ProviderBalanceSnapshot`'s own docblock for
 * what "cached balance age" actually means instead, which is NOT this
 * cache).
 *
 * `$keyContext` is caller-supplied extra disambiguation (e.g. an
 * account id, a page cursor) — never secret content, since the
 * resulting key is only ever a cache lookup key, not stored data of its
 * own beyond the returned array.
 */
final class ProviderResponseCacheService
{
    /**
     * @param  array<string, scalar>  $keyContext
     * @return array<string, mixed>|null
     */
    public function get(FirmIntegration $connection, ProviderBillingClassification $classification, array $keyContext = []): ?array
    {
        if (! $classification->isCacheable) {
            return null;
        }

        return Cache::get($this->key($connection, $classification, $keyContext));
    }

    /**
     * @param  array<string, scalar>  $keyContext
     * @param  array<string, mixed>  $value
     */
    public function put(FirmIntegration $connection, ProviderBillingClassification $classification, array $keyContext, array $value, int $ttlSeconds): void
    {
        if (! $classification->isCacheable || $ttlSeconds <= 0) {
            return;
        }

        Cache::put($this->key($connection, $classification, $keyContext), $value, $ttlSeconds);
    }

    /**
     * @param  array<string, scalar>  $keyContext
     */
    private function key(FirmIntegration $connection, ProviderBillingClassification $classification, array $keyContext): string
    {
        $suffix = $keyContext === [] ? '' : ':'.md5(json_encode($keyContext, JSON_THROW_ON_ERROR));

        return "provider-response-cache:{$connection->id}:{$classification->capability()}{$suffix}";
    }
}
