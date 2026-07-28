<?php

declare(strict_types=1);

namespace App\Integrations\Billing;

use App\Integrations\Models\ProviderBillableCallReservation;

/**
 * ProviderBillableCallResult — `ProviderBillableCallPipeline::execute()`'s
 * return type on success (checkpoint4-design-cost-control.md §2's
 * `execute()` signature). `$response` carries whatever the caller's own
 * `$providerCall` closure returned (a `ProviderHttpResponse`, or
 * whatever shape `OutboundProviderHttpClient::execute()` was told to
 * return) — this pipeline never unwraps or reshapes it further.
 *
 * `$reservation` is null only for a served-from-cache response (pipeline
 * step 8 short-circuit) — no reservation is ever created for a call that
 * never reached the provider. `$softLimitExceeded` mirrors step 11's own
 * "proceeds, but flags" behavior — a soft-limit breach never blocks the
 * call, it only surfaces here for the caller's own audit/UI purposes.
 *
 * A failed call (any `SanitizedProviderHttpException`, including an
 * `uncertain`/non-billable outcome) is never represented by this class —
 * `execute()` finalizes and audits the reservation first, then rethrows
 * the original exception unchanged, exactly mirroring
 * `OutboundProviderHttpClient::execute()`'s own "propagates through both
 * layers intact" contract (checkpoint4-design-cost-control.md §2.1).
 */
final class ProviderBillableCallResult
{
    public function __construct(
        public readonly mixed $response,
        public readonly ?ProviderBillableCallReservation $reservation,
        public readonly ProviderNormalizedOutcome $outcome,
        public readonly bool $softLimitExceeded,
    ) {}
}
