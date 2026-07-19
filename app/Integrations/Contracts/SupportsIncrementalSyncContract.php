<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsIncrementalSyncContract — deliberately separate from
 * SupportsPullSyncContract (provider-contracts.md, §9 of
 * checkpoint-00-final-specification.md) so a provider that only offers
 * a full-list pull is never forced to fake a delta/cursor token it
 * doesn't actually have. A provider may implement
 * SupportsPullSyncContract without this interface at all.
 */
interface SupportsIncrementalSyncContract
{
    /**
     * Whether this provider can do a true incremental (delta) sync for
     * the given resource type, as opposed to only a full-list pull.
     */
    public function supportsIncrementalFor(string $resourceType): bool;

    /**
     * The current incremental cursor/delta token for the given
     * resource type, or null if none is available yet (e.g. no prior
     * sync has completed).
     *
     * @param array<string, mixed> $context
     */
    public function incrementalCursorFor(array $context, string $resourceType): ?string;
}
