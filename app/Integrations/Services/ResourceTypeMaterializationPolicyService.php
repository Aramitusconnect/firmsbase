<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;

/**
 * ResourceTypeMaterializationPolicyService — the canonical answer to
 * "does the pull-sync framework actually keep a local record for this
 * resource type, for this provider." A resource type is unmaterialized
 * when PullSyncJob::applyPage() has no local-record path for it (falls
 * straight through to SyncItemStatus::Skipped, or — for the one
 * provider whose materializer does handle a fixed subset of types —
 * has no `match` arm for it at all, an UnhandledMatchError under
 * FinancialEvidenceMaterializerService::materialize()):
 *
 *  - Message: only Plaid's materializer runs at all, and even Plaid's
 *    own `match` has no Message arm — unmaterialized for every
 *    provider.
 *  - CalendarEvent: no provider's materializer has a CalendarEvent arm
 *    at all, Plaid included — unmaterialized for every provider,
 *    unconditionally, unlike Message which is at least keyed off
 *    Plaid for future-proofing.
 *
 * Deliberately consulted ONLY by TriggerManualSyncAction, which
 * dispatches a real PullSyncJob run whose items would be silently
 * discarded for an unmaterialized type. ConnectProviderAction's own
 * COMM-008 fix is intentionally NOT wired to this service: connect-time
 * capability selection is a separate, narrower, already-tested policy
 * question (which capabilities are worth requesting real OAuth consent
 * for), not "which capabilities pull-sync currently materializes" —
 * ConnectProviderAction still offers CalendarEvent (see
 * FirmIntegrationConnectionLifecycleActionsTest's own
 * ::assertSee('Calendar') assertions for Microsoft365/GoogleWorkspace)
 * even though this service reports it unmaterialized, and keeps its
 * own private, Message-only, Plaid-scoped exclusion unchanged.
 */
class ResourceTypeMaterializationPolicyService
{
    /**
     * $providerKey is nullable so a caller with an unresolved/unknown
     * provider (e.g. a connection whose provider row failed to
     * resolve) still gets a safe, conservative answer: null is treated
     * the same as "not Plaid," matching this codebase's convention of
     * defaulting to the honest/pessimistic answer under doubt rather
     * than assuming support. CalendarEvent is unmaterialized
     * unconditionally either way.
     */
    public function isUnmaterializedByPullSync(string $resourceType, ?ProviderKey $providerKey): bool
    {
        if ($resourceType === ResourceType::CalendarEvent->value) {
            return true;
        }

        return $resourceType === ResourceType::Message->value
            && $providerKey !== ProviderKey::Plaid;
    }

    /**
     * @param  array<int, string>  $resourceTypes
     * @return array<int, string>
     */
    public function filterSyncableCapabilities(array $resourceTypes, ?ProviderKey $providerKey): array
    {
        return array_values(array_filter(
            $resourceTypes,
            fn (string $resourceType): bool => ! $this->isUnmaterializedByPullSync($resourceType, $providerKey),
        ));
    }
}
