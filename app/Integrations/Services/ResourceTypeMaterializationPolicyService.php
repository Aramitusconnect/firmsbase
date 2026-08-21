<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;

/**
 * ResourceTypeMaterializationPolicyService — the single canonical
 * answer to "does the pull-sync framework actually keep a local record
 * for this resource type, for this provider," used by every UI surface
 * that lets a user select a resource type to sync. A resource type is a
 * dead end when PullSyncJob::applyPage() has no local-record path for
 * it (falls straight through to SyncItemStatus::Skipped, or — for the
 * one provider whose materializer does handle a fixed subset of types —
 * has no `match` arm for it at all, an UnhandledMatchError under
 * FinancialEvidenceMaterializerService::materialize()).
 *
 * Extracted from ConnectProviderAction's original COMM-008 fix
 * (isDeadEndCapability(), scoped to that one action) so
 * TriggerManualSyncAction can enforce the identical rule rather than
 * requesting real OAuth consent, or queuing a real sync run, for a
 * capability whose synced items are unconditionally discarded.
 *
 *  - Message: only Plaid's materializer runs at all, and even Plaid's
 *    own `match` has no Message arm (see
 *    FinancialEvidenceMaterializerService::materialize()) — dead end
 *    for every provider.
 *  - CalendarEvent: no provider's materializer has a CalendarEvent arm
 *    at all, Plaid included — dead end for every provider,
 *    unconditionally, unlike Message which is at least keyed off
 *    Plaid for future-proofing.
 */
class ResourceTypeMaterializationPolicyService
{
    /**
     * $providerKey is nullable so a caller with an unresolved/unknown
     * provider (e.g. a connection whose provider row failed to
     * resolve) still gets a safe, conservative answer: null is treated
     * the same as "not Plaid," matching this codebase's convention of
     * defaulting to the honest/pessimistic answer under doubt rather
     * than assuming support. CalendarEvent is dead-end unconditionally
     * either way.
     */
    public function isDeadEndCapability(string $resourceType, ?ProviderKey $providerKey): bool
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
    public function filterSelectableCapabilities(array $resourceTypes, ?ProviderKey $providerKey): array
    {
        return array_values(array_filter(
            $resourceTypes,
            fn (string $resourceType): bool => ! $this->isDeadEndCapability($resourceType, $providerKey),
        ));
    }
}
