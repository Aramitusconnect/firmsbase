<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderHardLimitExceededException — thrown by
 * `App\Integrations\Billing\ProviderUsageLimitEnforcementService::assertWithinLimits()`
 * (pipeline step 11, checkpoint4-design-cost-control.md §2 step 11) when
 * finalized usage plus currently-`reserved` reservations for the same
 * scope/period would exceed the resolved hard limit. No reservation is
 * created when this throws — the reservation-inclusive sum is computed
 * BEFORE step 12 reserves anything, precisely to close the TOCTOU gap a
 * plain "usage so far" check would leave open under concurrent load.
 */
final class ProviderHardLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $attemptedTotal,
    ) {
        parent::__construct(
            "Provider hard usage limit exceeded: limit={$limit}, attempted_total={$attemptedTotal}."
        );
    }
}
