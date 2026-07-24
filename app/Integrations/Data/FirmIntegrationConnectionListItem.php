<?php

declare(strict_types=1);

namespace App\Integrations\Data;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use Illuminate\Support\Carbon;

/**
 * FirmIntegrationConnectionListItem — Checkpoint 10 (frozen-design-
 * post-security-review.md §12). A read-model DTO for the firm-facing
 * connections list/summary UI, built ONLY from columns Agent 10D's
 * column-by-column inventory classifies SAFE on `FirmIntegration`
 * (`app/Integrations/Models/FirmIntegration.php`) — every field below
 * is display-safe as-is. `webhook_routing_token` (the one HIDDEN-ONLY
 * column on this model) is deliberately never referenced here.
 *
 * Not structurally REQUIRED the way `FirmIntegrationCredentialSummary`
 * is (direct `$record->column` access to a SAFE `FirmIntegration`
 * column is acceptable without a DTO, per frozen design §9) — this DTO
 * exists as defense-in-depth hygiene, extending this codebase's own
 * established `SanitizedPayloadReference`/`ConnectionHealthSummary`
 * read-model-DTO pattern one layer further into the UI, so no
 * `getStateUsing()`/`formatStateUsing()` closure on the list page ever
 * needs to touch a raw `FirmIntegration` attribute directly.
 */
final readonly class FirmIntegrationConnectionListItem
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $displayLabel,
        public ConnectionStatus $status,
        public string $providerDisplayName,
        public ?Carbon $connectedAt,
        public ?Carbon $disconnectedAt,
        public ?HealthSummaryState $lastHealthStatus,
        public ?Carbon $lastHealthCheckAt,
        public ?string $errorReason,
    ) {
    }

    public static function fromModel(FirmIntegration $connection): self
    {
        return new self(
            id: $connection->id,
            uuid: $connection->uuid,
            displayLabel: $connection->display_label ?? $connection->integrationProvider?->display_name ?? 'Untitled connection',
            status: $connection->status,
            providerDisplayName: $connection->integrationProvider?->display_name ?? 'Unknown provider',
            connectedAt: $connection->connected_at,
            disconnectedAt: $connection->disconnected_at,
            lastHealthStatus: $connection->last_health_status,
            lastHealthCheckAt: $connection->last_health_check_at,
            errorReason: $connection->error_reason,
        );
    }
}
