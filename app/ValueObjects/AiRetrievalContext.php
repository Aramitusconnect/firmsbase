<?php

namespace App\ValueObjects;

/**
 * AiRetrievalContext — the ONLY object AiRetrievalIsolationService
 * hands to a retrieval call. Carries the resolved, permission-filtered
 * set of matter IDs a query is allowed to touch, computed ONCE by
 * MatterAccessPolicyService, so "cross-matter unauthorized" and
 * "cross-firm unauthorized" are enforced by construction — a caller
 * cannot accidentally widen the set after the fact because the
 * property is readonly and there is no mutator.
 */
final readonly class AiRetrievalContext
{
    /**
     * @param  array<int>  $authorizedMatterIds
     */
    public function __construct(
        public int $firmId,
        public array $authorizedMatterIds,
        public string $namespaceIdentifier,
    ) {
    }

    public function permitsMatter(int $matterId): bool
    {
        return in_array($matterId, $this->authorizedMatterIds, true);
    }

    /**
     * Cross-matter retrieval requires access to EVERY matter involved
     * (project rule 16) — not just one of them.
     *
     * @param  array<int>  $matterIds
     */
    public function permitsAllMatters(array $matterIds): bool
    {
        foreach ($matterIds as $matterId) {
            if (! $this->permitsMatter($matterId)) {
                return false;
            }
        }

        return true;
    }
}
