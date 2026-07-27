<?php

declare(strict_types=1);

namespace App\Integrations\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * ProviderOutboundRequestCompleted — dispatched by
 * `App\Integrations\Support\ProviderRequestExecutor::send()` at the very
 * end of every call, success or failure alike
 * (checkpoint1-design-http-ratelimit-usage.md §2.8). A pure
 * extensibility seam — no listener ships in Checkpoint 1 — mirroring how
 * `App\Services\TimelineEventRecorder`/`App\Services\PlatformAdminAuditEventRecorder`
 * already exist as write paths with no assumption about who reads them
 * later.
 *
 * Carries ONLY the same non-secret fields already written to
 * `integration_usage_records.metadata_json` — never request/response
 * body content, never a header, never a credential. Never dispatched for
 * the proactive rate-limit rejection's own usage-recording exemption
 * (that rejection still fires this event, since it IS a completed
 * "attempt" from the executor's own point of view — it is only the
 * separate `IntegrationUsageRecorderService::recordOnce()` write that is
 * skipped for that specific case).
 */
final class ProviderOutboundRequestCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $providerKey,
        public readonly string $operationType,
        public readonly string $outcome,
        public readonly ?string $category,
        public readonly ?int $statusCode,
        public readonly int $durationMs,
        public readonly string $correlationId,
        public readonly int $firmIntegrationId,
    ) {}
}
