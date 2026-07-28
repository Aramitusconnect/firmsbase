<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use Illuminate\Contracts\Cache\Lock;

/**
 * ProviderRequestLock — a thin, closed wrapper around the
 * `Illuminate\Contracts\Cache\Lock` `ProviderRequestDeduplicationService::acquire()`
 * returns (pipeline step 9, checkpoint4-design-cost-control.md §2 step
 * 9/§5.2). Exists only so `ProviderBillableCallPipeline::execute()`
 * never holds a raw framework `Lock` instance directly — release() is
 * the only operation the pipeline needs, always called from a `finally`
 * block regardless of how steps 10-15 conclude.
 */
final class ProviderRequestLock
{
    public function __construct(private readonly Lock $lock) {}

    public function release(): void
    {
        $this->lock->release();
    }
}
