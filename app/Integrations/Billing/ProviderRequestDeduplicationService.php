<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Exceptions\ProviderDuplicateRequestInFlightException;
use App\Integrations\Models\FirmIntegration;
use Illuminate\Support\Facades\Cache;

/**
 * ProviderRequestDeduplicationService — pipeline step 9
 * (checkpoint4-design-cost-control.md §2 step 9/§5.2). A pre-flight
 * `Cache::lock()`, acquired BEFORE the real HTTP call and held through
 * finalize, released in a `finally`.
 *
 * This is the step that closes a real, previously undocumented gap:
 * `IntegrationUsageRecorderService::recordOnce()`'s `usageIdempotencyKey`
 * only deduplicates the EVIDENCE ROW, written AFTER the real HTTP call
 * already happened (`ProviderRequestExecutor::send()` sends the request
 * in step 3, records usage in step 4/8) — two concurrent requests
 * carrying the identical `usageIdempotencyKey` (a double-click, two
 * open tabs both confirming Live Balance) would both independently
 * reach `send()` and both execute a real network call, with only the
 * evidence rows collapsing to one afterward. A pre-flight lock, by
 * contrast, rejects the second concurrent request BEFORE it ever
 * reaches `ProviderRequestExecutor::send()` — zero risk of a second
 * real network call.
 */
final class ProviderRequestDeduplicationService
{
    /**
     * @param  array<string, scalar>  $lockContext
     */
    public function acquire(FirmIntegration $connection, ProviderBillingClassification $classification, array $lockContext = [], int $lockSeconds = 30): ProviderRequestLock
    {
        $lock = Cache::lock($this->key($connection, $classification, $lockContext), $lockSeconds);

        if (! $lock->get()) {
            throw new ProviderDuplicateRequestInFlightException;
        }

        return new ProviderRequestLock($lock);
    }

    /**
     * @param  array<string, scalar>  $lockContext
     */
    private function key(FirmIntegration $connection, ProviderBillingClassification $classification, array $lockContext): string
    {
        $suffix = $lockContext === [] ? '' : ':'.md5(json_encode($lockContext, JSON_THROW_ON_ERROR));

        return "provider-inflight:{$connection->id}:{$classification->capability()}{$suffix}";
    }
}
