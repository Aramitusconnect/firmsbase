<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use Illuminate\Support\Carbon;

/**
 * ProviderLiveBalanceConfirmationContext — `ProviderLiveBalanceConfirmationService::prepare()`'s
 * return value (checkpoint4-design-cost-control.md §5.3). The single
 * source of every value a Live Balance confirmation UI would display
 * (last successful retrieval, cached balance age, included allowance
 * remaining, estimated customer charge/overage, cooldown remaining,
 * whether a reason is required, the confirmation token) — the product
 * owner's own quoted safeguard requirement, verbatim: "last successful
 * balance retrieval; cached balance age; included allowance remaining;
 * estimated customer charge or overage; cooldown remaining; reason
 * field; confirmation step." No value here is ever meant to be
 * independently re-derived by a caller — every field is already fully
 * resolved.
 */
final class ProviderLiveBalanceConfirmationContext
{
    public function __construct(
        public readonly ?Carbon $lastSuccessfulRetrievalAt,
        public readonly ?int $cachedBalanceAgeSeconds,
        public readonly ?int $includedAllowanceRemaining,
        public readonly ?int $estimatedCustomerChargeCents,
        public readonly bool $isOverage,
        public readonly int $cooldownRemainingSeconds,
        public readonly bool $reasonRequired,
        public readonly string $confirmationToken,
        public readonly int $confirmationTokenExpiresInSeconds,
    ) {}
}
