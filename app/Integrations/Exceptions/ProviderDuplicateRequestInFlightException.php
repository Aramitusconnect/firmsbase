<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderDuplicateRequestInFlightException — thrown by
 * `App\Integrations\Billing\ProviderRequestDeduplicationService::acquire()`
 * (pipeline step 9, checkpoint4-design-cost-control.md §2 step 9/§5.2)
 * when a `Cache::lock()` for the identical (connection, product,
 * billingOperation, account) key is already held by another in-flight
 * request. This is the PRE-FLIGHT dedup mechanism that closes the real
 * concurrent-duplicate-real-HTTP-call gap
 * `IntegrationUsageRecorderService::recordOnce()`'s `usageIdempotencyKey`
 * alone cannot close (that mechanism only deduplicates the evidence row
 * AFTER the real HTTP call already happened) — see
 * `ProviderRequestDeduplicationService`'s own docblock for the full
 * reasoning.
 */
final class ProviderDuplicateRequestInFlightException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A duplicate provider request is already in flight for this exact operation.');
    }
}
