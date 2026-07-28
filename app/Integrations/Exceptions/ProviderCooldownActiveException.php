<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderCooldownActiveException — thrown by
 * `App\Integrations\Billing\ProviderCooldownService::assertNotCoolingDown()`
 * (pipeline step 10, checkpoint4-design-cost-control.md §2 step 10/§5.1)
 * when a prior successful (finalized_billable) call for the identical
 * (connection, product, billingOperation, account) key is still within
 * its configured cooldown window. Carries the remaining seconds so a
 * caller (e.g. the Live Balance confirmation UI, out of this
 * checkpoint's scope) can display it directly rather than re-deriving
 * it from cache state itself.
 */
final class ProviderCooldownActiveException extends RuntimeException
{
    public function __construct(public readonly int $remainingSeconds)
    {
        parent::__construct("Provider call is cooling down; {$remainingSeconds} second(s) remaining.");
    }
}
