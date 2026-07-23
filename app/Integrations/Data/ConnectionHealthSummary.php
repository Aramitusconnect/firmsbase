<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\HealthSummaryState;
use Illuminate\Support\Carbon;

/**
 * ConnectionHealthSummary — the read-side DTO returned by
 * App\Integrations\Services\HealthStateService::summaryFor()/
 * summariesForFirm() (Checkpoint 8, agent-8f-health-state-design.md §5;
 * frozen verbatim into the HealthStateService interface by
 * agent-8h-architecture-security-review.md §6). A plain, small,
 * `final readonly` value object — matching the existing
 * OAuthCallbackResult/ConsumedOAuthState/ResolvedWebhookConnection
 * convention in this namespace — never a raw Eloquent model exposed
 * past the service boundary.
 */
final readonly class ConnectionHealthSummary
{
    public function __construct(
        public HealthSummaryState $summaryState,
        public ?Carbon $lastSuccessAt,
        public ?Carbon $lastFailureAt,
        public int $consecutiveFailures,
        public ?Carbon $nextRetryAt,
        public ?string $sanitizedDiagnosticSummary,
    ) {
    }
}
