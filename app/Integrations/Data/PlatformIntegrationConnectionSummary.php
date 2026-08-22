<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use Illuminate\Support\Carbon;

/**
 * PlatformIntegrationConnectionSummary — Checkpoint 11 (frozen-design-
 * post-security-review.md §10, §12). The read-model DTO for the
 * SuperAdmin cross-firm per-firm connection list/detail UI. Deliberately
 * STRICTER than Checkpoint 10's `FirmIntegrationConnectionListItem`
 * (`app/Integrations/Data/FirmIntegrationConnectionListItem.php`, which
 * this checkpoint must not modify):
 *   - `webhook_routing_token` is never referenced anywhere in this class
 *     (Checkpoint 10's own DTO already omits it; this one additionally
 *     never even checks whether it's null — `webhookRoutingConfigured`
 *     below is derived from `integration_webhook_routing_index`'s own
 *     row existence instead, never from this model's hidden column).
 *   - `externalAccountId` is ALWAYS masked/truncated (last 4 characters)
 *     by the named constructor below — no code path on this DTO can
 *     produce the raw value.
 *   - Health fields come only from the governed
 *     `sanitized_diagnostic_summary`/`last_failure_category` columns
 *     (via App\Integrations\Services\HealthStateService's own DTO) —
 *     never `FirmIntegration.error_reason`, which this checkpoint
 *     deliberately does not surface (last_error on outbox events/sync
 *     items is separately, unconditionally banned — frozen design §10
 *     item 2 — and this DTO does not touch either table at all).
 */
final readonly class PlatformIntegrationConnectionSummary
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $firmId,
        public string $firmUuid,
        public string $displayLabel,
        public ConnectionStatus $status,
        public string $providerDisplayName,
        public ?Carbon $connectedAt,
        public ?Carbon $disconnectedAt,
        public ?HealthSummaryState $healthSummaryState,
        public ?string $sanitizedDiagnosticSummary,
        public ?string $lastFailureCategory,
        public int $consecutiveFailures,
        public ?Carbon $nextRetryAt,
        public ?string $maskedExternalAccountId,
        public bool $webhookRoutingConfigured,
    ) {}

    /**
     * $lastFailureCategory is passed explicitly (never sourced from
     * ConnectionHealthSummary, which does not carry it) — read directly
     * from `integration_connection_health.last_failure_category` by the
     * caller (App\Services\IntegrationPlatformOversightReadService),
     * since App\Integrations\Services\HealthStateService's own DTO
     * shape is frozen/untouched by this checkpoint.
     */
    public static function fromModel(
        FirmIntegration $connection,
        ConnectionHealthSummary $health,
        ?string $lastFailureCategory,
        bool $webhookRoutingConfigured,
    ): self {
        return new self(
            id: $connection->id,
            uuid: $connection->uuid,
            firmId: (int) $connection->firm_id,
            firmUuid: (string) $connection->firm->uuid,
            displayLabel: $connection->display_label ?? $connection->integrationProvider?->display_name ?? 'Untitled connection',
            status: $connection->status,
            providerDisplayName: $connection->integrationProvider?->display_name ?? 'Unknown provider',
            connectedAt: $connection->connected_at,
            disconnectedAt: $connection->disconnected_at,
            healthSummaryState: $health->summaryState,
            sanitizedDiagnosticSummary: $health->sanitizedDiagnosticSummary,
            lastFailureCategory: $lastFailureCategory,
            consecutiveFailures: $health->consecutiveFailures,
            nextRetryAt: $health->nextRetryAt,
            maskedExternalAccountId: self::maskExternalAccountId($connection->external_account_id),
            webhookRoutingConfigured: $webhookRoutingConfigured,
        );
    }

    /**
     * Masks/truncates to the last 4 characters only (frozen design §10
     * item 4) — never assumed opaque, since no real provider exists yet
     * to confirm `external_account_id`'s real-world shape.
     */
    public static function maskExternalAccountId(?string $externalAccountId): ?string
    {
        if ($externalAccountId === null || $externalAccountId === '') {
            return null;
        }

        $length = strlen($externalAccountId);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - 4).substr($externalAccountId, -4);
    }
}
