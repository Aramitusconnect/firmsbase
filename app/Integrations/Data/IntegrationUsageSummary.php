<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\SyncDirection;
use Illuminate\Support\Carbon;

/**
 * IntegrationUsageSummary — Checkpoint 10 (frozen-design-post-security-
 * review.md §6; agent-10h-architecture-security-review.md §5). The
 * read-model DTO returned by
 * `IntegrationUsageSummaryService::summariesForFirm()`, grouping the
 * raw, append-only `integration_usage_records` rows into one row per
 * (connection, provider, capability, operation_type, direction, unit)
 * combination — built ONLY from a `SUM(quantity)`/`MIN(occurred_at)`/
 * `MAX(occurred_at)` aggregate query, never a raw model, never
 * `->toArray()` of an `IntegrationUsageRecord`.
 *
 * `operationType` is a plain string, not `UsageOperationType` — mirrors
 * `integration_usage_records.operation_type`'s own deliberate,
 * documented choice not to cast this column to that enum at the model
 * layer (a closed enum would force a migration every time a future
 * provider/capability introduces a new operation shape). `direction` IS
 * cast to `SyncDirection` on the model, so it is safe to type strictly
 * here.
 */
final readonly class IntegrationUsageSummary
{
    public function __construct(
        public int $firmIntegrationId,
        public string $connectionLabel,
        public string $providerKey,
        public string $capability,
        public string $operationType,
        public ?SyncDirection $direction,
        public int $totalQuantity,
        public string $unit,
        public ?Carbon $firstOccurredAt,
        public ?Carbon $lastOccurredAt,
    ) {}
}
