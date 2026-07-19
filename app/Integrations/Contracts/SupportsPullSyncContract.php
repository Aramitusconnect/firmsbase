<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * SupportsPullSyncContract — implemented by providers that support
 * FirmsBase pulling (reading) resources from them. Deliberately
 * separate from SupportsIncrementalSyncContract so a full-list-only
 * provider is never forced to fake a delta/cursor token
 * (provider-contracts.md).
 */
interface SupportsPullSyncContract
{
    /**
     * @return string[] resource type identifiers this provider can
     *                   pull (see App\Integrations\Enums\ResourceType
     *                   for the current documented vocabulary).
     */
    public function pullableResourceTypes(): array;

    /**
     * Pull one page of the given resource type.
     *
     * @param array<string, mixed> $context caller-supplied connection/
     *                                       auth context.
     * @param string $resourceType one of pullableResourceTypes().
     * @param string|null $cursor opaque pagination cursor from a
     *                             previous call, or null for the first
     *                             page.
     * @return array<string, mixed> shape must include the pulled
     *                               items and the next cursor (or null
     *                               if exhausted).
     */
    public function pull(array $context, string $resourceType, ?string $cursor): array;
}
