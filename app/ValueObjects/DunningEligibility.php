<?php

namespace App\ValueObjects;

use App\Enums\ConsentChannel;

/**
 * DunningEligibility — the outcome of
 * PaymentPlanDunningService::checkAndLog(). Carries the client's
 * language/timezone through as plain data so a future Phase 4
 * notification system can render a reminder without re-deriving these
 * values itself; Phase 3 never renders or sends anything.
 */
final readonly class DunningEligibility
{
    public function __construct(
        public bool $eligible,
        public ?string $reason = null,
        public ?ConsentChannel $channel = null,
        public ?string $clientLanguage = null,
        public ?string $clientTimezone = null,
    ) {
    }
}
